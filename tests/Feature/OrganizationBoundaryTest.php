<?php

declare(strict_types=1);

use App\Domain\Organizations\Exceptions\InvalidOrganizationHierarchy;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Audit\Models\AuditLog;
use App\Infrastructure\Identity\Models\User;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Audit\Facades\Audit;
use App\Support\Organizations\OrganizationContext;

beforeEach(function (): void {
    $this->provider = Organization::factory()->provider()->create(['name' => 'InfraCMS']);

    $this->resellerA = Organization::factory()->reseller($this->provider)->create(['name' => 'Reseller A']);
    $this->resellerB = Organization::factory()->reseller($this->provider)->create(['name' => 'Reseller B']);

    $this->customerA = Organization::factory()->customerOf($this->resellerA)->create(['name' => 'Customer A']);
    $this->customerB = Organization::factory()->customerOf($this->resellerB)->create(['name' => 'Customer B']);

    $this->context = app(OrganizationContext::class);
});

it('materialises a path that encodes the ancestor chain', function (): void {
    expect($this->provider->path)->toBe('/'.$this->provider->id.'/')
        ->and($this->resellerA->path)->toBe('/'.$this->provider->id.'/'.$this->resellerA->id.'/')
        ->and($this->customerA->path)->toBe(
            '/'.$this->provider->id.'/'.$this->resellerA->id.'/'.$this->customerA->id.'/',
        );
});

it('knows which organizations it owns', function (): void {
    expect($this->provider->owns($this->customerA))->toBeTrue()
        ->and($this->resellerA->owns($this->customerA))->toBeTrue()
        ->and($this->resellerA->owns($this->customerB))->toBeFalse()
        ->and($this->resellerB->owns($this->resellerA))->toBeFalse();
});

it('refuses a hierarchy the ownership model does not allow', function (): void {
    Organization::factory()->create([
        'type' => OrganizationType::Reseller->value,
        'parent_id' => $this->customerA->id,
    ]);
})->throws(InvalidOrganizationHierarchy::class);

it('refuses a second root organization', function (): void {
    Organization::factory()->create([
        'type' => OrganizationType::Customer->value,
        'parent_id' => null,
    ]);
})->throws(InvalidOrganizationHierarchy::class);

it('refuses to give the provider organization a parent', function (): void {
    Organization::factory()->create([
        'type' => OrganizationType::Provider->value,
        'parent_id' => $this->resellerA->id,
    ]);
})->throws(InvalidOrganizationHierarchy::class);

it('hides another reseller from a reseller', function (): void {
    $this->context->set($this->resellerA->id);

    $visible = Organization::query()->pluck('name')->all();

    expect($visible)->toContain('Reseller A', 'Customer A')
        ->and($visible)->not->toContain('Reseller B', 'Customer B', 'InfraCMS');
});

it('shows the whole hierarchy to the provider', function (): void {
    $this->context->set($this->provider->id);

    expect(Organization::query()->count())->toBe(5);
});

it('never leaks another organization owned records', function (): void {
    $mine = $this->context->runAs(
        $this->resellerA->id,
        fn (): User => User::factory()->create(['email' => 'mine@example.test']),
    );

    $theirs = $this->context->runAs(
        $this->resellerB->id,
        fn (): User => User::factory()->create(['email' => 'theirs@example.test']),
    );

    expect($mine->organization_id)->toBe($this->resellerA->id)
        ->and($theirs->organization_id)->toBe($this->resellerB->id);

    $this->context->set($this->resellerA->id);

    $emails = User::query()->pluck('email')->all();

    expect($emails)->toContain('mine@example.test')
        ->and($emails)->not->toContain('theirs@example.test')
        ->and(User::query()->find($theirs->id))->toBeNull();
});

it('lets a parent organization see records owned by its children', function (): void {
    $this->context->runAs(
        $this->customerA->id,
        fn (): User => User::factory()->create(['email' => 'child@example.test']),
    );

    $this->context->set($this->resellerA->id);

    expect(User::query()->pluck('email')->all())->toContain('child@example.test');
});

it('stamps new records with the acting organization without being told', function (): void {
    $user = $this->context->runAs(
        $this->customerB->id,
        fn (): User => User::factory()->create(),
    );

    expect($user->organization_id)->toBe($this->customerB->id);
});

it('refuses to create an owned record with no boundary at all', function (): void {
    $this->context->forget();

    User::query()->create([
        'name' => 'Nobody',
        'email' => 'nobody@example.test',
        'password' => 'irrelevant',
    ]);
})->throws(RuntimeException::class, 'outside an organization boundary');

it('restores the previous boundary even when the callback throws', function (): void {
    $this->context->set($this->resellerA->id);

    try {
        $this->context->runAs($this->resellerB->id, function (): never {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($this->context->id())->toBe($this->resellerA->id);
});

it('bounds the audit trail by organization', function (): void {
    $this->context->runAs($this->resellerA->id, function (): void {
        Audit::action('services.suspended')->bySystem('test')->write();
    });

    $this->context->runAs($this->resellerB->id, function (): void {
        Audit::action('services.terminated')->bySystem('test')->write();
    });

    $this->context->set($this->resellerA->id);

    expect(AuditLog::query()->pluck('action')->all())->toBe(['services.suspended']);

    $this->context->forget();

    expect(AuditLog::query()->count())->toBe(2);
});

it('exposes an explicit, auditable escape hatch for provider-wide work', function (): void {
    $this->context->set($this->resellerA->id);

    expect(Organization::query()->count())->toBe(2)
        ->and($this->context->withoutBoundary(
            fn (): int => Organization::query()->count(),
        ))->toBe(5);

    // The boundary is back in place afterwards.
    expect(Organization::query()->count())->toBe(2);
});
