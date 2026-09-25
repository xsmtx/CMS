<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Domains\SearchDomains;
use App\Application\Domains\TransitionDomain;
use App\Application\Operations\WatchedDispatch;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Domains\DomainOperation;
use App\Domain\Domains\DomainStatus;
use App\Domain\Operations\OperationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Domains\DomainActionRequest;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Jobs\RegisterDomain;
use App\Infrastructure\Domains\Jobs\RunDomainAction;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Domains\Models\DomainEvent;
use App\Infrastructure\Domains\RegistrarRegistry;
use App\Support\Correlation\CorrelationContext;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every name this installation holds.
 *
 * Operations are queued, never run inside the request: a registry can take
 * a minute, and an operator who refreshes because nothing happened would
 * otherwise send a second registration.
 */
final class DomainController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly RegistrarRegistry $registrars,
        private readonly CorrelationContext $correlation,
        private readonly WatchedDispatch $dispatcher,
    ) {}

    public function index(Request $request, SearchDomains $search): Response
    {
        $this->authorize('viewAny', Domain::class);

        /** @var array<string, string> $criteria */
        $criteria = array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $request->only(['domain', 'status', 'registrar', 'client']),
        );

        $domains = $search->paginate($criteria);

        return Inertia::render('Admin/Domains/Index', [
            'domains' => [
                'data' => array_map($this->row(...), $domains->items()),
                'currentPage' => $domains->currentPage(),
                'lastPage' => $domains->lastPage(),
                'total' => $domains->total(),
                'links' => $domains->linkCollection()->all(),
            ],
            'filters' => $criteria,
            'statuses' => $this->statuses(),
            'registrars' => $search->registrars(),
            'counts' => [
                'expiring' => Domain::query()->expiringWithin(45)->count(),
                'failed' => Domain::query()->where('status', DomainStatus::Failed->value)->count(),
                'pending' => Domain::query()->where('status', DomainStatus::Pending->value)->count(),
            ],
        ]);
    }

    public function show(Domain $domain): Response
    {
        $this->authorize('view', $domain);

        $domain->load([...Customer::displayNameWith('customer'), 'tld', 'order']);

        $registrar = $domain->registrar === null ? null : $this->registrars->find($domain->registrar);
        $capabilities = $registrar instanceof DomainRegistrar ? $registrar->capabilities() : null;

        return Inertia::render('Admin/Domains/Show', [
            'domain' => [
                ...$this->row($domain),
                'registrar' => $domain->registrar,
                'externalId' => $domain->external_id,
                'orderNumber' => $domain->order?->number,
                'registeredOn' => $domain->registered_on?->toDateString(),
                'years' => $domain->years,
                'autoRenew' => $domain->auto_renew,
                'registrarLock' => $domain->registrar_lock,
                'whoisPrivacy' => $domain->whois_privacy,
                'nameservers' => $domain->nameservers ?? [],
                'failureReason' => $domain->failure_reason,
                'syncedAt' => $domain->synced_at?->toIso8601String(),
                'events' => $domain->events()->limit(50)->get()
                    ->map(fn (DomainEvent $event): array => [
                        'id' => $event->id,
                        'operation' => (string) __($event->operation->labelKey()),
                        'outcome' => $event->outcome->value,
                        'outcomeLabel' => (string) __($event->outcome->labelKey()),
                        'actor' => $event->actor_label,
                        'message' => $event->message,
                        'occurredAt' => $event->occurred_at->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                'transitions' => array_map(
                    static fn (DomainStatus $status): array => [
                        'value' => $status->value,
                        'label' => (string) __($status->labelKey()),
                    ],
                    $domain->status->manualTransitions(),
                ),
            ],
            'can' => [
                'update' => $this->actor->can('update', $domain),
                'register' => $this->actor->can('register', $domain)
                    && $domain->status->canRegister(),
                'renew' => $this->actor->can('register', $domain)
                    && ($capabilities !== null && $capabilities->renew),
                'nameservers' => $this->actor->can('update', $domain)
                    && ($capabilities !== null && $capabilities->nameservers),
                'lock' => $this->actor->can('update', $domain)
                    && ($capabilities !== null && $capabilities->lock),
                'autoRenew' => $this->actor->can('update', $domain)
                    && ($capabilities !== null && $capabilities->autoRenew),
                'sync' => $this->actor->can('update', $domain)
                    && ($capabilities !== null && $capabilities->sync),
            ],
        ]);
    }

    public function register(Domain $domain): RedirectResponse
    {
        $this->authorize('register', $domain);

        $this->dispatcher->handle(
            OperationType::DomainRegister,
            $domain,
            new RegisterDomain($domain->id, $this->correlation->id()),
            $this->actor->model(),
        );

        return back()->with('status', __('domains.domains.queued'));
    }

    public function action(DomainActionRequest $request, Domain $domain): RedirectResponse
    {
        $operation = DomainOperation::from($request->string('operation')->toString());

        $this->authorize(
            $operation === DomainOperation::Renew ? 'register' : 'update',
            $domain,
        );

        /** @var list<string> $nameservers */
        $nameservers = array_values(array_filter((array) $request->input('nameservers', [])));

        $this->dispatcher->handle(
            $operation === DomainOperation::Renew
                ? OperationType::DomainRenew
                : OperationType::DomainSync,
            $domain,
            new RunDomainAction(
                $domain->id,
                $operation,
                $request->has('flag') ? $request->boolean('flag') : null,
                $nameservers,
                $request->integer('years') > 0 ? $request->integer('years') : 1,
                $this->correlation->id(),
            ),
            $this->actor->model(),
        );

        return back()->with('status', __('domains.domains.queued'));
    }

    public function transition(
        Request $request,
        Domain $domain,
        TransitionDomain $transitions,
    ): RedirectResponse {
        $this->authorize('update', $domain);

        $transitions->handle(
            $domain,
            DomainStatus::from((string) $request->input('status')),
            $this->actor->model(),
            $request->input('reason'),
        );

        return back()->with('status', __('domains.domains.saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Domain $domain): array
    {
        return [
            'id' => $domain->id,
            'name' => $domain->name,
            'status' => $domain->status->value,
            'statusLabel' => (string) __($domain->status->labelKey()),
            'customer' => $domain->customer?->displayName(),
            'customerId' => $domain->customer_id,
            'expiresOn' => $domain->expires_on?->toDateString(),
            'daysUntilExpiry' => $domain->daysUntilExpiry(),
            'renewal' => $domain->renewal->format(app()->getLocale()),
            'years' => $domain->years,
            'registrar' => $domain->registrar,
            // The renewal sweep's date, which is what an operator means by
            // "next due": the expiry is the registry's date and the two
            // differ by however long this installation invoices ahead.
            'nextDueOn' => $domain->renewal_invoiced_through?->toDateString()
                ?? $domain->expires_on?->toDateString(),
            'detail' => [
                'orderNumber' => $domain->order?->number,
                'orderId' => $domain->order_id,
                'orderType' => (string) __($domain->order_type->labelKey()),
                'registeredOn' => $domain->registered_on?->toDateString(),
                'dnsManagement' => $domain->dns_management,
                'emailForwarding' => $domain->email_forwarding,
                'idProtection' => $domain->id_protection,
                'premium' => $domain->is_premium,
                'paymentMethod' => $this->paymentMethod($domain),
            ],
        ];
    }

    /**
     * What paid for this name, if anything did.
     *
     * Read from the payments against the order's invoices rather than from
     * a column on the domain: a domain is not paid by a method, an invoice
     * is, and a second answer here would drift from the ledger.
     */
    private function paymentMethod(Domain $domain): ?string
    {
        $order = $domain->order;

        if ($order === null) {
            return null;
        }

        foreach ($order->invoices as $invoice) {
            foreach ($invoice->payments as $payment) {
                return $payment->gateway;
            }
        }

        return null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statuses(): array
    {
        return array_values(array_map(
            static fn (DomainStatus $status): array => [
                'value' => $status->value,
                'label' => (string) __($status->labelKey()),
            ],
            DomainStatus::cases(),
        ));
    }
}
