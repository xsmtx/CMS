<?php

declare(strict_types=1);

namespace App\Domain\Api;

/**
 * What a token's holder allowed an integration to do.
 *
 * **A scope narrows; it never grants.** Authorization here is still the
 * three questions asked everywhere else — organization boundary, resource
 * ownership, permission — and the scope is a fourth, asked last. A token
 * carrying `services:write` held by a contact without
 * `portal.services.view` reaches nothing, because a scope describes what
 * the person consented to share, not what the platform lets them do.
 * Confusing the two is how an API becomes a privilege-escalation path.
 *
 * `:write` deliberately does not imply `:read`. A token that may open a
 * ticket without reading the others is a real integration — a contact form
 * on somebody's own site — and the reverse is more common still.
 *
 * `invoices` is read-only in v1. Paying an invoice moves money, and a token
 * is a password nobody types; when that changes it will be its own decision
 * with its own scope.
 */
enum ApiScope: string
{
    case ProfileRead = 'profile:read';
    case ProfileWrite = 'profile:write';

    case ServicesRead = 'services:read';
    case ServicesWrite = 'services:write';

    case DomainsRead = 'domains:read';
    case DomainsWrite = 'domains:write';

    case InvoicesRead = 'invoices:read';

    case OrdersRead = 'orders:read';

    case TicketsRead = 'tickets:read';
    case TicketsWrite = 'tickets:write';

    case WebhooksRead = 'webhooks:read';
    case WebhooksWrite = 'webhooks:write';

    public function labelKey(): string
    {
        return 'api.scopes.'.str_replace(':', '_', $this->value).'.label';
    }

    public function descriptionKey(): string
    {
        return 'api.scopes.'.str_replace(':', '_', $this->value).'.description';
    }

    /**
     * The resource group this scope belongs to, for grouping on a screen.
     */
    public function group(): string
    {
        return explode(':', $this->value)[0];
    }

    public function isWrite(): bool
    {
        return str_ends_with($this->value, ':write');
    }

    /**
     * The portal permissions the holder must have for this scope to mean
     * anything.
     *
     * Declared here rather than checked at each route, so that adding a
     * scope forces the author to answer "and what does the person need to
     * be allowed to do" in the same edit.
     *
     * @return list<string>
     */
    public function requiredPermissions(): array
    {
        return match ($this) {
            self::ProfileRead => ['portal.profile.view'],
            self::ProfileWrite => ['portal.profile.manage'],
            self::ServicesRead, self::ServicesWrite => ['portal.services.view'],
            self::DomainsRead => ['portal.domains.view'],
            self::DomainsWrite => ['portal.domains.view', 'portal.domains.manage'],
            self::InvoicesRead => ['portal.billing.view'],
            self::OrdersRead => ['portal.orders.view'],
            self::TicketsRead => ['portal.tickets.view'],
            self::TicketsWrite => ['portal.tickets.create'],
            // Managing where this platform posts its events is as dangerous
            // as reading the events themselves, so both sit behind the
            // permission that already guards issuing a token.
            self::WebhooksRead, self::WebhooksWrite => ['portal.tokens.manage'],
        };
    }

    /**
     * @return list<self>
     */
    public static function forGroup(string $group): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $scope): bool => $scope->group() === $group,
        ));
    }

    /**
     * @return list<string>
     */
    public static function groups(): array
    {
        return array_values(array_unique(array_map(
            static fn (self $scope): string => $scope->group(),
            self::cases(),
        )));
    }
}
