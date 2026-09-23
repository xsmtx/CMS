<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Application\Organizations\CreateReseller;
use App\Application\Organizations\ResellerAttributes;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\InvoiceStatus;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Support\Organizations\OrganizationContext;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Illuminate\Support\Str;

/**
 * One action, twenty invoices.
 *
 * A bulk action is the most dangerous control on a list screen: it reaches
 * records the operator has not opened, from ids the browser sent. So the
 * tests here are about the guards rather than the happy path —
 *
 * - the boundary and the policy are asked about **every** row, not the list;
 * - a row the action cannot apply to is **skipped**, and the other nineteen
 *   still happen;
 * - one row failing does not stop the rest;
 * - cancelling without a reason is refused, because the audit record is the
 *   only thing that can answer "what happened to this invoice" later.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();
});

/**
 * A draft that can actually be issued: one with a line on it.
 *
 * The number column is not nullable, so a draft already carries a working
 * number — issuing replaces it with one from the seller's sequence, which is
 * what the tests below assert rather than "it has a number now".
 */
function issuableDraft(?Customer $customer = null): Invoice
{
    $invoice = Invoice::factory()->create([
        'customer_id' => ($customer ?? Customer::factory()->create())->id,
        'status' => InvoiceStatus::Draft->value,
    ]);

    InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

    return $invoice->fresh();
}

it('issues every draft it was given', function (): void {
    $drafts = collect(range(1, 3))->map(fn (): Invoice => issuableDraft());

    $this->actingAs($this->admin, 'staff')
        ->from('/admin/invoices')
        ->post('/admin/invoices/bulk', [
            'action' => 'issue',
            'ids' => $drafts->pluck('id')->all(),
        ])
        ->assertRedirect('/admin/invoices')
        ->assertSessionHas('status');

    $drafts->each(function (Invoice $invoice): void {
        $fresh = $invoice->fresh();

        expect($fresh->status)->toBe(InvoiceStatus::Unpaid);
        // Issuing is the moment the number comes from the seller's sequence
        // rather than from whatever the draft was carrying.
        expect($fresh->number)->not->toBe($invoice->number);
    });
});

/**
 * The rule that makes a bulk action usable. An operator who selected the
 * whole page and caught one already-issued invoice among the drafts meant
 * the drafts.
 */
it('skips a row the action cannot apply to and does the rest', function (): void {
    $draft = issuableDraft();
    $already = Invoice::factory()->create(['status' => InvoiceStatus::Paid->value]);

    $this->actingAs($this->admin, 'staff')
        ->from('/admin/invoices')
        ->post('/admin/invoices/bulk', [
            'action' => 'issue',
            'ids' => [$draft->id, $already->id],
        ])
        ->assertRedirect('/admin/invoices');

    expect($draft->fresh()->status)->toBe(InvoiceStatus::Unpaid);
    // Untouched, not refused, and not cancelled by accident.
    expect($already->fresh()->status)->toBe(InvoiceStatus::Paid);
});

/**
 * `IssueInvoice` refuses a draft with no lines. The invoice beside it still
 * has to be issued: a bulk action that stopped on the eleventh of twenty
 * leaves an operator with no idea which ten went through.
 */
it('keeps going when one row fails, and says which one', function (): void {
    $good = issuableDraft();
    $empty = Invoice::factory()->create([
        'status' => InvoiceStatus::Draft->value,
        'number' => 'INV-BROKEN',
    ]);

    $this->actingAs($this->admin, 'staff')
        ->from('/admin/invoices')
        ->post('/admin/invoices/bulk', [
            'action' => 'issue',
            'ids' => [$empty->id, $good->id],
        ])
        ->assertRedirect('/admin/invoices')
        // The failure is reported as an error rather than swallowed into a
        // success message that says "2 updated".
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'INV-BROKEN'));

    expect($good->fresh()->status)->toBe(InvoiceStatus::Unpaid);
    expect($empty->fresh()->status)->toBe(InvoiceStatus::Draft);
});

it('refuses to cancel without a reason', function (): void {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Unpaid->value]);

    $this->actingAs($this->admin, 'staff')
        ->from('/admin/invoices')
        ->post('/admin/invoices/bulk', ['action' => 'cancel', 'ids' => [$invoice->id]])
        ->assertSessionHasErrors('reason');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

it('refuses a reason that is only whitespace', function (): void {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Unpaid->value]);

    $this->actingAs($this->admin, 'staff')
        ->from('/admin/invoices')
        ->post('/admin/invoices/bulk', [
            'action' => 'cancel',
            'ids' => [$invoice->id],
            'reason' => '   ',
        ])
        ->assertSessionHasErrors('reason');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid);
});

