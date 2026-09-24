<?php

declare(strict_types=1);

namespace InfraCMS\RegistrarRealtime;

use App\Domain\Domains\AvailabilityResult;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Domains\DomainName;
use App\Domain\Domains\DomainReference;
use App\Domain\Domains\DomainStatus;
use App\Domain\Domains\DomainSyncResult;
use App\Domain\Domains\RegistrantDetails;
use App\Domain\Domains\RegistrarCapabilities;
use App\Domain\Domains\RegistrarResult;
use App\Domain\Domains\RegistrationRequest;
use App\Domain\Domains\TransferRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Realtime Register, over its v2 REST API.
 *
 * **A registry that did not answer has not said a name is free.** Availability
 * is three-valued here and stays that way (ADR 0028): a timeout, a 500 or a
 * malformed answer all produce `unknown`, never `available`. Collapsing the
 * third state is how a customer pays for a name somebody else owns.
 *
 * **A contact is created before a domain, and reused after.** Realtime Register
 * addresses registrants by handle, not by a block of fields, so registering
 * means having a contact handle first. The handle is derived from the
 * registrant's email, so the same person registering a second domain reuses the
 * first handle instead of accumulating one per name.
 *
 * **The transfer code is fetched and never stored.** It is returned once, to be
 * shown once, and this class keeps no copy — the registry holds the truth, and a
 * copy here is a copy to leak.
 *
 * It has never talked to Realtime Register. Written against the published
 * documentation and tested against faked HTTP.
 */
