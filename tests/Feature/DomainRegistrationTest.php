<?php

declare(strict_types=1);

use App\Application\Domains\CheckDomainAvailability;
use App\Application\Domains\CreateDomainsForOrder;
use App\Application\Domains\RunDomainOperation;
use App\Application\Domains\TldCatalog;
use App\Domain\Domains\DomainAction;
use App\Domain\Domains\DomainName;
use App\Domain\Domains\DomainOperation;
use App\Domain\Domains\DomainStatus;
use App\Domain\Domains\Exceptions\InvalidDomainName;
use App\Domain\Domains\RegistrarResult;
use App\Domain\Ordering\LineKind;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Provisioning\OperationOutcome;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Jobs\RegisterDomain;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Domains\Models\DomainEvent;
use App\Infrastructure\Domains\Models\Tld;
use App\Infrastructure\Domains\Models\TldPrice;
use App\Infrastructure\Domains\RegistrarRegistry;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use Database\Seeders\ProviderOrganizationSeeder;
use Tests\Support\FakeRegistrar;

beforeEach(function (): void {
    $this->seed(ProviderOrganizationSeeder::class);

    $this->registrar = new FakeRegistrar;

    $registry = new RegistrarRegistry;
    $registry->register($this->registrar);
    $this->app->instance(RegistrarRegistry::class, $registry);

    $this->tld = Tld::factory()->extension('com')->create(['registrar' => 'fake']);

    foreach ([DomainAction::Register, DomainAction::Renew, DomainAction::Transfer] as $action) {
        TldPrice::factory()->forTld($this->tld)->create([
            'action' => $action->value,
            'years' => 1,
            'currency_code' => 'EUR',
            'amount_minor' => $action === DomainAction::Register ? 1200 : 1400,
        ]);
    }

    $this->customer = Customer::factory()->create();
    Contact::factory()->forCustomer($this->customer)->primary()->create([
        'first_name' => 'Ayse',
        'last_name' => 'Yilmaz',
        'email' => 'ayse@example.test',
        'phone' => '+905551112233',
    ]);

    app(TldCatalog::class)->forget();
});

function orderWithDomain(Customer $customer, string $name = 'example.com', int $years = 1): Order
{
    $order = Order::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'status' => OrderStatus::Paid->value,
        'currency_code' => 'EUR',
    ]);

    OrderItem::factory()->create([
        'organization_id' => $order->organization_id,
        'order_id' => $order->id,
        'product_id' => null,
        'kind' => LineKind::Domain->value,
        'name' => $name,
        'billing_cycle' => null,
        'domain' => $name,
        'domain_years' => $years,
        'line_recurring_minor' => 0,
        'line_total_minor' => 1200,
    ]);

    return $order;
}

it('splits a name against the extensions actually on sale', function (): void {
    Tld::factory()->extension('co.uk')->create(['registrar' => 'fake']);
    app(TldCatalog::class)->forget();

    $name = app(TldCatalog::class)->parse('https://www.shop.co.uk/basket');

    // `co.uk` is two labels and `uk` is one. Counting dots gets this wrong.
    expect($name->tld)->toBe('co.uk')
        ->and($name->sld)->toBe('shop')
        ->and((string) $name)->toBe('shop.co.uk');
});

it('offers a name the registry says is free, at the price in the matrix', function (): void {
    $offers = app(CheckDomainAvailability::class)->handle('example.com', 'EUR');

    expect($offers)->toHaveCount(1)
        ->and($offers[0]->isOrderable())->toBeTrue()
        ->and($offers[0]->price?->minorUnits)->toBe(1200);
});

it('offers a transfer for a name somebody else owns', function (): void {
    $this->registrar->taken = ['example.com'];

    $offers = app(CheckDomainAvailability::class)->handle('example.com', 'EUR');

    expect($offers[0]->isOrderable())->toBeFalse()
        ->and($offers[0]->isTransferable())->toBeTrue()
        ->and($offers[0]->transferPrice?->minorUnits)->toBe(1400);
});