it('writes the reason to the audit record of every invoice it cancelled', function (): void {
    $invoices = collect(range(1, 2))->map(
        fn (): Invoice => Invoice::factory()->create(['status' => InvoiceStatus::Unpaid->value]),
    );

    $this->actingAs($this->admin, 'staff')
        ->from('/admin/invoices')
        ->post('/admin/invoices/bulk', [
            'action' => 'cancel',
            'ids' => $invoices->pluck('id')->all(),
            'reason' => 'Duplicated by the migration',
        ])
        ->assertSessionHas('status');

    $invoices->each(function (Invoice $invoice): void {
        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled);

        $record = AuditLog::query()
            ->where('target_id', $invoice->id)
            ->where('action', 'billing.invoice.status_changed')
            ->first();

        expect($record)->not->toBeNull();
        expect($record->reason)->toBe('Duplicated by the migration');
    });
});

/**
 * The two questions, in order. The ids came from a browser, so a check on the
 * list would be a check on nothing.
 *
 * Two resellers rather than the provider and one reseller: the provider's
 * boundary is the whole subtree, so a provider administrator legitimately
 * reaches every invoice on the installation and could never fail this.
 */
it('leaves another reseller\'s invoice alone, without saying it exists', function (): void {
    $context = app(OrganizationContext::class);

    $mineOrg = app(CreateReseller::class)->handle(new ResellerAttributes(
        name: 'Anatolia Hosting',
        ownerName: 'Anatolia Owner',
        ownerEmail: 'owner@anatolia.test',
    ));

    $theirsOrg = app(CreateReseller::class)->handle(new ResellerAttributes(
        name: 'Aegean Hosting',
        ownerName: 'Aegean Owner',
        ownerEmail: 'owner@aegean.test',
    ));

    $operator = $mineOrg['owner'];
    $operator->assignRole(SystemRole::Administrator);

    $mine = $context->runAs(
        $mineOrg['organization']->id,
        fn (): Invoice => issuableDraft(Customer::factory()->create([
            'organization_id' => $mineOrg['organization']->id,
        ])),
    );

    $theirs = $context->runAs(
        $theirsOrg['organization']->id,
        fn (): Invoice => issuableDraft(Customer::factory()->create([
            'organization_id' => $theirsOrg['organization']->id,
        ])),
    );

    $this->actingAs($operator->fresh(), 'staff')
        ->from('/admin/invoices')
        ->post('/admin/invoices/bulk', [
            'action' => 'issue',
            'ids' => [$mine->id, $theirs->id],
        ])
        // Not a 403: telling an operator which of the twenty ids they posted
        // belongs to somebody else is telling them that it exists.
        ->assertRedirect('/admin/invoices');

    expect($context->withoutBoundary(fn (): InvoiceStatus => Invoice::query()
        ->where('id', $mine->id)
        ->sole()
        ->status))->toBe(InvoiceStatus::Unpaid);

    expect($context->withoutBoundary(fn (): InvoiceStatus => Invoice::query()
        ->where('id', $theirs->id)
        ->sole()
        ->status))->toBe(InvoiceStatus::Draft);
});

it('refuses an action nobody declared', function (): void {
    $invoice = issuableDraft();

    $this->actingAs($this->admin, 'staff')
        ->from('/admin/invoices')
        ->post('/admin/invoices/bulk', ['action' => 'delete', 'ids' => [$invoice->id]])
        ->assertSessionHasErrors('action');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});

it('refuses an empty selection rather than reporting a successful nothing', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->from('/admin/invoices')
        ->post('/admin/invoices/bulk', ['action' => 'issue', 'ids' => []])
        ->assertSessionHasErrors('ids');
});

/**
 * A bulk endpoint with no ceiling is a request that can be made to load ten
 * thousand models into memory by anybody who can sign in.
 */
it('refuses more rows than a page could ever hold', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->from('/admin/invoices')
        ->post('/admin/invoices/bulk', [
            'action' => 'issue',
            'ids' => array_map(
                fn (): string => (string) Str::ulid(),
                range(1, 201),
            ),
        ])
        ->assertSessionHasErrors('ids');
});

it('needs the permission the list screen needs', function (): void {
    $support = StaffUser::factory()->create();
    $support->assignRole(SystemRole::Support);

    $invoice = issuableDraft();

    $this->actingAs($support->fresh(), 'staff')
        ->from('/admin/invoices')
        ->post('/admin/invoices/bulk', ['action' => 'issue', 'ids' => [$invoice->id]])
        ->assertForbidden();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});

it('offers the list screen the actions it may draw', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->get('/admin/invoices')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Invoices/Index')
            ->has('bulkActions', 2)
            ->where('bulkActions.0.value', 'issue')
            ->where('bulkActions.0.needsReason', false)
            ->where('bulkActions.1.value', 'cancel')
            // The screen asks for a reason because the action says it needs
            // one, rather than because the page was written knowing it.
            ->where('bulkActions.1.needsReason', true));
});
