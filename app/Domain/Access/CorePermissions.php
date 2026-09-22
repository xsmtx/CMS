<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Permissions owned by the platform core.
 *
 * Each phase adds the permissions for the context it introduces. A
 * permission is never removed without marking the database row orphaned, so
 * that historical role assignments remain explainable.
 *
 * The high-risk flag is set on anything that grants access to a system,
 * removes data, or changes configuration with money or security
 * consequences. High-risk grants require re-confirmation in the UI and a
 * super-admin bypass of one is audited.
 */
final class CorePermissions
{
    /**
     * @return list<PermissionDefinition>
     */
    public static function all(): array
    {
        return [
            ...self::platform(),
            ...self::access(),
            ...self::identity(),
            ...self::crm(),
            ...self::catalog(),
            ...self::ordering(),
            ...self::settings(),
            ...self::portal(),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function platform(): array
    {
        return [
            new PermissionDefinition('platform.health.view', 'platform', RoleScope::Staff),
            new PermissionDefinition('platform.queue.view', 'platform', RoleScope::Staff),
            new PermissionDefinition('platform.audit.view', 'platform', RoleScope::Staff),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function access(): array
    {
        return [
            new PermissionDefinition('access.roles.view', 'access', RoleScope::Staff),
            new PermissionDefinition('access.roles.manage', 'access', RoleScope::Staff, highRisk: true),
            new PermissionDefinition('access.permissions.view', 'access', RoleScope::Staff),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function identity(): array
    {
        return [
            new PermissionDefinition('identity.staff.view', 'identity', RoleScope::Staff),
            new PermissionDefinition('identity.staff.manage', 'identity', RoleScope::Staff, highRisk: true),
            new PermissionDefinition('identity.contacts.view', 'identity', RoleScope::Staff),
            new PermissionDefinition('identity.contacts.manage', 'identity', RoleScope::Staff, highRisk: true),

            // Acting as a customer is the single most sensitive capability in
            // the product: it produces actions attributable to someone who
            // did not perform them.
            new PermissionDefinition('identity.contacts.impersonate', 'identity', RoleScope::Staff, highRisk: true),

            new PermissionDefinition('identity.sessions.manage', 'identity', RoleScope::Staff, highRisk: true),
            new PermissionDefinition('identity.login_history.view', 'identity', RoleScope::Staff),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function crm(): array
    {
        return [
            new PermissionDefinition('crm.customers.view', 'crm', RoleScope::Staff),
            new PermissionDefinition('crm.customers.manage', 'crm', RoleScope::Staff),

            // Both produce or destroy a complete copy of a person's data.
            new PermissionDefinition('crm.customers.export', 'crm', RoleScope::Staff, highRisk: true),
            new PermissionDefinition('crm.customers.anonymize', 'crm', RoleScope::Staff, highRisk: true),

            new PermissionDefinition('crm.notes.view', 'crm', RoleScope::Staff),
            new PermissionDefinition('crm.notes.manage', 'crm', RoleScope::Staff),
            new PermissionDefinition('crm.tags.manage', 'crm', RoleScope::Staff),
            new PermissionDefinition('crm.custom_fields.manage', 'crm', RoleScope::Staff),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function catalog(): array
    {
        return [
            new PermissionDefinition('catalog.groups.view', 'catalog', RoleScope::Staff),
            new PermissionDefinition('catalog.groups.manage', 'catalog', RoleScope::Staff),
            new PermissionDefinition('catalog.products.view', 'catalog', RoleScope::Staff),
            new PermissionDefinition('catalog.products.manage', 'catalog', RoleScope::Staff),

            // Both change what customers are charged, which is why they are
            // separable from editing a product's description.
            new PermissionDefinition('catalog.pricing.manage', 'catalog', RoleScope::Staff, highRisk: true),
            new PermissionDefinition('catalog.currencies.manage', 'catalog', RoleScope::Staff, highRisk: true),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function ordering(): array
    {
        return [
            new PermissionDefinition('orders.view', 'ordering', RoleScope::Staff),
            new PermissionDefinition('orders.manage', 'ordering', RoleScope::Staff),

            // Clearing a risk hold overrides the platform's own judgement
            // about an order, which is exactly the kind of decision that
            // has to be attributable.
            new PermissionDefinition('orders.review', 'ordering', RoleScope::Staff, highRisk: true),

            new PermissionDefinition('promotions.view', 'ordering', RoleScope::Staff),

            // Changes what customers are charged.
            new PermissionDefinition('promotions.manage', 'ordering', RoleScope::Staff, highRisk: true),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function settings(): array
    {
        return [
            new PermissionDefinition('organizations.view', 'organizations', RoleScope::Staff),
            new PermissionDefinition('organizations.manage', 'organizations', RoleScope::Staff, highRisk: true),
            new PermissionDefinition('settings.view', 'settings', RoleScope::Staff),
            new PermissionDefinition('settings.manage', 'settings', RoleScope::Staff, highRisk: true),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function portal(): array
    {
        return [
            new PermissionDefinition('portal.dashboard.view', 'portal', RoleScope::Customer),
            new PermissionDefinition('portal.profile.view', 'portal', RoleScope::Customer),
            new PermissionDefinition('portal.profile.manage', 'portal', RoleScope::Customer),
            new PermissionDefinition('portal.contacts.manage', 'portal', RoleScope::Customer, highRisk: true),
            new PermissionDefinition('portal.security.manage', 'portal', RoleScope::Customer),
        ];
    }
}
