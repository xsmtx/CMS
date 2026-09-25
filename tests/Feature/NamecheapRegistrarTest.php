<?php

declare(strict_types=1);

use App\Domain\Domains\DomainName;
use App\Domain\Domains\DomainReference;
use App\Domain\Domains\DomainStatus;
use App\Domain\Domains\RegistrantDetails;
use App\Domain\Domains\RegistrarAccount;
use App\Domain\Domains\RegistrationRequest;
use App\Domain\Provisioning\OperationOutcome;
use Illuminate\Support\Facades\Http;
use InfraCMS\RegistrarNamecheap\NamecheapRegistrar;

/**
 * Tested against faked HTTP, which proves the code and not the
 * integration. It has never talked to Namecheap.
 */
beforeEach(function (): void {
    // The adapter lives in a package now; this puts its classes on the
    // autoloader without installing or enabling anything.
    loadModuleClasses('registrar-namecheap');

    $this->registrar = new NamecheapRegistrar(
        account: new RegistrarAccount(
            username: 'reseller',
            apiKey: 'nc-api-key-value',
            clientIp: '203.0.113.7',
            apiBase: 'https://api.namecheap.test/xml.response',
        ),
        timeout: 5,
        retries: 0,
    );

    $this->name = DomainName::of('example', 'com');

    $this->reference = new DomainReference('dom_1', $this->name, 'example.com');

    $this->registrant = new RegistrantDetails(
        firstName: 'Ayse',
        lastName: 'Yilmaz',
        email: 'ayse@example.test',
        organization: 'Northwind',
        phone: '+90.5551112233',
        addressLine: 'Bagdat Caddesi 1',
        city: 'Istanbul',
        region: 'Istanbul',
        postalCode: '34710',
        countryCode: 'TR',
    );
});

function namecheap(string $body, int $status = 200): void
{
    Http::fake(['api.namecheap.test/*' => Http::response($body, $status)]);
}

function namecheapOk(string $inner): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<ApiResponse Status="OK"><CommandResponse>'.$inner.'</CommandResponse></ApiResponse>';
}

function namecheapError(string $message): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        .'<ApiResponse Status="ERROR"><Errors><Error Number="2019166">'
        .$message.'</Error></Errors></ApiResponse>';
}

it('reads availability out of the body', function (): void {
    namecheap(namecheapOk('<DomainCheckResult Domain="example.com" Available="true" IsPremiumName="false" />'));

    $result = $this->registrar->checkAvailability($this->name);

    expect($result->isAvailable())->toBeTrue()
        ->and($result->known)->toBeTrue();
});

it('reads a taken name as taken, not as unknown', function (): void {
    namecheap(namecheapOk('<DomainCheckResult Domain="example.com" Available="false" />'));

    $result = $this->registrar->checkAvailability($this->name);

    expect($result->known)->toBeTrue()
        ->and($result->available)->toBeFalse();
});

it('reads an error as unknown rather than as available', function (): void {
    // The single most important behaviour here: a registry that refused to
    // answer has not said the name is free.
    namecheap(namecheapError('Invalid request IP'));

    $result = $this->registrar->checkAvailability($this->name);

    expect($result->known)->toBeFalse()
        ->and($result->isAvailable())->toBeFalse();
});

it('reads an unparseable answer as unknown', function (): void {
    namecheap('<html><body>502 Bad Gateway</body></html>');

    expect($this->registrar->checkAvailability($this->name)->known)->toBeFalse();
});

it('reads a network failure as unknown', function (): void {
    Http::fake(fn () => throw new RuntimeException('Connection timed out'));

    $result = $this->registrar->checkAvailability($this->name);

    expect($result->known)->toBeFalse()
        ->and($result->message)->toContain('Connection timed out');
});

it('sends credentials and the allow-listed address on every call', function (): void {
    namecheap(namecheapOk('<DomainCheckResult Domain="example.com" Available="true" />'));

    $this->registrar->checkAvailability($this->name);

    Http::assertSent(fn ($request): bool => $request['ApiUser'] === 'reseller'
        && $request['ApiKey'] === 'nc-api-key-value'
        // A missing ClientIp fails in a way that reads like bad credentials.
        && $request['ClientIp'] === '203.0.113.7'
        && $request['Command'] === 'namecheap.domains.check');
});

it('registers a name with the whole contact set', function (): void {
    namecheap(namecheapOk('<DomainCreateResult Domain="example.com" Registered="true" />'));

    $result = $this->registrar->register(new RegistrationRequest(
        domainId: 'dom_1',
        name: $this->name,
        years: 2,
        registrant: $this->registrant,
        nameservers: ['ns1.example.test', 'ns2.example.test'],
    ));

    expect($result->outcome)->toBe(OperationOutcome::Succeeded)
        ->and($result->externalId)->toBe('example.com');

    // A registry rejects the whole registration over one missing field in
    // any of the four contact sets.
    Http::assertSent(fn ($request): bool => $request['Years'] === 2
        && $request['RegistrantFirstName'] === 'Ayse'
        && $request['TechFirstName'] === 'Ayse'
        && $request['AdminEmailAddress'] === 'ayse@example.test'
        && $request['AuxBillingCountry'] === 'TR'
        && $request['Nameservers'] === 'ns1.example.test,ns2.example.test');
});

