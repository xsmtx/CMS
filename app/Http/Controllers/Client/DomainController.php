<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Application\Domains\RunDomainOperation;
use App\Application\Operations\WatchedDispatch;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Domains\DomainOperation;
use App\Domain\Domains\DomainStatus;
use App\Domain\Domains\RegistrarCapabilities;
use App\Domain\Operations\OperationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Domains\NameserverRequest;
use App\Infrastructure\Domains\Jobs\RunDomainAction;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Domains\RegistrarRegistry;
use App\Support\Correlation\CorrelationContext;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Identity\CurrentCustomer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The names a customer holds.
 *
 * Three things a domain owner actually does between registering and
 * renewing: point it somewhere, decide whether it renews itself, and take
 * it away. All three are here, and nothing else is — the registrar's name,
 * the registry's reference and the event log are an operator's vocabulary.
 */
final class DomainController extends Controller
{
    public function __construct(
        private readonly CurrentCustomer $customer,
        private readonly CurrentActor $actor,
        private readonly RegistrarRegistry $registrars,
        private readonly CorrelationContext $correlation,
        private readonly WatchedDispatch $dispatcher,
    ) {}

    public function index(): Response
    {
        $this->authorizeDomains('portal.domains.view');

        $domains = $this->customer
            ->owned(Domain::query())
            ->whereNotIn('status', [DomainStatus::Deleted->value, DomainStatus::Cancelled->value])
            ->orderByRaw('expires_on IS NULL, expires_on ASC')
            ->get();

        return Inertia::render('Client/Domains/Index', [
            'domains' => $domains->map(fn (Domain $domain): array => $this->row($domain))->values()->all(),
        ]);
    }

    public function show(string $domain): Response
    {
        $this->authorizeDomains('portal.domains.view');

        $record = $this->find($domain);
        $capabilities = $this->capabilitiesFor($record);

        return Inertia::render('Client/Domains/Show', [
            'domain' => [
                ...$this->row($record),
                'registeredOn' => $record->registered_on?->toDateString(),
                'years' => $record->years,
                'autoRenew' => $record->auto_renew,
                'registrarLock' => $record->registrar_lock,
                'nameservers' => $record->nameservers ?? [],
            ],
            'can' => [
                'manage' => $this->actor->can('portal.domains.manage'),
                'nameservers' => $this->actor->can('portal.domains.manage')
                    && ($capabilities !== null && $capabilities->nameservers),
                'autoRenew' => $this->actor->can('portal.domains.manage')
                    && ($capabilities !== null && $capabilities->autoRenew),
                'transferCode' => $this->actor->can('portal.domains.manage')
                    && ($capabilities !== null && $capabilities->transferCode),
            ],
            // Flashed once by the request below, never stored.
            'transferCode' => session('transferCode'),
        ]);
    }

    public function nameservers(NameserverRequest $request, string $domain): RedirectResponse
    {
        $this->authorizeDomains('portal.domains.manage');

        $record = $this->find($domain);

        /** @var list<string> $nameservers */
        $nameservers = array_values(array_filter((array) $request->input('nameservers', [])));

        $this->dispatcher->handle(
            OperationType::DomainSync,
            $record,
            new RunDomainAction(
                $record->id,
                DomainOperation::SetNameservers,
                null,
                $nameservers,
                1,
                $this->correlation->id(),
            ),
            $this->actor->model(),
        );

        return back()->with('status', __('domains.portal.nameservers_queued'));
    }

    public function autoRenew(string $domain): RedirectResponse
    {
        $this->authorizeDomains('portal.domains.manage');

        $record = $this->find($domain);

        $this->dispatcher->handle(
            OperationType::DomainSync,
            $record,
            new RunDomainAction(
                $record->id,
                DomainOperation::SetAutoRenew,
                ! $record->auto_renew,
                [],
                1,
                $this->correlation->id(),
            ),
            $this->actor->model(),
        );

        return back()->with('status', __('domains.domains.queued'));
    }

    /**
     * Fetch the code that moves this domain away.
     *
     * Run inline rather than queued, because the customer is looking at the
     * screen waiting for it — and flashed rather than stored, because it is
     * the credential that moves the domain and a copy of it here is a copy
     * somebody has to protect forever.
     */
    public function transferCode(string $domain, RunDomainOperation $operations): RedirectResponse
    {
        $this->authorizeDomains('portal.domains.manage');

        $record = $this->find($domain);
        $result = $operations->requestTransferCode($record, $this->customer->contact());

        if (! $result->isSuccessful() || $result->transferCode === null) {
            return back()->with('error', $result->message ?? __('domains.errors.no_transfer_code'));
        }

        return back()->with('transferCode', $result->transferCode);
    }

    private function find(string $id): Domain
    {
        /** @var Domain $domain */
        $domain = $this->customer->find(Domain::query()->whereKey($id));

        return $domain;
    }

    private function capabilitiesFor(Domain $domain): ?RegistrarCapabilities
    {
        $registrar = $domain->registrar === null ? null : $this->registrars->find($domain->registrar);

        return $registrar instanceof DomainRegistrar ? $registrar->capabilities() : null;
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
            'isUsable' => $domain->status->isUsable(),
            'expiresOn' => $domain->expires_on?->toDateString(),
            'daysUntilExpiry' => $domain->daysUntilExpiry(),
            'renewal' => $domain->renewal->format(app()->getLocale()),
        ];
    }

    private function authorizeDomains(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('domains.domains.not_permitted'));
        }
    }
}
