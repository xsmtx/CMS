<?php

declare(strict_types=1);

use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\ProductType;
use App\Domain\Support\ArticleVisibility;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Infrastructure\Catalog\Models\ProductPrice;
use App\Infrastructure\Content\Models\KbArticle;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Promotions\Models\Promotion;
use App\Infrastructure\Shared\Models\CurrencyRecord;
use Database\Seeders\ProviderOrganizationSeeder;

/**
 * The two storefront buttons nothing had driven: taking a discount code off a
 * cart, and rating a help article.
 *
 * Small, and the first one is not: a code that cannot be removed is a customer
 * stuck with a total they did not intend, on the last screen before they pay.
 */
beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);

    $this->provider = Organization::query()
        ->withoutGlobalScope('organization')
        ->whereNull('parent_id')
        ->sole();

    CurrencyRecord::factory()
        ->forOrganization($this->provider->id)
        ->base()
        ->code('EUR', 'Euro')
        ->create();
});

/**
 * A product on sale, priced. Named differently from the catalog suite's helper
 * of the same shape: two Pest files sharing a global function name is one
 * definition and a fatal error the day both run.
 */
function saleableProduct(string $name = 'Starter', int $minor = 999): Product
{
    /** @var Organization $provider */
    $provider = Organization::query()
        ->withoutGlobalScope('organization')
        ->whereNull('parent_id')
        ->sole();

    $group = ProductGroup::factory()->forOrganization($provider->id)->create(['name' => 'Shared Hosting']);
    // A VPS rather than shared hosting: a hosting product asks for a domain
    // name, and this test is about a discount code rather than about the
    // domain-in-cart flow.
    $product = Product::factory()->inGroup($group)->ofType(ProductType::Vps)->create(['name' => $name]);

    ProductPrice::factory()
        ->forProduct($product)
        ->cycle(BillingCycle::Monthly)
        ->currency('EUR')
        ->amounts($minor)
        ->create();

    return $product;
}

it('takes a discount code off a cart again', function (): void {
    $product = saleableProduct();

    Promotion::factory()->forOrganization($this->provider->id)->create([
        'code' => 'WELCOME10',
        'percentage' => 10,
        'is_active' => true,
    ]);

    $this->post('/cart', [
        'product_id' => $product->id,
        'billing_cycle' => BillingCycle::Monthly->value,
        'quantity' => 1,
    ])->assertRedirect();

    $this->post('/cart/code', ['code' => 'WELCOME10'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->get('/cart')->assertOk()->assertSee('WELCOME10');

    $this->delete('/cart/code')->assertRedirect();

    // Gone, and the cart is priced on every read, so the total follows without
    // anything being recalculated by hand.
    $this->get('/cart')->assertOk()->assertDontSee('WELCOME10');
});

it('removing a code from an empty cart is a no-op rather than an error', function (): void {
    // Somebody who pressed it twice, or came back to an expired cart. The
    // endpoint has nothing to do and must not say something went wrong.
    $this->delete('/cart/code')->assertRedirect()->assertSessionHasNoErrors();
});

it('counts a help article as helpful, and as not', function (): void {
    $article = KbArticle::factory()->create([
        'organization_id' => $this->provider->id,
        'slug' => 'how-to-pay',
        'visibility' => ArticleVisibility::Public->value,
        'published_at' => now()->subDay(),
        'helpful_count' => 0,
        'unhelpful_count' => 0,
    ]);

    $this->post('/help/how-to-pay/rating', ['helpful' => true])->assertRedirect();
    $this->post('/help/how-to-pay/rating', ['helpful' => false])->assertRedirect();

    // `increment()` compiles to `set x = x + 1` and is atomic, which is what
    // makes two readers at once safe here.
    expect($article->fresh()->helpful_count)->toBe(1)
        ->and($article->fresh()->unhelpful_count)->toBe(1);
});

it('does not rate an article the public cannot read', function (): void {
    KbArticle::factory()->create([
        'organization_id' => $this->provider->id,
        'slug' => 'internal-runbook',
        'visibility' => ArticleVisibility::Draft->value,
        'published_at' => null,
    ]);

    // 404, which is the same answer as a slug that does not exist: the rating
    // endpoint must not tell a stranger which drafts are there.
    $this->post('/help/internal-runbook/rating', ['helpful' => true])->assertNotFound();
});
