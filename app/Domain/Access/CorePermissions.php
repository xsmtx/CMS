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
            ...self::billing(),
            ...self::settings(),
            ...self::provisioning(),
            ...self::domains(),
            ...self::support(),
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

            new PermissionDefinition('automation.view', 'automation', RoleScope::Staff),
            // Running a task by hand can invoice a thousand customers.
            new PermissionDefinition('automation.run', 'automation', RoleScope::Staff, highRisk: true),
            new PermissionDefinition('automation.dunning.manage', 'automation', RoleScope::Staff, highRisk: true),

            new PermissionDefinition('operations.view', 'automation', RoleScope::Staff),
            new PermissionDefinition('operations.manage', 'automation', RoleScope::Staff),

            // The Resource Graph. Reading it is ordinary — a support agent
            // mid-ticket wants to know what a service sits on.
            new PermissionDefinition('infrastructure.resources.view', 'infrastructure', RoleScope::Staff),
            new PermissionDefinition('infrastructure.telemetry.view', 'infrastructure', RoleScope::Staff),
            new PermissionDefinition('infrastructure.adapters.view', 'infrastructure', RoleScope::Staff),
            /*
             * Allowing an adapter to write is the moment this installation stops
             * being a window onto the estate and starts being a control plane
             * over it. High risk, so Phase 17's recent-password challenge
             * applies without anything new being written.
             */
            new PermissionDefinition('infrastructure.adapters.manage', 'infrastructure', RoleScope::Staff, highRisk: true),

            /*
             * Addressing. Reading it is ordinary for the same reason reading
             * the graph is: a support agent looking at an abuse report needs to
             * know which service held an address, and that question is the
             * whole point of keeping the history.
             *
             * Managing it is not high risk, and that is deliberate: handing out
             * an address is routine work a network operator does all day, and a
             * password challenge on routine work is a challenge people learn to
             * type through. The destructive end - deleting a prefix - refuses
             * while anything is in it.
             */
            new PermissionDefinition('network.ipam.view', 'infrastructure', RoleScope::Staff),
            new PermissionDefinition('network.ipam.manage', 'infrastructure', RoleScope::Staff),

            /*
             * Changing a device's configuration, as three permissions rather
             * than one.
             *
             * `request` and `approve` are separate because a change that one
             * person can both ask for and agree to is a change nobody agreed
             * to - and `RequestNetworkChange` refuses a decision from the
             * person who asked, which a single permission could not express.
             *
             * `apply` is separate again and is the high-risk one: approving is
             * a sentence in a record, and applying is a firewall reloading.
             * It carries the password challenge on the route as well.
             */
            new PermissionDefinition('network.devices.view', 'infrastructure', RoleScope::Staff),
            new PermissionDefinition('network.changes.request', 'infrastructure', RoleScope::Staff),
            new PermissionDefinition('network.changes.approve', 'infrastructure', RoleScope::Staff),
            new PermissionDefinition(
                'network.changes.apply',
                'infrastructure',
                RoleScope::Staff,
                highRisk: true,
            ),

            // Turning the storefront off is not a settings change.
            new PermissionDefinition('platform.maintenance.manage', 'platform', RoleScope::Staff, highRisk: true),

            new PermissionDefinition('platform.modules.view', 'platform', RoleScope::Staff),
            // The highest-risk permission in the product: enabling a module
            // runs code this repository does not contain, on this server,
            // as this user. Nothing else here grants that.
            new PermissionDefinition('platform.modules.manage', 'platform', RoleScope::Staff, highRisk: true),
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
    private static function billing(): array
    {
        return [
            new PermissionDefinition('billing.invoices.view', 'billing', RoleScope::Staff),
            new PermissionDefinition('billing.invoices.manage', 'billing', RoleScope::Staff),

            // Each of these asserts something about money: that it arrived,
            // that it went back, or that the business owes it. They are
            // separable from editing a draft for that reason.
            new PermissionDefinition('billing.payments.record', 'billing', RoleScope::Staff, highRisk: true),
            new PermissionDefinition('billing.refunds.manage', 'billing', RoleScope::Staff, highRisk: true),
            new PermissionDefinition('billing.credits.manage', 'billing', RoleScope::Staff, highRisk: true),
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
    private static function provisioning(): array
    {
        return [
            new PermissionDefinition('services.view', 'services', RoleScope::Staff),
            new PermissionDefinition('services.manage', 'services', RoleScope::Staff),
            new PermissionDefinition('services.provision', 'services', RoleScope::Staff),
            new PermissionDefinition('services.suspend', 'services', RoleScope::Staff),
            // It destroys an account at a provider, and nothing brings it
            // back.
            new PermissionDefinition('services.terminate', 'services', RoleScope::Staff, highRisk: true),

            new PermissionDefinition('infrastructure.view', 'infrastructure', RoleScope::Staff),
            // Holds the credentials for somebody's production fleet.
            new PermissionDefinition('infrastructure.manage', 'infrastructure', RoleScope::Staff, highRisk: true),

            /*
             * A way into a server's panel without a password.
             *
             * A permission rather than the owner-only gate, because it is a
             * thing somebody *does* rather than who they are: a support agent
             * fixing an account needs to get into the panel, and the honest
             * alternative — handing them the root password or the API key — is
             * the thing this platform exists not to do. The token never leaves
             * the server, the session the panel issues is short-lived, and
             * every one is audited against the person who asked.
             *
             * High risk, which is about *granting* it: the roles screen asks
             * for confirmation and records a reason. Using it is not
             * challenged, because an agent who met a password prompt on every
             * account they opened would keep a password in a text file
             * instead.
             */
            new PermissionDefinition('infrastructure.connect', 'infrastructure', RoleScope::Staff, highRisk: true),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function domains(): array
    {
        return [
            new PermissionDefinition('domains.view', 'domains', RoleScope::Staff),
            // Nameservers, lock, auto-renew, contacts.
            new PermissionDefinition('domains.manage', 'domains', RoleScope::Staff),
            // Spends money at a registrar, and a registration cannot be
            // taken back.
            new PermissionDefinition('domains.register', 'domains', RoleScope::Staff, highRisk: true),

            new PermissionDefinition('catalog.tlds.view', 'catalog', RoleScope::Staff),
            new PermissionDefinition('catalog.tlds.manage', 'catalog', RoleScope::Staff),
        ];
    }

    /**
     * @return list<PermissionDefinition>
     */
    private static function support(): array
    {
        return [
            new PermissionDefinition('support.tickets.view', 'support', RoleScope::Staff),
            new PermissionDefinition('support.tickets.manage', 'support', RoleScope::Staff),
            new PermissionDefinition('support.tickets.delete', 'support', RoleScope::Staff, highRisk: true),
            new PermissionDefinition('support.departments.manage', 'support', RoleScope::Staff),

            new PermissionDefinition('content.announcements.manage', 'content', RoleScope::Staff),
            new PermissionDefinition('content.kb.manage', 'content', RoleScope::Staff),

            new PermissionDefinition('notifications.view', 'notifications', RoleScope::Staff),
            // Editing a template changes what every customer is told.
            new PermissionDefinition('notifications.manage', 'notifications', RoleScope::Staff, highRisk: true),
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
            new PermissionDefinition('portal.billing.view', 'portal', RoleScope::Customer),
            new PermissionDefinition('portal.billing.pay', 'portal', RoleScope::Customer),
            new PermissionDefinition('portal.orders.view', 'portal', RoleScope::Customer),
            new PermissionDefinition('portal.payment_methods.manage', 'portal', RoleScope::Customer),
            // A token is a password that does not expire and that nobody
            // types. Issuing one is the most dangerous thing a customer can
            // do in the portal.
            new PermissionDefinition('portal.tokens.manage', 'portal', RoleScope::Customer, highRisk: true),
            new PermissionDefinition('portal.services.view', 'portal', RoleScope::Customer),
            new PermissionDefinition('portal.domains.view', 'portal', RoleScope::Customer),
            new PermissionDefinition('portal.domains.manage', 'portal', RoleScope::Customer),
            new PermissionDefinition('portal.tickets.view', 'portal', RoleScope::Customer),
            new PermissionDefinition('portal.tickets.create', 'portal', RoleScope::Customer),
        ];
    }
}
