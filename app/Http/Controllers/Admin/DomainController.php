<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Domains\TransitionDomain;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Domains\DomainOperation;
use App\Domain\Domains\DomainStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Domains\DomainActionRequest;
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
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Domain::class);

        $status = $request->string('status')->toString();
        $expiring = $request->boolean('expiring');

        $domains = Domain::query()
            ->with('customer')
            ->when(
                DomainStatus::tryFrom($status) instanceof DomainStatus,
                fn ($query) => $query->where('status', $status),
            )
            ->when($expiring, fn ($query) => $query->expiringWithin(45))
            ->orderByRaw('expires_on IS NULL, expires_on ASC')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Domains/Index', [
            'domains' => [
                'data' => array_map($this->row(...), $domains->items()),
                'currentPage' => $domains->currentPage(),
                'lastPage' => $domains->lastPage(),
                'total' => $domains->total(),
            ],
            'filters' => ['status' => $status === '' ? null : $status, 'expiring' => $expiring],
            'statuses' => $this->statuses(),
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

        $domain->load(['customer', 'tld', 'order']);

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

        dispatch(new RegisterDomain($domain->id, $this->correlation->id()));

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

        dispatch(new RunDomainAction($domain->id, $operation, $request->has('flag') ? $request->boolean('flag') : null, $nameservers, $request->integer('years') > 0 ? $request->integer('years') : 1, $this->correlation->id()));

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
        ];
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
