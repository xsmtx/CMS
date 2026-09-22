<?php

declare(strict_types=1);

use App\Domain\Access\CorePermissions;
use App\Domain\Access\Exceptions\UnknownPermission;
use App\Domain\Access\PermissionDefinition;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Access\RoleScope;

it('registers and resolves definitions', function (): void {
    $registry = new PermissionRegistry([
        new PermissionDefinition('billing.invoice.refund', 'billing', RoleScope::Staff, highRisk: true),
    ]);

    expect($registry->has('billing.invoice.refund'))->toBeTrue()
        ->and($registry->get('billing.invoice.refund')->highRisk)->toBeTrue();
});

it('refuses to let two features claim the same slug', function (): void {
    $registry = new PermissionRegistry([
        new PermissionDefinition('settings.manage', 'settings', RoleScope::Staff),
    ]);

    $registry->register(new PermissionDefinition('settings.manage', 'settings', RoleScope::Staff));
})->throws(LogicException::class);

it('throws a typed exception for an undeclared slug', function (): void {
    (new PermissionRegistry)->get('nope.not.declared');
})->throws(UnknownPermission::class);

it('filters by scope so customer roles cannot see staff capabilities', function (): void {
    $registry = new PermissionRegistry(CorePermissions::all());

    $customer = $registry->forScope(RoleScope::Customer);

    expect($customer)->not->toBeEmpty();

    foreach ($customer as $definition) {
        expect($definition->scope)->toBe(RoleScope::Customer);
    }
});

it('groups definitions for the admin UI', function (): void {
    $grouped = new PermissionRegistry(CorePermissions::all())->grouped();

    expect($grouped)->toHaveKeys(['access', 'platform', 'portal', 'settings']);
});

it('forgets everything a module contributed', function (): void {
    $registry = new PermissionRegistry([
        new PermissionDefinition('core.thing.view', 'core', RoleScope::Staff),
        new PermissionDefinition('mod.thing.view', 'mod', RoleScope::Staff, module: 'acme-gateway'),
    ]);

    $registry->forgetModule('acme-gateway');

    expect($registry->slugs())->toBe(['core.thing.view']);
});

it('declares every core permission with a dotted, lowercase slug', function (): void {
    // context.resource.verb, lowercase, dots between segments. The shape is
    // load-bearing: gates, translation keys and the admin grouping all key
    // off it.
    foreach (CorePermissions::all() as $definition) {
        expect($definition->slug)->toMatch('/^[a-z0-9]+(\.[a-z0-9_-]+){1,2}$/');
    }
});

it('groups every core permission under a declared group', function (): void {
    $groups = ['platform', 'access', 'identity', 'crm', 'catalog', 'organizations', 'settings', 'portal'];

    foreach (CorePermissions::all() as $definition) {
        expect($definition->group)->toBeIn($groups);
    }
});
