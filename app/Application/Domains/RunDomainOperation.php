<?php

declare(strict_types=1);

namespace App\Application\Domains;

use App\Application\Domains\Exceptions\DomainNotOperable;
use App\Domain\Crm\AddressType;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Domains\DomainOperation;
use App\Domain\Domains\DomainStatus;
use App\Domain\Domains\RegistrantDetails;
use App\Domain\Domains\RegistrarResult;
use App\Domain\Domains\RegistrationRequest;
use App\Domain\Provisioning\OperationOutcome;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Domains\RegistrarRegistry;
use App\Support\Correlation\CorrelationContext;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The one place a registrar is called.
 *
 * Every rule from
 * [ADR 0026](../../../docs/adr/0026-provisioning-is-idempotent-and-failure-is-a-state.md)
 * applies here unchanged, because a registration is the same kind of
 * irreversible act as creating an account: the call happens outside any
 * transaction, the external id is written the moment it arrives,
 * `already_done` is a success so a retry is safe, and a run that cannot
 * succeed leaves the domain in `failed` with a reason rather than in
 * `registering` forever.
 *
 * One rule is this phase's own. **The transfer code is never stored.** It
 * is fetched, returned to the caller to show once, and recorded in the
 * event log only as "an operator asked for it" — never as its value.
 */
