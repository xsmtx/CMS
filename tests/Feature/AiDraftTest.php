<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Ai\AiProviders;
use App\Application\Ai\Draft;
use App\Application\Ai\TicketPrompts;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Ai\AiFeature;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Organizations\OrganizationType;
use App\Domain\Support\TicketStatus;
use App\Infrastructure\Ai\Models\AiSetting;
use App\Infrastructure\Ai\Models\AiUsage;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Support\Models\Ticket;
use App\Infrastructure\Support\Models\TicketReply;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Tests\Support\FakeAiProvider;

/**
 * The assistant (ADR 0050).
 *
 * Most of this file is about **what does not happen**: nothing leaves until
 * somebody turns it on, an internal note never leaves at all, no completion is
 * stored, and there is no path from a draft to a customer.
 *
 * That emphasis is the design. A test that proved a model can write a reply
 * would be testing the vendor; what is worth pinning here is every promise
 * this platform made about a third party receiving a customer's words.
 */
beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->provider = Organization::query()
        ->where('type', OrganizationType::Provider->value)
        ->firstOrFail();

    app(OrganizationContext::class)->set($this->provider->id);

    $this->staff = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $this->ai = new FakeAiProvider;

    $registry = new AiProviders;
    $registry->register($this->ai);
    app()->instance(AiProviders::class, $registry);

    $this->draft = app(Draft::class);
});

function aiTicket(): Ticket
{
    $customer = Customer::factory()->create(['company_name' => 'Acme Ltd']);

    return Ticket::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'subject' => 'Site is returning 502',
        'status' => TicketStatus::CustomerReply,
    ]);
}

/**
 * The installation's owner, which is what the `owner` middleware asks for.
 */
function owningStaff(): StaffUser
{
    $owner = StaffUser::factory()->create(['organization_id' => test()->provider->id]);
    $owner->assignRole(SystemRole::SuperAdmin);

    return $owner->fresh();
}

function aiEnabled(array $features = [AiFeature::TicketReply]): AiSetting
{
    return AiSetting::factory()
        ->using('fake', $features)
        ->create(['organization_id' => test()->provider->id]);
}

it('writes nothing and sends nothing until somebody turns it on', function (): void {
    $prompt = new AiPrompt(AiFeature::TicketReply, 'Draft a reply.');

    expect(fn () => $this->draft->write($prompt, $this->staff))
        ->toThrow(function (AiUnavailable $e): void {
            expect($e->key())->toBe('ai.errors.not_enabled');
        });

    // The provider was never asked, which is the part that matters: a
    // settings check that happened after the call would have sent the words
    // and then refused.
    expect($this->ai->calls)->toBe(0);
});

it('refuses a feature the seller did not turn on, even with the assistant enabled', function (): void {
    aiEnabled([AiFeature::TicketSummary]);

    // Summarising for a colleague and drafting what a customer reads are
    // different decisions, and this is what makes them different.
    expect(fn () => $this->draft->write(new AiPrompt(AiFeature::TicketReply, 'Draft.'), $this->staff))
        ->toThrow(AiUnavailable::class);

    expect($this->ai->calls)->toBe(0);
});

it('refuses when no module is enabled at all', function (): void {
    app()->instance(AiProviders::class, new AiProviders);
    aiEnabled();

    expect(fn () => app(Draft::class)->write(new AiPrompt(AiFeature::TicketReply, 'Draft.'), $this->staff))
        ->toThrow(function (AiUnavailable $e): void {
            expect($e->key())->toBe('ai.errors.no_provider');
        });
});

it('answers once it is on, and records what it cost', function (): void {
    aiEnabled();

    $completion = $this->draft->write(new AiPrompt(AiFeature::TicketReply, 'Draft.'), $this->staff);

    expect($completion->text)->not->toBe('')
        ->and($completion->model)->toBe('fake-1');

    $usage = AiUsage::query()->firstOrFail();

    expect($usage->feature)->toBe(AiFeature::TicketReply)
        ->and($usage->provider_key)->toBe('fake')
        ->and($usage->tokens())->toBe(200)
        ->and($usage->outcome)->toBe(AiUsage::OutcomeAnswered)
        ->and($usage->staff_user_id)->toBe($this->staff->id);
});

/**
 * The absence that the whole table was designed around.
 */
it('stores what a call cost and never what it said', function (): void {
    aiEnabled();

    $completion = $this->draft->write(new AiPrompt(AiFeature::TicketReply, 'Draft.'), $this->staff);

    $row = AiUsage::query()->firstOrFail()->getAttributes();

    foreach ($row as $value) {
        expect((string) $value)->not->toContain($completion->text);
    }

    // And the audit row carries the feature and the provider, never the words:
    // an audit log is the one table nothing deletes from.
    $audit = AuditLog::query()->where('action', 'ai.draft.requested')->firstOrFail();

    expect(json_encode($audit->metadata))->not->toContain($completion->text);
});

