<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Access\RoleScope;

/**
 * The two authentication guards.
 *
 * Staff and customers never share a session cookie, a password broker or a
 * rate-limit bucket. Keeping the pairing in one enum means a new surface
 * cannot accidentally wire a client screen to the staff guard.
 */
enum Guard: string
{
    case Staff = 'staff';
    case Client = 'client';

    public function roleScope(): RoleScope
    {
        return match ($this) {
            self::Staff => RoleScope::Staff,
            self::Client => RoleScope::Customer,
        };
    }

    /**
     * The user provider this guard authenticates against.
     *
     * `Auth::createUserProvider()` takes a provider name, not a guard name,
     * and the two are easy to confuse: passing the guard name silently
     * returns null and every sign-in fails as "unknown identity".
     */
    public function userProvider(): string
    {
        return match ($this) {
            self::Staff => 'staff_users',
            self::Client => 'contacts',
        };
    }

    public function passwordBroker(): string
    {
        return match ($this) {
            self::Staff => 'staff_users',
            self::Client => 'contacts',
        };
    }

    /**
     * Where an unauthenticated visitor is sent.
     */
    public function loginPath(): string
    {
        return match ($this) {
            self::Staff => '/admin/login',
            self::Client => '/login',
        };
    }

    public function homePath(): string
    {
        return match ($this) {
            self::Staff => '/admin',
            self::Client => '/client',
        };
    }

    public function routePrefix(): string
    {
        return match ($this) {
            self::Staff => 'admin',
            self::Client => 'client',
        };
    }

    /**
     * The guard a named route belongs to.
     *
     * Every route in an authenticated area is registered under the area's
     * name prefix, so the name already carries the answer. Deriving it here
     * means the shared auth controllers need no extra wiring in the route
     * files, and a route cannot be given the wrong guard by accident.
     */
    public static function fromRouteName(?string $routeName): ?self
    {
        if ($routeName === null) {
            return null;
        }

        foreach (self::cases() as $guard) {
            if (str_starts_with($routeName, $guard->routePrefix().'.')) {
                return $guard;
            }
        }

        return null;
    }
}