it('never reads a silent registry as available', function (): void {
    $this->registrar->registryDown = true;

    $offers = app(CheckDomainAvailability::class)->handle('example.com', 'EUR');

    // This is the one that matters. A registry that did not answer has not
    // said the name is free.
    expect($offers[0]->isUnknown())->toBeTrue()
        ->and($offers[0]->isOrderable())->toBeFalse()
        ->and($offers[0]->isTransferable())->toBeFalse();
});

it('treats a name this installation already holds as taken', function (): void {
    Domain::factory()->forCustomer($this->customer)->named('example', 'com')->active()->create();

    $offers = app(CheckDomainAvailability::class)->handle('example.com', 'EUR');

    expect($offers[0]->isOrderable())->toBeFalse()
        // Answered from our own records, so it is right even with the
        // registrar down, and costs nothing.
        ->and($this->registrar->callsTo('check_availability'))->toBe(0);
});

it('does not sell a term the operator has not priced', function (): void {
    $offers = app(CheckDomainAvailability::class)->handle('example.com', 'EUR', 3);

    expect($offers[0]->sold)->toBeFalse()
        ->and($offers[0]->price)->toBeNull()
        // Not searched for either: a registry call for a name nobody can
        // buy is a call wasted.
        ->and($this->registrar->callsTo('check_availability'))->toBe(0);
});

it('does not sell an extension that has no price in the asked currency', function (): void {
    $offers = app(CheckDomainAvailability::class)->handle('example.com', 'TRY');

    expect($offers[0]->sold)->toBeFalse();
});

it('creates a domain from a paid order, copying the name', function (): void {
    $order = orderWithDomain($this->customer, 'example.com');

    $domains = app(CreateDomainsForOrder::class)->handle($order);

    expect($domains)->toHaveCount(1);

    $domain = $domains[0];

    // The TLD is retired afterwards; the domain keeps its name.
    $this->tld->update(['extension' => 'renamed']);

    expect($domain->fresh()?->name)->toBe('example.com')
        ->and($domain->sld ?? $domain->label)->toBe('example')
        ->and($domain->extension)->toBe('com')
        ->and($domain->status)->toBe(DomainStatus::Pending)
        ->and($domain->registrar)->toBe('fake')
        // The renewal price at the time of purchase, which Phase 9 needs
        // to decide whether to honour it.
        ->and($domain->renewal->minorUnits)->toBe(1400);
});

it('creates one domain however many times the order is paid', function (): void {
    $order = orderWithDomain($this->customer, 'example.com');

    app(CreateDomainsForOrder::class)->handle($order);
    app(CreateDomainsForOrder::class)->handle($order->fresh() ?? $order);

    expect(Domain::query()->withoutGlobalScope('organization')->count())->toBe(1);
});

it('registers the domain and stores what the registrar called it', function (): void {
    $order = orderWithDomain($this->customer, 'example.com');
    $domain = app(CreateDomainsForOrder::class)->handle($order)[0];

    $result = app(RunDomainOperation::class)->register($domain);

    $domain->refresh();

    expect($result->outcome)->toBe(OperationOutcome::Succeeded)
        ->and($domain->status)->toBe(DomainStatus::Active)
        ->and($domain->external_id)->toBe('example.com')
        ->and($domain->expires_on)->not->toBeNull()
        ->and($domain->registered_on)->not->toBeNull();
});

it('sends the registry a complete registrant', function (): void {
    $order = orderWithDomain($this->customer, 'example.com');
    $domain = app(CreateDomainsForOrder::class)->handle($order)[0];

    app(RunDomainOperation::class)->register($domain);

    $request = $this->registrar->registrations[0];

    expect($request->registrant->fullName())->toBe('Ayse Yilmaz')
        ->and($request->registrant->email)->toBe('ayse@example.test')
        ->and($request->years)->toBe(1);
});

it('treats a name already in the account as registered', function (): void {
    $order = orderWithDomain($this->customer, 'example.com');
    $domain = app(CreateDomainsForOrder::class)->handle($order)[0];

    // The job timed out after the registry had taken the money.
    $this->registrar->willReturn(RegistrarResult::alreadyDone('example.com'));

    $result = app(RunDomainOperation::class)->register($domain);

    expect($result->outcome)->toBe(OperationOutcome::AlreadyDone)
        ->and($domain->fresh()?->status)->toBe(DomainStatus::Active);
});