it('refuses an incomplete registrant before spending a call', function (): void {
    $result = $this->registrar->register(new RegistrationRequest(
        domainId: 'dom_1',
        name: $this->name,
        years: 1,
        registrant: new RegistrantDetails('Ayse', 'Yilmaz', 'ayse@example.test'),
    ));

    expect($result->outcome)->toBe(OperationOutcome::Failed);

    Http::assertNothingSent();
});

it('treats a name already in the account as already registered', function (): void {
    namecheap(namecheapError('Domain is already registered in your account'));

    $result = $this->registrar->register(new RegistrationRequest(
        domainId: 'dom_1',
        name: $this->name,
        years: 1,
        registrant: $this->registrant,
    ));

    // A retried job must reach the same place rather than failing forever.
    expect($result->outcome)->toBe(OperationOutcome::AlreadyDone);
});

it('reads a refusal out of the body although the status code is 200', function (): void {
    namecheap(namecheapError('Insufficient funds in your account'));

    $result = $this->registrar->register(new RegistrationRequest(
        domainId: 'dom_1',
        name: $this->name,
        years: 1,
        registrant: $this->registrant,
    ));

    expect($result->outcome)->toBe(OperationOutcome::Failed)
        ->and($result->message)->toBe('Insufficient funds in your account');
});

it('renews and reads the new expiry', function (): void {
    namecheap(namecheapOk(
        '<DomainRenewResult DomainName="example.com" Renew="true">'
        .'<DomainDetails><ExpiredDate>03/14/2029</ExpiredDate></DomainDetails>'
        .'</DomainRenewResult>',
    ));

    $result = $this->registrar->renew($this->reference, 1);

    // Namecheap writes dates as MM/DD/YYYY.
    expect($result->isSuccessful())->toBeTrue()
        ->and($result->expiresOn)->toBe('2029-03-14');
});

it('sets nameservers by second-level and top-level label', function (): void {
    namecheap(namecheapOk('<DomainDNSSetCustomResult Domain="example.com" Update="true" />'));

    $this->registrar->setNameservers($this->reference, ['ns1.new.test', 'ns2.new.test']);

    Http::assertSent(fn ($request): bool => $request['SLD'] === 'example'
        && $request['TLD'] === 'com'
        && $request['Nameservers'] === 'ns1.new.test,ns2.new.test');
});

it('refuses to clear every nameserver', function (): void {
    expect($this->registrar->setNameservers($this->reference, [])->outcome)
        ->toBe(OperationOutcome::Failed);

    Http::assertNothingSent();
});

it('fetches a transfer code and returns it without writing it anywhere', function (): void {
    namecheap(namecheapOk(
        '<DomainGetInfoResult Status="Ok"><DomainDetails><ExpiredDate>03/14/2029</ExpiredDate>'
        .'</DomainDetails><EppCode>SECRET-EPP</EppCode></DomainGetInfoResult>',
    ));

    $result = $this->registrar->requestTransferCode($this->reference);

    expect($result->transferCode)->toBe('SECRET-EPP')
        // Not printable, whatever prints the result.
        ->and(print_r($result, true))->not->toContain('SECRET-EPP');
});

it('reads the registry state back from a sync', function (): void {
    namecheap(namecheapOk(
        '<DomainGetInfoResult Status="Expired" IsExpired="true">'
        .'<DomainDetails><ExpiredDate>01/02/2026</ExpiredDate></DomainDetails>'
        .'<LockDetails RegistrarLock="true" />'
        .'<DnsDetails><Nameserver>ns1.registry.test</Nameserver>'
        .'<Nameserver>ns2.registry.test</Nameserver></DnsDetails>'
        .'</DomainGetInfoResult>',
    ));

    $sync = $this->registrar->sync($this->reference);

    expect($sync->reachable)->toBeTrue()
        ->and($sync->remoteStatus)->toBe(DomainStatus::Expired)
        ->and($sync->expiresOn)->toBe('2026-01-02')
        ->and($sync->nameservers)->toBe(['ns1.registry.test', 'ns2.registry.test'])
        ->and($sync->locked)->toBeTrue();
});

it('never prints the API key, whatever prints the account', function (): void {
    $printed = print_r(new RegistrarAccount('reseller', 'nc-api-key-value'), true);

    expect($printed)->not->toContain('nc-api-key-value')
        ->and($printed)->toContain('[redacted]');
});