it('records a refusal too, because that is what somebody is looking for', function (): void {
    aiEnabled();

    $failing = new FakeAiProvider(refusal: AiUnavailable::unreachable('fake'));
    $registry = new AiProviders;
    $registry->register($failing);
    app()->instance(AiProviders::class, $registry);

    expect(fn () => app(Draft::class)->write(new AiPrompt(AiFeature::TicketReply, 'Draft.'), $this->staff))
        ->toThrow(AiUnavailable::class);

    expect(AiUsage::query()->firstOrFail()->outcome)->toBe(AiUsage::OutcomeRefused);
});

it('treats an empty answer as no draft rather than as a blank one', function (): void {
    aiEnabled();

    $empty = new FakeAiProvider(answer: '   ');
    $registry = new AiProviders;
    $registry->register($empty);
    app()->instance(AiProviders::class, $registry);

    // Putting that in the reply box would read as the button having worked.
    expect(fn () => app(Draft::class)->write(new AiPrompt(AiFeature::TicketReply, 'Draft.'), $this->staff))
        ->toThrow(function (AiUnavailable $e): void {
            expect($e->key())->toBe('ai.errors.empty_answer');
        });
});

it('attaches the seller’s own instructions to the prompt', function (): void {
    aiEnabled()->forceFill(['instructions' => 'Answer in Turkish and never promise a refund.'])->save();

    $this->draft->write(new AiPrompt(AiFeature::TicketReply, 'Draft.'), $this->staff);

    expect($this->ai->lastPrompt?->instructions)->toBe('Answer in Turkish and never promise a refund.');
});

/**
 * The one that would matter most if it were wrong.
 */
it('never sends an internal note to the vendor', function (): void {
    aiEnabled();
    $ticket = aiTicket();

    TicketReply::factory()->create([
        'organization_id' => $ticket->organization_id,
        'ticket_id' => $ticket->id,
        'author_type' => TicketReply::AUTHOR_STAFF,
        'body' => 'This customer is difficult, charge them for the call.',
        'is_internal' => true,
    ]);

    TicketReply::factory()->create([
        'organization_id' => $ticket->organization_id,
        'ticket_id' => $ticket->id,
        'author_type' => TicketReply::AUTHOR_CUSTOMER,
        'body' => 'The site is still down.',
        'is_internal' => false,
    ]);

    $this->draft->write(app(TicketPrompts::class)->reply($ticket), $this->staff);

    $sent = $this->ai->lastPrompt?->render() ?? '';

    expect($sent)->toContain('The site is still down.')
        // A colleague wrote that for the people in this installation, and a
        // draft quoting it back to the customer is the version that ends up
        // in a complaint.
        ->and($sent)->not->toContain('charge them for the call');
});

it('sends the customer’s name and nothing else about them', function (): void {
    aiEnabled();

    $customer = Customer::factory()->create([
        'company_name' => 'Acme Ltd',
        'legal_name' => 'Acme Bilişim Anonim Şirketi',
        'tax_id' => 'TR1234567890',
    ]);

    $contact = Contact::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'email' => 'muhasebe@acme.test',
    ]);

    $ticket = Ticket::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'subject' => 'Site is returning 502',
    ]);

    $this->draft->write(app(TicketPrompts::class)->reply($ticket), $this->staff);

    $sent = $this->ai->lastPrompt?->render() ?? '';

    // A draft that opens with their name is the point; one that knows their
    // postcode is not.
    expect($sent)->toContain('Acme Ltd')
        ->and($sent)->not->toContain('TR1234567890')
        ->and($sent)->not->toContain('muhasebe@acme.test')
        ->and($sent)->not->toContain($contact->id);
});

it('has no feature that answers a customer by itself', function (): void {
    // ADR 0050 in one assertion: every member is a draft a person then edits,
    // and a member that sent something would have to be added here first.
    foreach (AiFeature::cases() as $feature) {
        expect($feature->value)->not->toContain('send')
            ->and($feature->value)->not->toContain('auto');
    }
});

/**
 * The screen, and the button on it.
 *
 * Rendering proves the props; only a request proves the path the form posts
 * to is the path the router serves. `AdminActionRoutesTest` states that rule
 * generally and this is the one endpoint where the answer is text rather than
 * a redirect to a row.
 */
