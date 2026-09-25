<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Notifications\Notifier;
use App\Application\Notifications\RenderTemplate;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationRecipient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\NotificationTemplateRequest;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Notifications\Models\NotificationDelivery;
use App\Infrastructure\Notifications\Models\NotificationTemplate;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What each message says, and what became of every one this platform sent.
 *
 * Editing a template changes what every customer is told, which is why
 * `notifications.manage` is high risk and why a test send exists: an
 * operator should be able to see their wording arrive before a customer
 * does.
 *
 * Resetting **deletes the row** rather than overwriting it with the
 * shipped text. The shipped wording lives in `lang/`, so removing the
 * customisation restores it — and the restored copy keeps being translated
 * into every locale, which a copied-in string would not.
 */
final class NotificationTemplateController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly RenderTemplate $renderer,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeFor('notifications.view');

        $locale = $request->string('locale')->toString() ?: (string) config('app.locale', 'en');

        $templates = NotificationTemplate::query()
            ->where('locale', $locale)
            ->get()
            ->keyBy(static fn (NotificationTemplate $template): string => $template->event->value);

        return Inertia::render('Admin/Notifications/Templates', [
            // `editingLocale`, not `locale`: the shell shares the
            // request's own locale under that name.
            'editingLocale' => $locale,
            'templates' => array_values(array_map(
                function (NotificationEvent $event) use ($templates, $locale): array {
                    $custom = $templates->get($event->value);
                    $shipped = $this->renderer->handle($event, $locale, $this->sampleFor($event));

                    return [
                        'event' => $event->value,
                        'label' => (string) __($event->labelKey()),
                        'category' => (string) __($event->category()->labelKey()),
                        'transactional' => $event->isTransactional(),
                        'isCustomised' => $custom !== null,
                        'templateId' => $custom?->id,
                        'subject' => $custom === null ? $shipped->subject : $custom->subject,
                        'body' => $custom === null ? $shipped->body : $custom->body,
                        'actionLabel' => $custom === null || $custom->action_label === null
                            ? $shipped->actionLabel
                            : $custom->action_label,
                        // Rendered with sample values, so an operator sees
                        // the sentence rather than the placeholders.
                        'previewSubject' => $shipped->subject,
                        'previewBody' => $shipped->body,
                        'placeholders' => array_keys($this->sampleFor($event)),
                    ];
                },
                NotificationEvent::cases(),
            )),
            'can' => ['manage' => $this->actor->can('notifications.manage')],
        ]);
    }

    public function update(
        NotificationTemplateRequest $request,
        string $event,
        string $locale,
    ): RedirectResponse {
        $this->authorizeFor('notifications.manage');

        $template = NotificationTemplate::query()->updateOrCreate(
            ['event' => NotificationEvent::from($event)->value, 'locale' => $locale],
            [
                'subject' => $request->string('subject')->toString(),
                'body' => $request->string('body')->toString(),
                'action_label' => $request->input('action_label'),
                'is_customised' => true,
                'is_active' => true,
            ],
        );

        Audit::action('notifications.template.updated')
            ->by($this->actor->model())
            ->on($template)
            ->withMetadata(['event' => $event, 'locale' => $locale])
            ->write();

        return back()->with('status', __('notifications.admin.saved'));
    }

    public function reset(NotificationTemplate $template): RedirectResponse
    {
        $this->authorizeFor('notifications.manage');

        $event = $template->event->value;
        $locale = $template->locale;

        // Deleted, not overwritten: the shipped wording lives in `lang/`
        // and keeps being translated. A copied-in string would not.
        $template->delete();

        Audit::action('notifications.template.reset')
            ->by($this->actor->model())
            ->withMetadata(['event' => $event, 'locale' => $locale])
            ->write();

        return back()->with('status', __('notifications.admin.reset_done'));
    }

    /**
     * Send the operator their own wording.
     */
    public function test(string $event, string $locale, Notifier $notifier): RedirectResponse
    {
        $this->authorizeFor('notifications.manage');

        $staff = $this->actor->model();

        if (! $staff instanceof StaffUser) {
            throw new ForbiddenException(__('notifications.admin.not_permitted'));
        }

        $notificationEvent = NotificationEvent::from($event);

        $notifier->send(
            $notificationEvent,
            [new NotificationRecipient(
                name: $staff->displayName(),
                email: $staff->email,
                locale: $locale,
                subjectType: $staff::class,
                subjectId: $staff->id,
                isStaff: true,
            )],
            $this->sampleFor($notificationEvent),
            channels: [NotificationChannel::Mail],
        );

        return back()->with('status', __('notifications.admin.test_sent'));
    }

    public function log(Request $request): Response
    {
        $this->authorizeFor('notifications.view');

        $event = $request->string('event')->toString();

        $deliveries = NotificationDelivery::query()
            ->when(
                NotificationEvent::tryFrom($event) instanceof NotificationEvent,
                fn ($query) => $query->where('event', $event),
            )
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Notifications/Log', [
            'deliveries' => [
                'data' => array_map(
                    static fn (NotificationDelivery $delivery): array => [
                        'id' => $delivery->id,
                        'event' => (string) __($delivery->event->labelKey()),
                        'channel' => (string) __($delivery->channel->labelKey()),
                        'status' => $delivery->status->value,
                        'statusLabel' => (string) __($delivery->status->labelKey()),
                        'recipient' => $delivery->recipient_name,
                        'address' => $delivery->recipient_address,
                        'subject' => $delivery->rendered_subject,
                        'error' => $delivery->error,
                        'createdAt' => $delivery->created_at->toIso8601String(),
                    ],
                    $deliveries->items(),
                ),
                'currentPage' => $deliveries->currentPage(),
                'lastPage' => $deliveries->lastPage(),
                'total' => $deliveries->total(),
                'links' => $deliveries->linkCollection()->all(),
            ],
            'filters' => ['event' => $event === '' ? null : $event],
            'events' => array_values(array_map(
                static fn (NotificationEvent $value): array => [
                    'value' => $value->value,
                    'label' => (string) __($value->labelKey()),
                ],
                NotificationEvent::cases(),
            )),
        ]);
    }

    /**
     * Believable values for a preview and a test send.
     *
     * Not `lorem ipsum`: an operator checking whether their sentence reads
     * well needs it to read like a real message.
     *
     * The dates are dates, not strings: the preview is worth having only if
     * it words them exactly as the message will.
     *
     * @return array<string, string|CarbonInterface>
     */
    private function sampleFor(NotificationEvent $event): array
    {
        return match ($event) {
            NotificationEvent::OrderPlaced,
            NotificationEvent::OrderPaid => ['order_number' => 'ORD-000042', 'total' => '€14.99'],

            NotificationEvent::InvoiceIssued => [
                'invoice_number' => 'INV-000042',
                'total' => '€14.99',
                'due_date' => CarbonImmutable::parse('2026-10-11'),
            ],

            NotificationEvent::PaymentReceived => [
                'invoice_number' => 'INV-000042',
                'amount' => '€14.99',
            ],

            NotificationEvent::PaymentFailed => [
                'invoice_number' => 'INV-000042',
                'amount' => '€14.99',
                'reason' => 'Your card was declined.',
            ],

            NotificationEvent::ServiceProvisioned,
            NotificationEvent::ServiceTerminated => [
                'service_name' => 'Starter Plan',
                'domain' => 'example.com',
            ],

            NotificationEvent::ServiceSuspended => [
                'service_name' => 'Starter Plan',
                'reason' => 'Invoice INV-000042 is 14 days overdue.',
            ],

            NotificationEvent::DomainRegistered,
            NotificationEvent::DomainExpiring => [
                'domain' => 'example.com',
                'expires_on' => CarbonImmutable::parse('2027-09-27'),
            ],

            NotificationEvent::TicketOpened,
            NotificationEvent::TicketReplied => [
                'ticket_number' => 'TKT-000042',
                'subject' => 'Site is down',
                'department' => 'Technical Support',
            ],
        };
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('notifications.admin.not_permitted'));
        }
    }
}
