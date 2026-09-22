<?php

declare(strict_types=1);

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;
use App\Domain\Access\SystemRole;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Promotions\PromotionScope;
use App\Domain\Promotions\PromotionType;
use App\Domain\Risk\RiskDecision;
use App\Infrastructure\Access\Models\Permission;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Promotions\Models\Promotion;
use Database\Seeders\ProviderOrganizationSeeder;
use Database\Seeders\SystemRoleSeeder;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    $this->seed(ProviderOrganizationSeeder::class);
    app(SyncPermissions::class)->handle(app(PermissionRegistry::class));
    $this->seed(SystemRoleSeeder::class);

    $this->admin = StaffUser::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator);
    $this->admin = $this->admin->fresh();

    $this->customer = Customer::factory()->forOrganization($this->admin->organization_id)->create();
});

function staffWith(array $slugs): StaffUser
{
    $role = Role::query()->create([
        'name' => 'Limited',
        'slug' => 'limited-'.uniqid(),
        'scope' => RoleScope::Staff->value,
        'is_system' => false,
    ]);

    $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id'));

    $staff = StaffUser::factory()->create(['organization_id' => test()->admin->organization_id]);
    $staff->roles()->attach($role);

    return $staff->fresh() ?? $staff;
}

it('refuses the order list without the permission', function (): void {
    $this->actingAs(StaffUser::factory()->create(), 'staff')
        ->get('/admin/orders')
        ->assertForbidden();
});

it('lists orders', function (): void {
    Order::factory()->forCustomer($this->customer)->create(['number' => 'ORD-000001']);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/orders')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Orders/Index')
            ->has('orders.data', 1)
            ->where('orders.data.0.number', 'ORD-000001'));
});

it('filters the list by status', function (): void {
    Order::factory()->forCustomer($this->customer)->status(OrderStatus::Cancelled)->create();
    Order::factory()->forCustomer($this->customer)->status(OrderStatus::AwaitingPayment)->create();

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/orders?status=cancelled')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.status', 'cancelled'));
});

it('shows an order with its lines and history', function (): void {
    $order = Order::factory()->forCustomer($this->customer)->create();
    $order->allItems()->create([
        'organization_id' => $order->organization_id,
        'kind' => 'product',
        'name' => 'Starter Plan',
        'currency_code' => 'EUR',
        'quantity' => 1,
        'unit_recurring_minor' => 999,
        'line_recurring_minor' => 999,
        'line_total_minor' => 999,
        'position' => 0,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get("/admin/orders/{$order->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Orders/Show')
            ->has('order.items', 1)
            ->where('order.items.0.name', 'Starter Plan'));
});

it('changes an order status with a reason', function (): void {
    $order = Order::factory()->forCustomer($this->customer)
        ->status(OrderStatus::AwaitingPayment)->create();

    $this->actingAs($this->admin, 'staff')
        ->put("/admin/orders/{$order->id}/status", [
            'status' => OrderStatus::Cancelled->value,
            'reason' => 'Customer asked to cancel',
        ])
        ->assertSessionHasNoErrors();

    expect($order->fresh()?->status)->toBe(OrderStatus::Cancelled)
        ->and($order->statusHistory()->get()->last()?->reason)->toBe('Customer asked to cancel');
});

it('refuses a status change the machine does not allow', function (): void {
    $order = Order::factory()->forCustomer($this->customer)
        ->status(OrderStatus::Cancelled)->create();

    $this->actingAs($this->admin, 'staff')
        ->put("/admin/orders/{$order->id}/status", ['status' => OrderStatus::Paid->value])
        ->assertStatus(409);
});

it('lists the review queue', function (): void {
    Order::factory()->forCustomer($this->customer)->status(OrderStatus::FraudReview)->create([
        'risk_decision' => RiskDecision::Review->value,
        'risk_reasons' => [['code' => 'high_order_value', 'detail' => ['threshold' => '100.00']]],
    ]);

    $this->actingAs($this->admin, 'staff')
        ->get('/admin/orders/review')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Orders/Review')
            ->has('orders', 1)
            ->where('orders.0.riskReasons.0', 'Order value above 100.00'));
});

it('releases a held order and records who did it and why', function (): void {
    $audit = $this->fakeAudit();

    $order = Order::factory()->forCustomer($this->customer)->status(OrderStatus::FraudReview)->create([
        'risk_decision' => RiskDecision::Review->value,
    ]);

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/orders/{$order->id}/release", ['reason' => 'Spoke to the customer'])
        ->assertSessionHasNoErrors();

    $fresh = $order->fresh();

    expect($fresh?->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($fresh?->risk_reviewed_at)->not->toBeNull()
        ->and($fresh?->risk_reviewed_by)->toBe($this->admin->email)
        ->and($audit->actions())->toContain('ordering.order.risk_released');
});

it('refuses a held order', function (): void {
    $order = Order::factory()->forCustomer($this->customer)->status(OrderStatus::FraudReview)->create();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/orders/{$order->id}/refuse", ['reason' => 'Card details did not check out'])
        ->assertSessionHasNoErrors();

    expect($order->fresh()?->status)->toBe(OrderStatus::Cancelled);
});

it('demands a reason for an override', function (): void {
    $order = Order::factory()->forCustomer($this->customer)->status(OrderStatus::FraudReview)->create();

    $this->actingAs($this->admin, 'staff')
        ->post("/admin/orders/{$order->id}/release", ['reason' => ''])
        ->assertSessionHasErrors('reason');
});

it('refuses a review to a staff member who may only manage orders', function (): void {
    $staff = staffWith(['orders.view', 'orders.manage']);

    $order = Order::factory()->forCustomer($this->customer)->status(OrderStatus::FraudReview)->create();

    $this->actingAs($staff, 'staff')
        ->post("/admin/orders/{$order->id}/release", ['reason' => 'Looks fine to me'])
        ->assertForbidden();

    $this->actingAs($staff, 'staff')
        ->get("/admin/orders/{$order->id}")
        ->assertOk();
});

it('creates a percentage promotion', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/promotions', [
            'code' => 'launch10',
            'name' => 'Launch offer',
            'type' => PromotionType::Percentage->value,
            'percentage' => '10',
            'scope' => PromotionScope::Order->value,
            'application' => 'first_payment',
            'is_active' => true,
        ])
        ->assertRedirect('/admin/promotions');

    $promotion = Promotion::query()->where('code', 'LAUNCH10')->sole();

    expect($promotion->percentage)->toBe('10.00')
        ->and($promotion->amount)->toBeNull();
});