it('offers the draft buttons only for the features the seller turned on', function (): void {
    $admin = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $admin->assignRole(SystemRole::Administrator);

    $ticket = aiTicket();

    $this->actingAs($admin->fresh(), 'staff')
        ->get('/admin/support/'.$ticket->id)
        ->assertOk()
        // Nothing turned on: a control that cannot do anything is a control
        // that lies, so the screen is told not to draw it.
        ->assertInertia(fn ($page) => $page
            ->where('ai.reply', false)
            ->where('ai.summary', false));

    aiEnabled([AiFeature::TicketSummary]);

    $this->actingAs($admin->fresh(), 'staff')
        ->get('/admin/support/'.$ticket->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ai.reply', false)
            ->where('ai.summary', true));
});

it('hands a draft back to the screen and stores nothing', function (): void {
    $admin = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $admin->assignRole(SystemRole::Administrator);

    aiEnabled();
    $ticket = aiTicket();

    $this->actingAs($admin->fresh(), 'staff')
        ->post('/admin/support/'.$ticket->id.'/draft', ['kind' => 'reply'])
        ->assertRedirect()
        ->assertSessionHas('draft');

    // The ticket gained no reply. A draft is not a reply until somebody
    // sends it, and there is no path from that endpoint to the customer.
    expect($ticket->refresh()->replies()->count())->toBe(0);
});

it('answers a refusal on the form rather than with a server error', function (): void {
    $admin = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $admin->assignRole(SystemRole::Administrator);

    $ticket = aiTicket();

    // The assistant is off, which is every installation until somebody says
    // otherwise. The operator writes the reply themselves.
    $this->actingAs($admin->fresh(), 'staff')
        ->post('/admin/support/'.$ticket->id.'/draft', ['kind' => 'reply'])
        ->assertRedirect()
        ->assertSessionHasErrors('body');
});

it('refuses a draft to somebody who may not answer the ticket', function (): void {
    aiEnabled();
    $ticket = aiTicket();

    $nobody = StaffUser::factory()->create(['organization_id' => $this->provider->id]);

    $this->actingAs($nobody->fresh(), 'staff')
        ->post('/admin/support/'.$ticket->id.'/draft', ['kind' => 'reply'])
        ->assertForbidden();

    expect(AiUsage::query()->count())->toBe(0);
});

/**
 * The settings screen, which is where somebody agrees to this on their
 * customers' behalf (ADR 0050).
 */
it('keeps the settings screen to the installation’s owner', function (): void {
    $administrator = StaffUser::factory()->create(['organization_id' => $this->provider->id]);
    $administrator->assignRole(SystemRole::Administrator);

    // An Administrator holds every staff permission by design, which is
    // exactly why no permission could stand in for this.
    $this->actingAs($administrator->fresh(), 'staff')
        ->get('/admin/apps/ai')
        ->assertForbidden();
});

it('turns features on one at a time and audits which', function (): void {
    $owner = owningStaff();

    $this->actingAs($owner, 'staff')
        ->put('/admin/apps/ai', [
            'provider' => 'fake',
            'features' => [AiFeature::TicketSummary->value],
            'instructions' => 'Answer in Turkish.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $setting = AiSetting::query()->firstOrFail();

    expect($setting->provider_key)->toBe('fake')
        ->and($setting->enabled_features)->toBe([AiFeature::TicketSummary->value])
        ->and($setting->allows(AiFeature::TicketSummary))->toBeTrue()
        // The one that reaches a customer was not agreed to.
        ->and($setting->allows(AiFeature::TicketReply))->toBeFalse();

    $audit = AuditLog::query()->where('action', 'ai.settings.updated')->firstOrFail();

    // "The assistant was switched on" would not say which agreement was made.
    expect($audit->metadata['features'])->toBe(AiFeature::TicketSummary->value);
});

it('refuses a provider no module answers for, rather than storing it', function (): void {
    $owner = owningStaff();

    $this->actingAs($owner, 'staff')
        ->put('/admin/apps/ai', ['provider' => 'a-vendor-nobody-enabled', 'features' => []])
        ->assertRedirect();

    // A stored key naming nothing would refuse at the reply box, which is the
    // wrong place to find out.
    expect(AiSetting::query()->firstOrFail()->provider_key)->toBeNull();
});

it('turns every feature off when the provider is cleared', function (): void {
    $owner = owningStaff();
    aiEnabled([AiFeature::TicketReply, AiFeature::TicketSummary]);

    $this->actingAs($owner, 'staff')
        ->put('/admin/apps/ai', ['provider' => '', 'features' => [AiFeature::TicketReply->value]])
        ->assertRedirect();

    // Choosing no provider is choosing no assistant, and leaving the features
    // ticked underneath would be a screen that disagreed with itself.
    $setting = AiSetting::query()->firstOrFail();

    expect($setting->provider_key)->toBeNull()
        ->and($setting->enabled_features)->toBe([])
        ->and($setting->allows(AiFeature::TicketReply))->toBeFalse();
});
