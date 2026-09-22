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
}