final readonly class RunDomainOperation
{
    public function __construct(
        private RegistrarRegistry $registrars,
        private TransitionDomain $transitions,
        private RecordDomainEvent $events,
        private CorrelationContext $correlation,
        private CurrentActor $actor,
    ) {}

    public function register(Domain $domain, ?Model $actor = null): RegistrarResult
    {
        $actor ??= $this->actor->model();

        if (! $domain->status->canRegister()) {
            throw DomainNotOperable::wrongStatus($domain->status->value);
        }

        $registrar = $this->registrarFor($domain, DomainOperation::Register);

        $this->transitions->handle($domain, DomainStatus::Registering, $actor);

        $request = new RegistrationRequest(
            domainId: $domain->id,
            name: $domain->domainName(),
            years: $domain->years,
            registrant: $this->registrantFor($domain),
            nameservers: $domain->nameservers ?? $this->defaultNameservers(),
            whoisPrivacy: $domain->whois_privacy,
            autoRenew: $domain->auto_renew,
            correlationId: $this->correlation->id(),
        );

        $result = $this->call(
            $domain,
            DomainOperation::Register,
            static fn (): RegistrarResult => $registrar->register($request),
            $actor,
        );

        if (! $result->isSuccessful()) {
            return $result;
        }

        // Written first, and separately: an id that was not stored is a
        // name nobody can renew.
        $domain->forceFill(array_filter([
            'external_id' => $result->externalId ?? $domain->external_id,
            'expires_on' => $result->expiresOn ?? $this->assumedExpiry($domain),
            'nameservers' => $result->nameservers === [] ? $domain->nameservers : $result->nameservers,
        ], static fn (mixed $value): bool => $value !== null))->save();

        $this->transitions->handle($domain, DomainStatus::Active, $actor);

        return $result;
    }

    public function renew(Domain $domain, int $years, ?Model $actor = null): RegistrarResult
    {
        $actor ??= $this->actor->model();
        $registrar = $this->registrarFor($domain, DomainOperation::Renew);

        $result = $this->call(
            $domain,
            DomainOperation::Renew,
            static fn (): RegistrarResult => $registrar->renew($domain->reference(), $years),
            $actor,
        );

        if (! $result->isSuccessful()) {
            return $result;
        }

        $domain->forceFill([
            'expires_on' => $result->expiresOn ?? $this->extendedExpiry($domain, $years),
        ])->save();

        if ($domain->status !== DomainStatus::Active
            && $domain->status->canTransitionTo(DomainStatus::Active)) {
            $this->transitions->handle($domain, DomainStatus::Active, $actor);
        }

        return $result;
    }

    /**
     * @param  list<string>  $nameservers
     */
    public function setNameservers(Domain $domain, array $nameservers, ?Model $actor = null): RegistrarResult
    {
        $actor ??= $this->actor->model();
        $registrar = $this->registrarFor($domain, DomainOperation::SetNameservers);

        $result = $this->call(
            $domain,
            DomainOperation::SetNameservers,
            static fn (): RegistrarResult => $registrar->setNameservers($domain->reference(), $nameservers),
            $actor,
        );

        if ($result->isSuccessful()) {
            $domain->forceFill(['nameservers' => $nameservers])->save();
        }

        return $result;
    }

    public function setLock(Domain $domain, bool $locked, ?Model $actor = null): RegistrarResult
    {
        $actor ??= $this->actor->model();
        $registrar = $this->registrarFor($domain, DomainOperation::SetLock);

        $result = $this->call(
            $domain,
            DomainOperation::SetLock,
            static fn (): RegistrarResult => $registrar->setLock($domain->reference(), $locked),
            $actor,
        );

        if ($result->isSuccessful()) {
            $domain->forceFill(['registrar_lock' => $locked])->save();
        }

        return $result;
    }

    public function setAutoRenew(Domain $domain, bool $enabled, ?Model $actor = null): RegistrarResult
    {
        $actor ??= $this->actor->model();
        $registrar = $this->registrarFor($domain, DomainOperation::SetAutoRenew);

        $result = $this->call(
            $domain,
            DomainOperation::SetAutoRenew,
            static fn (): RegistrarResult => $registrar->setAutoRenew($domain->reference(), $enabled),
            $actor,
        );

        if ($result->isSuccessful()) {
            $domain->forceFill(['auto_renew' => $enabled])->save();
        }

        return $result;
    }

    /**
     * Fetch the code that moves this domain away.
     *
     * Returned to the caller to show once. The event records that somebody
     * asked — which is the part worth keeping — and never the value.
     */
    public function requestTransferCode(Domain $domain, ?Model $actor = null): RegistrarResult
    {
        $actor ??= $this->actor->model();
        $registrar = $this->registrarFor($domain, DomainOperation::RequestTransferCode);

        try {
            $result = $registrar->requestTransferCode($domain->reference());
        } catch (Throwable $exception) {
            return $this->fail($domain, DomainOperation::RequestTransferCode, $exception->getMessage(), $actor);
        }

        $this->events->handle(
            $domain,
            DomainOperation::RequestTransferCode,
            $result->outcome,
            $result->isSuccessful() ? null : $result->message,
            actor: $actor,
        );

        return $result;
    }

    /**
     * Ask the registry what it thinks is true.
     *
     * Reports; does not decide. Whether a domain the registry calls expired
     * should be marked expired here, and what that costs the customer, is
     * Phase 9's question.
     */
    public function sync(Domain $domain, ?Model $actor = null): RegistrarResult
    {
        $actor ??= $this->actor->model();
        $registrar = $this->registrarFor($domain, DomainOperation::Sync);

        try {
            $sync = $registrar->sync($domain->reference());
        } catch (Throwable $exception) {
            return $this->fail($domain, DomainOperation::Sync, $exception->getMessage(), $actor);
        }

        $domain->forceFill(array_filter([
            'synced_at' => CarbonImmutable::now(),
            'expires_on' => $sync->expiresOn,
            'nameservers' => $sync->nameservers === [] ? null : $sync->nameservers,
            'registrar_lock' => $sync->locked,
            'auto_renew' => $sync->autoRenew,
        ], static fn (mixed $value): bool => $value !== null))->save();

        $this->events->handle(
            $domain,
            DomainOperation::Sync,
            $sync->reachable ? OperationOutcome::Succeeded : OperationOutcome::Failed,
            $sync->message,
            ['remote_status' => $sync->remoteStatus?->value],
            $actor,
        );

        return $sync->reachable
            ? RegistrarResult::succeeded(expiresOn: $sync->expiresOn, nameservers: $sync->nameservers)
            : RegistrarResult::failed((string) $sync->message);
    }

    /**
     * @param  callable(): RegistrarResult  $operation
     */
    private function call(
        Domain $domain,
        DomainOperation $name,
        callable $operation,
        ?Model $actor,
    ): RegistrarResult {
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            return $this->fail($domain, $name, $exception->getMessage(), $actor);
        }

        if (! $result->isSuccessful()) {
            return $this->fail($domain, $name, (string) $result->message, $actor, $result->metadata);
        }

        $this->events->handle($domain, $name, $result->outcome, $result->message, $result->metadata, $actor);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function fail(
        Domain $domain,
        DomainOperation $name,
        string $message,
        ?Model $actor,
        array $metadata = [],
    ): RegistrarResult {
        $this->events->handle($domain, $name, OperationOutcome::Failed, $message, $metadata, $actor);

        // Only a failed registration or transfer leaves the domain
        // unusable. A nameserver change that did not work leaves it active,
        // which is the truth.
        $breaks = $name === DomainOperation::Register || $name === DomainOperation::Transfer;

        if ($breaks && $domain->status->canTransitionTo(DomainStatus::Failed)) {
            $this->transitions->handle($domain, DomainStatus::Failed, $actor, $message);
        }

        return RegistrarResult::failed($message, $metadata);
    }

    private function registrarFor(Domain $domain, DomainOperation $operation): DomainRegistrar
    {
        $key = $domain->registrar;

        if ($key === null || $key === '') {
            throw DomainNotOperable::noRegistrar();
        }

        $registrar = $this->registrars->find($key);

        if (! $registrar instanceof DomainRegistrar) {
            throw DomainNotOperable::unknownRegistrar($key);
        }

        if (! $registrar->capabilities()->supports($operation)) {
            throw DomainNotOperable::unsupported($key, $operation);
        }

        return $registrar;
    }

    /**
     * Who the registry is told owns the name.
     *
     * Built at the moment of registration from the customer's own contact
     * and address. Never stored beside the domain: the registry holds the
     * authoritative copy.
     */
    private function registrantFor(Domain $domain): RegistrantDetails
    {
        $customer = $domain->customer;
        $contact = $customer?->primaryContact;
        $address = $customer instanceof Customer ? $customer->addressFor(AddressType::Billing) : null;

        return new RegistrantDetails(
            firstName: $contact === null ? '' : $contact->first_name,
            lastName: $contact === null ? '' : $contact->last_name,
            email: $contact === null ? '' : $contact->email,
            organization: $customer?->company_name,
            phone: $contact?->phone,
            addressLine: $address?->line_one,
            city: $address?->city,
            region: $address?->region,
            postalCode: $address?->postal_code,
            countryCode: $address?->country_code,
        );
    }

    /**
     * @return list<string>
     */
    private function defaultNameservers(): array
    {
        /** @var list<string> $nameservers */
        $nameservers = config('platform.domains.default_nameservers', []);

        return $nameservers;
    }

    /**
     * A registrar that did not report an expiry gets one assumed from the
     * term, marked for a sync to correct. A domain with no expiry at all
     * would never be renewed by anything.
     */
    private function assumedExpiry(Domain $domain): string
    {
        return CarbonImmutable::now()->addYears($domain->years)->toDateString();
    }

    private function extendedExpiry(Domain $domain, int $years): string
    {
        $from = $domain->expires_on ?? CarbonImmutable::now();

        return $from->addYears($years)->toDateString();
    }
}