it('leaves a failed registration in failed, with the reason', function (): void {
    $order = orderWithDomain($this->customer, 'example.com');
    $domain = app(CreateDomainsForOrder::class)->handle($order)[0];

    $this->registrar->willReturn(RegistrarResult::failed('Registry rejected the contact'));

    app(RunDomainOperation::class)->register($domain);

    expect($domain->fresh()?->status)->toBe(DomainStatus::Failed)
        ->and($domain->fresh()?->failure_reason)->toContain('Registry rejected');
});

it('does not register a domain twice', function (): void {
    $order = orderWithDomain($this->customer, 'example.com');
    $domain = app(CreateDomainsForOrder::class)->handle($order)[0];

    app(RunDomainOperation::class)->register($domain);

    // A job that ran twice, or a webhook redelivered an hour later.
    // Registering again costs real money at a registry.
    dispatch_sync(new RegisterDomain($domain->id));

    expect($this->registrar->callsTo('register'))->toBe(1);
});

it('fetches a transfer code and never writes it down', function (): void {
    $domain = Domain::factory()->forCustomer($this->customer)->forTld($this->tld)->active()->create();

    $result = app(RunDomainOperation::class)->requestTransferCode($domain);

    expect($result->transferCode)->toBe('EPP-SECRET-CODE');

    // The event records that somebody asked. Never the value — and
    // neither does the domain row.
    $event = DomainEvent::query()->withoutGlobalScope('organization')->sole();

    expect($event->operation)->toBe(DomainOperation::RequestTransferCode)
        ->and(json_encode($event->getAttributes()))->not->toContain('EPP-SECRET-CODE')
        ->and(json_encode($domain->fresh()?->getAttributes()))->not->toContain('EPP-SECRET-CODE');
});

it('changes nameservers through the registrar, not just in the database', function (): void {
    $domain = Domain::factory()->forCustomer($this->customer)->forTld($this->tld)->active()->create();

    app(RunDomainOperation::class)->setNameservers($domain, ['ns1.new.test', 'ns2.new.test']);

    expect($this->registrar->callsTo('set_nameservers'))->toBe(1)
        ->and($domain->fresh()?->nameservers)->toBe(['ns1.new.test', 'ns2.new.test']);
});

it('leaves a domain active when a nameserver change fails', function (): void {
    $domain = Domain::factory()->forCustomer($this->customer)->forTld($this->tld)->active()->create();
    $before = $domain->nameservers;

    $this->registrar->willReturn(RegistrarResult::failed('Nameserver not registered at the registry'));

    app(RunDomainOperation::class)->setNameservers($domain, ['broken.test']);

    // The domain still works. Saying otherwise would be a lie an operator
    // acts on.
    expect($domain->fresh()?->status)->toBe(DomainStatus::Active)
        ->and($domain->fresh()?->nameservers)->toBe($before);
});

it('extends the expiry when a domain is renewed', function (): void {
    $domain = Domain::factory()->forCustomer($this->customer)->forTld($this->tld)->active(30)->create();

    app(RunDomainOperation::class)->renew($domain, 1);

    expect($domain->fresh()?->expires_on?->isAfter(now()->addMonths(6)))->toBeTrue();
});

it('records a name whose extension is no longer sold, rather than dropping it', function (): void {
    $order = orderWithDomain($this->customer, 'example.zzz');

    $domains = app(CreateDomainsForOrder::class)->handle($order);

    // It was paid for, so it exists. No registrar, so nothing tries to
    // register it automatically: an operator decides.
    expect($domains)->toHaveCount(1)
        ->and($domains[0]->name)->toBe('example.zzz')
        ->and($domains[0]->registrar)->toBeNull();
});

it('refuses a name that would be a subdomain', function (): void {
    expect(fn (): DomainName => app(TldCatalog::class)->parse('shop.example.com'))
        ->toThrow(InvalidDomainName::class);
});
