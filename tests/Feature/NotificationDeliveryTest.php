<?php

declare(strict_types=1);

use App\Application\Notifications\Notifier;
use App\Application\Notifications\RenderTemplate;
use App\Application\Notifications\ResolveRecipients;
use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Notifications\Contracts\DeliveryOutcome;
use App\Domain\Notifications\DeliveryStatus;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationRecipient;
use App\Domain\Notifications\RenderedMessage;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Notifications\ChannelRegistry;
use App\Infrastructure\Notifications\Channels\DatabaseChannel;
use App\Infrastructure\Notifications\Channels\MailChannel;
use App\Infrastructure\Notifications\Mail\TemplatedMessage;
use App\Infrastructure\Notifications\Models\InAppNotification;
use App\Infrastructure\Notifications\Models\NotificationDelivery;
use App\Infrastructure\Notifications\Models\NotificationTemplate;
use Database\Seeders\ProviderOrganizationSeeder;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $registry = new ChannelRegistry;
    $registry->register(new MailChannel);
    $registry->register(new DatabaseChannel);
    $this->app->instance(ChannelRegistry::class, $registry);

    $this->customer = Customer::factory()->create();

    $this->contact = Contact::factory()->forCustomer($this->customer)->primary()->create([
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.test',
        'notify_invoices' => true,
        'notify_marketing' => false,
    ]);
});

function deliveries(): int
{
    return NotificationDelivery::query()->withoutGlobalScope('organization')->count();
}

it('sends on every configured channel and records each one', function (): void {
    Mail::fake();

    app(Notifier::class)->send(
        NotificationEvent::InvoiceIssued,
        [app(ResolveRecipients::class)->forContact($this->contact, NotificationEvent::InvoiceIssued)],
        ['invoice_number' => 'INV-000004', 'total' => '€14.99', 'due_date' => '2026-10-11'],
        channels: [NotificationChannel::Mail, NotificationChannel::Database],
    );

    Mail::assertSent(TemplatedMessage::class);

    expect(deliveries())->toBe(2)
        ->and(InAppNotification::query()->count())->toBe(1);
});

it('substitutes what the template asks for', function (): void {
    Mail::fake();

    app(Notifier::class)->send(
        NotificationEvent::InvoiceIssued,
        [app(ResolveRecipients::class)->forContact($this->contact, NotificationEvent::InvoiceIssued)],
        ['invoice_number' => 'INV-000004', 'total' => '€14.99', 'due_date' => '2026-10-11'],
        channels: [NotificationChannel::Database],
    );

    $inApp = InAppNotification::query()->sole();

    expect($inApp->title)->toContain('INV-000004')
        ->and($inApp->body)->toContain('€14.99')
        ->and($inApp->body)->toContain('2026-10-11');
});

it('leaves a placeholder with no value as itself rather than blanking it', function (): void {
    Mail::fake();

    // A visible `:total` in an email is a bug report from the message. An
    // empty space is a bug nobody notices.
    app(Notifier::class)->send(
        NotificationEvent::InvoiceIssued,
        [app(ResolveRecipients::class)->forContact($this->contact, NotificationEvent::InvoiceIssued)],
        ['invoice_number' => 'INV-000004'],
        channels: [NotificationChannel::Database],
    );

    expect(InAppNotification::query()->sole()->body)->toContain(':total');
});

it('prefers an operators template over the shipped wording', function (): void {
    Mail::fake();

    NotificationTemplate::factory()
        ->forEvent(NotificationEvent::InvoiceIssued, 'en')
        ->create(['subject' => 'Your bill :invoice_number', 'body' => 'Pay :total please.']);

    app(Notifier::class)->send(
        NotificationEvent::InvoiceIssued,
        [app(ResolveRecipients::class)->forContact($this->contact, NotificationEvent::InvoiceIssued)],
        ['invoice_number' => 'INV-000004', 'total' => '€14.99'],
        channels: [NotificationChannel::Database],
    );

    expect(InAppNotification::query()->sole()->title)->toBe('Your bill INV-000004');
});

it('falls back to the default locale rather than to the shipped wording', function (): void {
    Mail::fake();

    NotificationTemplate::factory()
        ->forEvent(NotificationEvent::InvoiceIssued, 'en')
        ->create(['subject' => 'Northwind bill :invoice_number', 'body' => 'Body']);

    // Better a customised message in the wrong language than a shipped one:
    // the customisation usually carries the brand.
    $message = app(RenderTemplate::class)->handle(
        NotificationEvent::InvoiceIssued,
        'tr',
        ['invoice_number' => 'INV-000004'],
    );

    expect($message->subject)->toBe('Northwind bill INV-000004');
});

