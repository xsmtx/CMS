<?php

declare(strict_types=1);

namespace App\Infrastructure\Domains\Registrars;

use App\Domain\Domains\AvailabilityResult;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Domains\DomainName;
use App\Domain\Domains\DomainReference;
use App\Domain\Domains\DomainStatus;
use App\Domain\Domains\DomainSyncResult;
use App\Domain\Domains\RegistrantDetails;
use App\Domain\Domains\RegistrarAccount;
use App\Domain\Domains\RegistrarCapabilities;
use App\Domain\Domains\RegistrarResult;
use App\Domain\Domains\RegistrationRequest;
use App\Domain\Domains\TransferRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

/**
 * Namecheap, over its API.
 *
 * Written against the API directly rather than a vendor SDK, for the same
 * reason the Stripe and cPanel adapters are. The responses are XML, parsed
 * defensively: a registrar that returns an error page instead of a document
 * must produce a failed result, not an unhandled parse error inside a
 * worker.
 *
 * Three things worth knowing about this API, all of which shape the code:
 *
 * - **Errors arrive with HTTP 200.** The status code says the request was
 *   received, not that it worked; `Status="ERROR"` on the root element is
 *   the real answer.
 * - **The IP address making the call must be allow-listed** at Namecheap
 *   and sent with every request. A missing `ClientIp` fails in a way that
 *   reads like an authentication problem.
 * - **The contact set is repeated four times** — registrant, tech, admin,
 *   billing — and a registry rejects the whole registration over one
 *   missing field in any of them. The request is validated before the call
 *   rather than after the rejection.
 *
 * One behaviour is worth calling out because it will surprise somebody:
 * Namecheap frequently **emails** the EPP transfer code to the registrant
 * rather than returning it. `requestTransferCode` then fails, honestly, and
 * the customer is told to check their inbox — which is better than showing
 * them a blank field and letting them conclude the platform is broken.
 *
 * It has never talked to Namecheap. Its request shapes, error handling and
 * idempotency are tested against faked HTTP, which proves the code and not
 * the integration.
 */
