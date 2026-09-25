<?php

declare(strict_types=1);

use App\Application\Domains\TldCatalog;
use App\Domain\Domains\DomainAction;
use App\Domain\Ordering\LineKind;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Domains\Models\Tld;
use App\Infrastructure\Domains\Models\TldPrice;
use App\Infrastructure\Domains\RegistrarRegistry;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use Database\Seeders\ProviderOrganizationSeeder;
use Tests\Support\FakeRegistrar;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);

    CurrencyRecord::factory()->create([
        'code' => 'EUR',
        'is_base' => true,
        'is_active' => true,
        'rate' => '1.000000',
    ]);

    $this->registrar = new FakeRegistrar;
    $registry = new RegistrarRegistry;
    $registry->register($this->registrar);
    $this->app->instance(RegistrarRegistry::class, $registry);

    $this->tld = Tld::factory()->extension('com')->create(['registrar' => 'fake']);

    foreach ([DomainAction::Register, DomainAction::Transfer] as $action) {
        TldPrice::factory()->forTld($this->tld)->create([
            'action' => $action->value,
            'years' => 1,
            'currency_code' => 'EUR',
            'amount_minor' => 1200,
        ]);
    }

    app(TldCatalog::class)->forget();
});

it('shows the search box with the extensions on sale', function (): void {
    $this->get('/domains')
        ->assertOk()
        ->assertSee('.com');
});

it('says a free name is available, with its price', function (): void {
    $this->get('/domains?q=example.com')
        ->assertOk()
        ->assertSee('example.com')
        ->assertSee(__('domains.search.add'));
});

it('says a taken name is taken', function (): void {
    $this->registrar->taken = ['example.com'];

    $this->get('/domains?q=example.com')
        ->assertOk()
        ->assertSee(__('domains.search.taken', ['name' => 'example.com']), false)
        ->assertDontSee(__('domains.search.available', ['name' => 'example.com']), false);
});

it('never turns a silent registry into an offer', function (): void {
    $this->registrar->registryDown = true;

    // The most expensive bug this feature can have: the customer pays, the
    // registration fails, and somebody has to explain it.
    $this->get('/domains?q=example.com')
        ->assertOk()
        ->assertSee(__('domains.search.unknown_hint'))
        ->assertDontSee(__('domains.search.add'));
});

it('refuses a name whose extension is not on sale', function (): void {
    $this->get('/domains?q=example.zzz')
        ->assertOk()
        ->assertSee(__('domains.errors.unsupported_tld'));
});

it('adds a name to the cart at the price on the board', function (): void {
    $this->post('/domains', ['domain' => 'example.com', 'years' => 1])
        ->assertRedirect('/cart');

    $cart = Cart::query()->withoutGlobalScope('organization')->sole();
    $item = $cart->allItems()->sole();

    expect($item->kind)->toBe(LineKind::Domain)
        ->and($item->domain)->toBe('example.com')
        ->and($item->domain_years)->toBe(1)
        // Copied now, so an operator repricing the matrix does not change
        // what this customer was shown.
        ->and((int) $item->getAttribute('domain_registration_minor'))->toBe(1200);
});

it('adds the same name once however many times it is clicked', function (): void {
    $this->post('/domains', ['domain' => 'example.com', 'years' => 1]);
    $this->post('/domains', ['domain' => 'example.com', 'years' => 1]);

    $cart = Cart::query()->withoutGlobalScope('organization')->sole();

    expect($cart->allItems()->count())->toBe(1);
});

it('refuses a term the registry will not accept', function (): void {
    $this->tld->update(['max_years' => 1]);
    app(TldCatalog::class)->forget();

    $this->post('/domains', ['domain' => 'example.com', 'years' => 5])
        ->assertRedirect()
        ->assertSessionHas('error');

    // A cart row exists — it is created before the line is priced — but
    // nothing was added to it.
    $cart = Cart::query()->withoutGlobalScope('organization')->first();

    expect($cart?->allItems()->count() ?? 0)->toBe(0);
});

it('refuses a name this installation already holds', function (): void {
    Domain::factory()->named('example', 'com')->active()->create();

    $this->post('/domains', ['domain' => 'example.com', 'years' => 1])
        ->assertRedirect()
        ->assertSessionHas('error');
});

/**
 * A shop that sells domains links to the search.
 *
 * The storefront header offered Plans, the cart and the client area, and
 * nothing else: domain search and the knowledge base were reachable only by
 * typing the URL. Listed the way the admin rail lists, so an installation
 * with no TLDs on sale has no Domains link rather than a link to "no
 * extensions are on sale yet".
 */
it('links the domain search from every public page', function (): void {
    $this->get('/')->assertOk()->assertSee(route('storefront.domains'), false);
});

it('leaves the link out when nothing is on sale', function (): void {
    TldPrice::query()->withoutGlobalScope('organization')->delete();
    Tld::query()->withoutGlobalScope('organization')->delete();
    app(TldCatalog::class)->forget();

    $this->get('/')->assertOk()->assertDontSee(route('storefront.domains'), false);
});
