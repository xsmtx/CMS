<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Domains\AvailabilityResult;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Domains\DomainName;
use App\Domain\Domains\DomainReference;
use App\Domain\Domains\DomainStatus;
use App\Domain\Domains\DomainSyncResult;
use App\Domain\Domains\RegistrarCapabilities;
use App\Domain\Domains\RegistrarResult;
use App\Domain\Domains\RegistrationRequest;
use App\Domain\Domains\TransferRequest;
use RuntimeException;

/**
 * A registrar that answers what the test tells it to.
 *
 * Counts its calls, so "did this register twice" is an assertion rather
 * than an inference, and can be told a name is taken, that the registry did
 * not answer, or to fail outright — the three shapes that decide whether
 * the platform behaves honestly.
 */
final class FakeRegistrar implements DomainRegistrar
{
    /** @var array<string, int> */
    public array $calls = [];

    /** @var list<RegistrationRequest> */
    public array $registrations = [];

    /** @var list<string> */
    public array $taken = [];

    public bool $registryDown = false;

    public function __construct(
        private readonly string $key = 'fake',
        private ?RegistrarResult $nextResult = null,
        private readonly ?string $throwMessage = null,
    ) {}

    public function willReturn(RegistrarResult $result): void
    {
        $this->nextResult = $result;
    }

    public function key(): string
    {
        return $this->key;
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
        $this->record('check_availability');

        if ($this->registryDown) {
            return AvailabilityResult::unknown($name, 'The registry did not answer.');
        }

        return in_array((string) $name, $this->taken, strict: true)
            ? AvailabilityResult::taken($name)
            : AvailabilityResult::available($name);
    }

    public function register(RegistrationRequest $request): RegistrarResult
    {
        $this->record('register');
        $this->registrations[] = $request;

        return $this->answer(fn (): RegistrarResult => RegistrarResult::succeeded(
            externalId: (string) $request->name,
            expiresOn: now()->addYears($request->years)->toDateString(),
            nameservers: $request->nameservers,
        ));
    }

    public function transfer(TransferRequest $request): RegistrarResult
    {
        $this->record('transfer');

        return $this->answer(fn (): RegistrarResult => RegistrarResult::succeeded(
            externalId: (string) $request->name,
        ));
    }

    public function renew(DomainReference $domain, int $years): RegistrarResult
    {
        $this->record('renew');

        return $this->answer(fn (): RegistrarResult => RegistrarResult::succeeded(
            externalId: $domain->externalId,
            expiresOn: now()->addYears($years + 1)->toDateString(),
        ));
    }

    public function setNameservers(DomainReference $domain, array $nameservers): RegistrarResult
    {
        $this->record('set_nameservers');

        return $this->answer(fn (): RegistrarResult => RegistrarResult::succeeded(
            nameservers: $nameservers,
        ));
    }

    public function setLock(DomainReference $domain, bool $locked): RegistrarResult
    {
        $this->record('set_lock');

        return $this->answer(fn (): RegistrarResult => RegistrarResult::succeeded());
    }

    public function setAutoRenew(DomainReference $domain, bool $enabled): RegistrarResult
    {
        $this->record('set_auto_renew');

        return $this->answer(fn (): RegistrarResult => RegistrarResult::succeeded());
    }

    public function requestTransferCode(DomainReference $domain): RegistrarResult
    {
        $this->record('request_transfer_code');

        return $this->answer(fn (): RegistrarResult => RegistrarResult::code('EPP-SECRET-CODE'));
    }

    public function sync(DomainReference $domain): DomainSyncResult
    {
        $this->record('sync');

        if ($this->throwMessage !== null) {
            throw new RuntimeException($this->throwMessage);
        }

        return new DomainSyncResult(
            reachable: true,
            remoteStatus: DomainStatus::Active,
            expiresOn: now()->addYear()->toDateString(),
            nameservers: ['ns1.fake.test', 'ns2.fake.test'],
            locked: true,
            autoRenew: true,
        );
    }

    public function callsTo(string $operation): int
    {
        return $this->calls[$operation] ?? 0;
    }

    /**
     * @param  callable(): RegistrarResult  $default
     */
    private function answer(callable $default): RegistrarResult
    {
        if ($this->throwMessage !== null) {
            throw new RuntimeException($this->throwMessage);
        }

        return $this->nextResult ?? $default();
    }

    private function record(string $operation): void
    {
        $this->calls[$operation] = ($this->calls[$operation] ?? 0) + 1;
    }
}