final readonly class NamecheapRegistrar implements DomainRegistrar
{
    private const string PRODUCTION = 'https://api.namecheap.com/xml.response';

    private const string SANDBOX = 'https://api.sandbox.namecheap.com/xml.response';

    public function __construct(
        private RegistrarAccount $account,
        private int $timeout = 30,
        private int $retries = 1,
    ) {}

    public function key(): string
    {
        return 'namecheap';
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
            $response = $this->call('namecheap.domains.check', ['DomainList' => (string) $name]);
        } catch (Throwable $exception) {
            // Not available. Not taken either — nobody was asked.
            return AvailabilityResult::unknown($name, $exception->getMessage());
        }

        $xml = $this->parse($response);

        if ($xml === null || $this->errorIn($xml) !== null) {
            return AvailabilityResult::unknown($name, $this->errorIn($xml) ?? $this->unparseable());
        }

        $result = $xml->CommandResponse->DomainCheckResult ?? null;

        if ($result === null) {
            return AvailabilityResult::unknown($name, $this->unparseable());
        }

        $available = ((string) $result['Available']) === 'true';
        $premium = ((string) ($result['IsPremiumName'] ?? 'false')) === 'true';

        return $available
            ? AvailabilityResult::available($name, $premium)
            : AvailabilityResult::taken($name);
    }

    public function register(RegistrationRequest $request): RegistrarResult
    {
        if (! $request->registrant->isComplete()) {
            // A registry rejects the whole registration over one missing
            // field, with a numeric code nobody can act on.
            return RegistrarResult::failed((string) __('domains.errors.registrant_incomplete'));
        }

        $parameters = [
            'DomainName' => (string) $request->name,
            'Years' => $request->years,
            'AddFreeWhoisguard' => $request->whoisPrivacy ? 'yes' : 'no',
            'WGEnabled' => $request->whoisPrivacy ? 'yes' : 'no',
            ...$this->contactParameters($request->registrant),
            ...$this->nameserverParameters($request->nameservers),
        ];

        try {
            $response = $this->call('namecheap.domains.create', $parameters);
        } catch (Throwable $exception) {
            return RegistrarResult::failed($exception->getMessage());
        }

        $xml = $this->parse($response);
        $error = $xml === null ? $this->unparseable() : $this->errorIn($xml);

        if ($error !== null) {
            // The name is already in this account. That is the state the
            // caller was trying to reach, so a retried job succeeds rather
            // than failing forever.
            return $this->meansAlreadyRegistered($error)
                ? RegistrarResult::alreadyDone((string) $request->name, $error)
                : RegistrarResult::failed($error);
        }

        return RegistrarResult::succeeded(
            externalId: (string) $request->name,
            expiresOn: $this->expiryFrom($xml, 'DomainCreateResult'),
            nameservers: $request->nameservers,
        );
    }

    public function transfer(TransferRequest $request): RegistrarResult
    {
        try {
            $response = $this->call('namecheap.domains.transfer.create', [
                'DomainName' => (string) $request->name,
                'Years' => $request->years,
                'EPPCode' => $request->authCode,
            ]);
        } catch (Throwable $exception) {
            return RegistrarResult::failed($exception->getMessage());
        }

        $xml = $this->parse($response);
        $error = $xml === null ? $this->unparseable() : $this->errorIn($xml);

        if ($error !== null) {
            return RegistrarResult::failed($error);
        }

        return RegistrarResult::succeeded(externalId: (string) $request->name);
    }

    public function renew(DomainReference $domain, int $years): RegistrarResult
    {
        try {
            $response = $this->call('namecheap.domains.renew', [
                'DomainName' => (string) $domain->name,
                'Years' => $years,
            ]);
        } catch (Throwable $exception) {
            return RegistrarResult::failed($exception->getMessage());
        }

        $xml = $this->parse($response);
        $error = $xml === null ? $this->unparseable() : $this->errorIn($xml);

        return $error === null
            ? RegistrarResult::succeeded(
                externalId: $domain->externalId,
                expiresOn: $this->expiryFrom($xml, 'DomainRenewResult'),
            )
            : RegistrarResult::failed($error);
    }

    public function setNameservers(DomainReference $domain, array $nameservers): RegistrarResult
    {
        if ($nameservers === []) {
            return RegistrarResult::failed((string) __('domains.errors.nameservers_required'));
        }

        try {
            $response = $this->call('namecheap.domains.dns.setCustom', [
                'SLD' => $domain->name->sld,
                'TLD' => $domain->name->tld,
                'Nameservers' => implode(',', $nameservers),
            ]);
        } catch (Throwable $exception) {
            return RegistrarResult::failed($exception->getMessage());
        }

        $xml = $this->parse($response);
        $error = $xml === null ? $this->unparseable() : $this->errorIn($xml);

        return $error === null
            ? RegistrarResult::succeeded(externalId: $domain->externalId, nameservers: $nameservers)
            : RegistrarResult::failed($error);
    }

    public function setLock(DomainReference $domain, bool $locked): RegistrarResult
    {
        return $this->simple('namecheap.domains.setRegistrarLock', $domain, [
            'DomainName' => (string) $domain->name,
            'LockAction' => $locked ? 'LOCK' : 'UNLOCK',
        ]);
    }

    public function setAutoRenew(DomainReference $domain, bool $enabled): RegistrarResult
    {
        return $this->simple('namecheap.domains.reactivate', $domain, [
            'DomainName' => (string) $domain->name,
            'IsAutoRenew' => $enabled ? 'true' : 'false',
        ]);
    }

    public function requestTransferCode(DomainReference $domain): RegistrarResult
    {
        try {
            $response = $this->call('namecheap.domains.getInfo', [
                'DomainName' => (string) $domain->name,
            ]);
        } catch (Throwable $exception) {
            return RegistrarResult::failed($exception->getMessage());
        }

        $xml = $this->parse($response);
        $error = $xml === null ? $this->unparseable() : $this->errorIn($xml);

        if ($error !== null) {
            return RegistrarResult::failed($error);
        }

        $result = $xml->CommandResponse->DomainGetInfoResult ?? null;

        // Looked for in both places the response has carried it. Namecheap
        // often emails the code to the registrant instead of returning it,
        // in which case this legitimately fails and the customer is told
        // to check their inbox — which is better than a blank field.
        $code = trim((string) (
            $result->DomainDetails->EppCode
            ?? $result->EppCode
            ?? ''
        ));

        if ($code === '') {
            return RegistrarResult::failed((string) __('domains.errors.no_transfer_code'));
        }

        // Returned, shown once, and never written down.
        return RegistrarResult::code($code);
    }

    public function sync(DomainReference $domain): DomainSyncResult
    {
        try {
            $response = $this->call('namecheap.domains.getInfo', [
                'DomainName' => (string) $domain->name,
            ]);
        } catch (Throwable $exception) {
            return DomainSyncResult::unreachable($exception->getMessage());
        }

        $xml = $this->parse($response);
        $error = $xml === null ? $this->unparseable() : $this->errorIn($xml);

        if ($error !== null) {
            return DomainSyncResult::unreachable($error);
        }

        $result = $xml->CommandResponse->DomainGetInfoResult ?? null;

        if ($result === null) {
            return DomainSyncResult::unreachable($this->unparseable());
        }

        $nameservers = [];

        foreach ($result->DnsDetails->Nameserver ?? [] as $nameserver) {
            $nameservers[] = (string) $nameserver;
        }

        return new DomainSyncResult(
            reachable: true,
            remoteStatus: $this->statusOf((string) ($result['Status'] ?? '')),
            expiresOn: $this->dateOf((string) ($result->DomainDetails->ExpiredDate ?? '')),
            nameservers: $nameservers,
            locked: ((string) ($result->LockDetails['RegistrarLock'] ?? '')) === 'true',
            autoRenew: ((string) ($result['IsExpired'] ?? '')) !== 'true',
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function simple(string $command, DomainReference $domain, array $parameters): RegistrarResult
    {
        try {
            $response = $this->call($command, $parameters);
        } catch (Throwable $exception) {
            return RegistrarResult::failed($exception->getMessage());
        }

        $xml = $this->parse($response);
        $error = $xml === null ? $this->unparseable() : $this->errorIn($xml);

        return $error === null
            ? RegistrarResult::succeeded(externalId: $domain->externalId)
            : RegistrarResult::failed($error);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function call(string $command, array $parameters): Response
    {
        return $this->client()->get($this->endpoint(), [
            'ApiUser' => $this->account->username,
            'ApiKey' => $this->account->apiKey,
            'UserName' => $this->account->username,
            // Namecheap allow-lists the calling address and rejects a
            // request without it in a way that reads like bad credentials.
            'ClientIp' => $this->account->clientIp ?? '127.0.0.1',
            'Command' => $command,
            ...$parameters,
        ]);
    }

    private function client(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->retry($this->retries, 250, throw: false)
            ->acceptJson();
    }

    private function endpoint(): string
    {
        return $this->account->apiBase
            ?? ($this->account->sandbox ? self::SANDBOX : self::PRODUCTION);
    }

    /**
     * Parse defensively. A registrar that answers with an error page
     * instead of a document must produce a failed result, not an unhandled
     * warning inside a worker.
     */
    private function parse(Response $response): ?SimpleXMLElement
    {
        $body = trim($response->body());

        if ($body === '' || ! str_starts_with($body, '<')) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body);
        } catch (Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml === false ? null : $xml;
    }

    /**
     * Namecheap answers 200 and says no in the body.
     */
    private function errorIn(?SimpleXMLElement $xml): ?string
    {
        if ($xml === null) {
            return $this->unparseable();
        }

        if (((string) ($xml['Status'] ?? '')) !== 'ERROR') {
            return null;
        }

        $error = $xml->Errors->Error ?? null;
        $message = $error === null ? '' : trim((string) $error);

        return $message === '' ? (string) __('domains.errors.registrar_refused') : $message;
    }

    private function unparseable(): string
    {
        return (string) __('domains.errors.registrar_unreadable');
    }

    private function meansAlreadyRegistered(string $error): bool
    {
        return Str::contains(Str::lower($error), [
            'already exists',
            'domain is already',
            'unavailable',
            'not available for registration',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function contactParameters(RegistrantDetails $registrant): array
    {
        $base = [
            'FirstName' => $registrant->firstName,
            'LastName' => $registrant->lastName,
            'Address1' => (string) $registrant->addressLine,
            'City' => (string) $registrant->city,
            'StateProvince' => (string) ($registrant->region ?? $registrant->city),
            'PostalCode' => (string) $registrant->postalCode,
            'Country' => (string) $registrant->countryCode,
            'Phone' => (string) $registrant->phone,
            'EmailAddress' => $registrant->email,
        ];

        if ($registrant->organization !== null && $registrant->organization !== '') {
            $base['OrganizationName'] = $registrant->organization;
        }

        $parameters = [];

        // The same set four times. Namecheap requires all of them and
        // rejects the registration if any one is incomplete.
        foreach (['Registrant', 'Tech', 'Admin', 'AuxBilling'] as $role) {
            foreach ($base as $field => $value) {
                $parameters[$role.$field] = $value;
            }
        }

        return $parameters;
    }

    /**
     * @param  list<string>  $nameservers
     * @return array<string, string>
     */
    private function nameserverParameters(array $nameservers): array
    {
        return $nameservers === [] ? [] : ['Nameservers' => implode(',', $nameservers)];
    }

    /**
     * The new expiry, where the response carries one.
     *
     * A create does not report it and a renew does, under
     * `DomainDetails/ExpiredDate`. Returning null rather than guessing is
     * the point: a sync will fill it in, and an invented date on a domain
     * is worse than a missing one.
     */
    private function expiryFrom(?SimpleXMLElement $xml, string $node): ?string
    {
        if ($xml === null) {
            return null;
        }

        $result = $xml->CommandResponse->{$node} ?? null;

        if ($result === null) {
            return null;
        }

        return $this->dateOf((string) ($result->DomainDetails->ExpiredDate ?? ''));
    }

    /**
     * Namecheap writes dates as `MM/DD/YYYY`.
     */
    private function dateOf(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $matches) !== 1) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $matches[1], (int) $matches[2]);
    }

    private function statusOf(string $status): DomainStatus
    {
        return match (mb_strtolower($status)) {
            'expired' => DomainStatus::Expired,
            'redemption', 'pendingdelete' => DomainStatus::Redemption,
            'locked', 'ok', 'active' => DomainStatus::Active,
            default => DomainStatus::Active,
        };
    }
}