final readonly class RealtimeRegistrar implements DomainRegistrar
{
    public function __construct(
        private string $customer,
        private string $apiKey,
        private string $apiBase = 'https://api.yoursrs.com',
        private int $timeout = 30,
    ) {}

    public function key(): string
    {
        return 'realtime';
    }

    public function capabilities(): RegistrarCapabilities
    {
        return new RegistrarCapabilities(
            checkAvailability: true,
            register: true,
            transfer: true,
            renew: true,
            nameservers: true,
            lock: true,
            autoRenew: true,
            transferCode: true,
            sync: true,
            whoisPrivacy: true,
        );
    }

    public function checkAvailability(DomainName $name): AvailabilityResult
    {
        try {
            $response = $this->client()->get($this->apiBase.'/v2/domains/'.$name->value.'/check');
        } catch (Throwable $exception) {
            // Not "available". A registry that did not answer said nothing.
            return AvailabilityResult::unknown($name, $exception->getMessage());
        }

        if ($response->failed()) {
            return AvailabilityResult::unknown($name, $this->reason($response->json() ?? []));
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if (! array_key_exists('available', $body)) {
            return AvailabilityResult::unknown($name, 'The registry answered without saying.');
        }

        return $body['available'] === true
            ? AvailabilityResult::available($name, premium: (bool) ($body['premium'] ?? false))
            : AvailabilityResult::taken($name);
    }

    public function register(RegistrationRequest $request): RegistrarResult
    {
        $handle = $this->contactHandle($request->registrant);

        if ($handle === null) {
            return RegistrarResult::failed('Realtime Register would not take the registrant.');
        }

        try {
            $response = $this->client()->post($this->apiBase.'/v2/domains/'.$request->name->value, [
                'customer' => $this->customer,
                'period' => $request->years,
                'registrant' => $handle,
                'privacyProtect' => $request->whoisPrivacy,
                'autoRenew' => $request->autoRenew,
                'ns' => $request->nameservers,
            ]);
        } catch (Throwable $exception) {
            return RegistrarResult::failed($exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed()) {
            $reason = $this->reason($body);

            // Ours already is the state the caller wanted; somebody else's is
            // not, and the two must not be confused.
            if (str_contains(strtolower($reason), 'already registered by you')) {
                return RegistrarResult::alreadyDone($request->name->value, $reason);
            }

            return RegistrarResult::failed($reason);
        }

        return RegistrarResult::succeeded(
            externalId: $request->name->value,
            expiresOn: $this->date($body['expiryDate'] ?? null),
            nameservers: $request->nameservers,
        );
    }

    public function transfer(TransferRequest $request): RegistrarResult
    {
        $handle = $this->contactHandle($request->registrant);

        if ($handle === null) {
            return RegistrarResult::failed('Realtime Register would not take the registrant.');
        }

        try {
            $response = $this->client()->post($this->apiBase.'/v2/domains/'.$request->name->value.'/transfer', [
                'customer' => $this->customer,
                'period' => $request->years,
                'registrant' => $handle,
                'authcode' => $request->authCode,
            ]);
        } catch (Throwable $exception) {
            return RegistrarResult::failed($exception->getMessage());
        }

        return $response->failed()
            ? RegistrarResult::failed($this->reason($response->json() ?? []))
            // A transfer is accepted, not completed: the losing registrar has
            // days to object, and `sync` is what reports the end of it.
            : RegistrarResult::succeeded(externalId: $request->name->value);
    }

    public function renew(DomainReference $domain, int $years): RegistrarResult
    {
        try {
            $response = $this->client()->post($this->apiBase.'/v2/domains/'.$domain->name->value.'/renew', [
                'period' => $years,
            ]);
        } catch (Throwable $exception) {
            return RegistrarResult::failed($exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return $response->failed()
            ? RegistrarResult::failed($this->reason($body))
            : RegistrarResult::succeeded(
                externalId: $domain->externalId ?? $domain->name->value,
                expiresOn: $this->date($body['expiryDate'] ?? null),
            );
    }

    /**
     * @param  list<string>  $nameservers
     */
    public function setNameservers(DomainReference $domain, array $nameservers): RegistrarResult
    {
        return $this->update($domain, ['ns' => $nameservers], nameservers: $nameservers);
    }

    public function setLock(DomainReference $domain, bool $locked): RegistrarResult
    {
        // A registry lock is a status, and the API takes the list rather than a
        // boolean — an empty list is what "unlocked" looks like.
        return $this->update($domain, [
            'status' => $locked ? ['CLIENT_TRANSFER_PROHIBITED'] : [],
        ]);
    }

    public function setAutoRenew(DomainReference $domain, bool $enabled): RegistrarResult
    {
        return $this->update($domain, ['autoRenew' => $enabled]);
    }

    public function requestTransferCode(DomainReference $domain): RegistrarResult
    {
        try {
            $response = $this->client()->get($this->apiBase.'/v2/domains/'.$domain->name->value.'/authcode');
        } catch (Throwable $exception) {
            return RegistrarResult::failed($exception->getMessage());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        if ($response->failed()) {
            return RegistrarResult::failed($this->reason($body));
        }

        $code = $body['authcode'] ?? null;

        if (! is_string($code) || $code === '') {
            return RegistrarResult::failed('The registry returned no transfer code.');
        }

        // Shown once, never written down. The registry holds the truth.
        return RegistrarResult::code($code);
    }

    public function sync(DomainReference $domain): DomainSyncResult
    {
        try {
            $response = $this->client()->get($this->apiBase.'/v2/domains/'.$domain->name->value);
        } catch (Throwable $exception) {
            return DomainSyncResult::unreachable($exception->getMessage());
        }

        if ($response->failed()) {
            return DomainSyncResult::unreachable($this->reason($response->json() ?? []));
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        /** @var list<string> $statuses */
        $statuses = array_values(array_filter(
            (array) ($body['status'] ?? []),
            static fn (mixed $value): bool => is_string($value),
        ));

        return new DomainSyncResult(
            reachable: true,
            remoteStatus: $this->statusFrom($statuses),
            expiresOn: $this->date($body['expiryDate'] ?? null),
            nameservers: array_values(array_filter(
                (array) ($body['ns'] ?? []),
                static fn (mixed $value): bool => is_string($value),
            )),
            locked: in_array('CLIENT_TRANSFER_PROHIBITED', $statuses, true),
            autoRenew: is_bool($body['autoRenew'] ?? null) ? $body['autoRenew'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $nameservers
     */
    private function update(DomainReference $domain, array $fields, array $nameservers = []): RegistrarResult
    {
        try {
            $response = $this->client()->post($this->apiBase.'/v2/domains/'.$domain->name->value.'/update', $fields);
        } catch (Throwable $exception) {
            return RegistrarResult::failed($exception->getMessage());
        }

        return $response->failed()
            ? RegistrarResult::failed($this->reason($response->json() ?? []))
            : RegistrarResult::succeeded(
                externalId: $domain->externalId ?? $domain->name->value,
                nameservers: $nameservers,
            );
    }

    /**
     * A contact handle for this registrant, created if it is not there.
     *
     * Derived from the email so that the same person registering a second
     * domain reuses the first handle rather than accumulating one per name.
     */
    private function contactHandle(RegistrantDetails $registrant): ?string
    {
        $handle = substr('c'.substr(sha1(strtolower($registrant->email)), 0, 15), 0, 16);

        try {
            $existing = $this->client()->get($this->apiBase.'/v2/customers/'.$this->customer.'/contacts/'.$handle);

            if ($existing->successful()) {
                return $handle;
            }

            $response = $this->client()->post(
                $this->apiBase.'/v2/customers/'.$this->customer.'/contacts/'.$handle,
                array_filter([
                    'name' => $registrant->fullName(),
                    'organization' => $registrant->organization,
                    'addressLine' => array_filter([$registrant->addressLine]),
                    'city' => $registrant->city,
                    'state' => $registrant->region,
                    'postalCode' => $registrant->postalCode,
                    'country' => $registrant->countryCode,
                    'email' => $registrant->email,
                    'voice' => $registrant->phone,
                ], static fn (mixed $value): bool => $value !== null && $value !== []),
            );
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? $handle : null;
    }

    /**
     * @param  list<string>  $statuses
     */
    private function statusFrom(array $statuses): ?DomainStatus
    {
        foreach ($statuses as $status) {
            $match = match (strtoupper($status)) {
                'PENDING_TRANSFER' => DomainStatus::TransferPending,
                'REDEMPTION_PERIOD', 'PENDING_DELETE' => DomainStatus::Redemption,
                'EXPIRED' => DomainStatus::Expired,
                'ACTIVE', 'OK' => DomainStatus::Active,
                default => null,
            };

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'ApiKey '.$this->apiKey])
            ->timeout($this->timeout)
            ->retry(2, 500, throw: false)
            ->acceptJson();
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // `0000-00-00` and its relatives are read as no date: Carbon parses
        // them into the year zero without complaint.
        return str_starts_with($value, '0000') ? null : substr($value, 0, 10);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function reason(?array $body): string
    {
        $message = $body['message'] ?? $body['error'] ?? null;

        return is_string($message) && $message !== '' ? $message : 'Realtime Register refused the request.';
    }
}