it('records a suppression when the recipient opted out', function (): void {
    Mail::fake();

    $this->contact->forceFill(['notify_marketing' => false])->save();

    // `order.placed` is a product message; the contact has that on. Use a
    // marketing-category event to exercise the opt-out.
    $recipient = new NotificationRecipient(
        name: 'Ayse',
        email: 'ayse@example.test',
        acceptsCategory: false,
    );

    app(Notifier::class)->send(
        NotificationEvent::OrderPlaced,
        [$recipient],
        channels: [NotificationChannel::Mail],
    );

    Mail::assertNothingSent();

    // Recorded rather than silently dropped: "they asked us not to" is an
    // answer a support agent needs, and an absence is not.
    $delivery = NotificationDelivery::query()->withoutGlobalScope('organization')->sole();

    expect($delivery->status)->toBe(DeliveryStatus::Suppressed);
});

it('sends a transactional message however the customer set their preferences', function (): void {
    Mail::fake();

    $this->contact->forceFill(['notify_invoices' => false])->save();

    $recipient = app(ResolveRecipients::class)
        ->forContact($this->contact->fresh() ?? $this->contact, NotificationEvent::ServiceSuspended);

    // A platform that lets somebody opt out of "your site is about to be
    // turned off" and then turns it off has chosen the wrong side.
    expect($recipient->acceptsCategory)->toBeTrue();

    app(Notifier::class)->send(
        NotificationEvent::ServiceSuspended,
        [$recipient],
        ['service_name' => 'Starter Plan', 'reason' => 'Non-payment'],
        channels: [NotificationChannel::Mail],
    );

    Mail::assertSent(TemplatedMessage::class);
});

it('suppresses a mail with no address, and says why', function (): void {
    Mail::fake();

    app(Notifier::class)->send(
        NotificationEvent::InvoiceIssued,
        [new NotificationRecipient(name: 'Nobody', email: null)],
        channels: [NotificationChannel::Mail],
    );

    $delivery = NotificationDelivery::query()->withoutGlobalScope('organization')->sole();

    expect($delivery->status)->toBe(DeliveryStatus::Suppressed)
        ->and($delivery->error)->toBe(__('notifications.errors.no_address'));
});

it('lets one channel fail without stopping the others', function (): void {
    $broken = new class implements DeliversNotifications
    {
        public function channel(): NotificationChannel
        {
            return NotificationChannel::Mail;
        }

        public function deliver(NotificationRecipient $recipient, RenderedMessage $message): DeliveryOutcome
        {
            throw new RuntimeException('SMTP connection refused');
        }
    };

    $registry = new ChannelRegistry;
    $registry->register($broken);
    $registry->register(new DatabaseChannel);
    $this->app->instance(ChannelRegistry::class, $registry);

    app(Notifier::class)->send(
        NotificationEvent::InvoiceIssued,
        [app(ResolveRecipients::class)->forContact($this->contact, NotificationEvent::InvoiceIssued)],
        ['invoice_number' => 'INV-000004'],
        channels: [NotificationChannel::Mail, NotificationChannel::Database],
    );

    // A mail server being down must not cost the customer their in-app
    // copy — and must not throw into whatever triggered the message.
    expect(InAppNotification::query()->count())->toBe(1);

    $failed = NotificationDelivery::query()->withoutGlobalScope('organization')
        ->where('status', DeliveryStatus::Failed->value)
        ->sole();

    expect($failed->error)->toContain('SMTP connection refused');
});

it('records what was actually sent, not what the contact says now', function (): void {
    Mail::fake();

    app(Notifier::class)->send(
        NotificationEvent::InvoiceIssued,
        [app(ResolveRecipients::class)->forContact($this->contact, NotificationEvent::InvoiceIssued)],
        ['invoice_number' => 'INV-000004'],
        channels: [NotificationChannel::Mail],
    );

    $this->contact->forceFill(['email' => 'new-address@example.test'])->save();

    // "Which address did we send it to" is the question that matters.
    $delivery = NotificationDelivery::query()->withoutGlobalScope('organization')->sole();

    expect($delivery->recipient_address)->toBe('ayse@example.test')
        ->and($delivery->status)->toBe(DeliveryStatus::Sent)
        ->and($delivery->delivered_at)->not->toBeNull();
});

it('sends to every contact on the customer who can reach the portal', function (): void {
    Mail::fake();

    Contact::factory()->forCustomer($this->customer)->create(['portal_access' => true]);
    Contact::factory()->forCustomer($this->customer)->create(['portal_access' => false]);

    app(Notifier::class)->send(
        NotificationEvent::InvoiceIssued,
        app(ResolveRecipients::class)->forCustomer($this->customer, NotificationEvent::InvoiceIssued),
        ['invoice_number' => 'INV-000004'],
        channels: [NotificationChannel::Mail],
    );

    expect(deliveries())->toBe(2);
});