it('demands a currency for a fixed promotion', function (): void {
    $this->actingAs($this->admin, 'staff')
        ->post('/admin/promotions', [
            'code' => 'FIVER',
            'name' => 'Five off',
            'type' => PromotionType::Fixed->value,
            'amount_minor' => 500,
            'scope' => PromotionScope::Order->value,
            'application' => 'first_payment',
        ])
        ->assertSessionHasErrors('currency_code');
});

it('clears the columns a type does not use', function (): void {
    $promotion = Promotion::factory()->forOrganization($this->admin->organization_id)
        ->fixed(5000, 'EUR')->code('WASFIXED')->create();

    $this->actingAs($this->admin, 'staff')
        ->put("/admin/promotions/{$promotion->id}", [
            'code' => 'WASFIXED',
            'name' => 'Now a percentage',
            'type' => PromotionType::Percentage->value,
            'percentage' => '15',
            'scope' => PromotionScope::Order->value,
            'application' => 'first_payment',
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    $fresh = $promotion->fresh();

    // A row that says two things will eventually have the wrong one read.
    expect($fresh?->amount)->toBeNull()
        ->and($fresh?->percentage)->toBe('15.00');
});

it('refuses a duplicate code in the same organization', function (): void {
    Promotion::factory()->forOrganization($this->admin->organization_id)->code('TAKEN')->create();

    $this->actingAs($this->admin, 'staff')
        ->post('/admin/promotions', [
            'code' => 'TAKEN',
            'name' => 'Another',
            'type' => PromotionType::Percentage->value,
            'percentage' => '5',
            'scope' => PromotionScope::Order->value,
            'application' => 'first_payment',
        ])
        ->assertSessionHasErrors('code');
});

it('refuses to delete a promotion that has been redeemed', function (): void {
    $promotion = Promotion::factory()->forOrganization($this->admin->organization_id)->create();

    $promotion->redemptions()->create([
        'organization_id' => $promotion->organization_id,
        'amount_minor' => 500,
        'currency_code' => 'EUR',
        'redeemed_at' => now(),
    ]);

    $this->actingAs($this->admin, 'staff')
        ->delete("/admin/promotions/{$promotion->id}")
        ->assertForbidden();
});

it('deletes a promotion nobody used', function (): void {
    $promotion = Promotion::factory()->forOrganization($this->admin->organization_id)->create();

    $this->actingAs($this->admin, 'staff')
        ->delete("/admin/promotions/{$promotion->id}")
        ->assertRedirect('/admin/promotions');

    expect(Promotion::query()->whereKey($promotion->id)->exists())->toBeFalse();
});

it('does not reach an order belonging to another organization', function (): void {
    $reseller = Organization::factory()->reseller(
        Organization::query()->whereNull('parent_id')->sole()
    )->create();

    $theirs = Order::factory()->forCustomer(
        Customer::factory()->forOrganization($reseller)->create()
    )->create();

    $staff = StaffUser::factory()->create(['organization_id' => $reseller->id]);

    $mine = Order::factory()->forCustomer($this->customer)->create();

    $this->actingAs($staff, 'staff')
        ->get("/admin/orders/{$mine->id}")
        ->assertNotFound();

    $this->actingAs($staff, 'staff')
        ->get("/admin/orders/{$theirs->id}")
        ->assertForbidden();
});
