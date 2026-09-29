<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Identity\Guard;

/**
 * Which scope vocabulary a guard speaks.
 *
 * `ApiScope` and `StaffApiScope` are deliberately separate enums — they
 * narrow different things, and one enum holding both would let a route ask
 * for `tickets:read` and get either meaning depending on who answered. This
 * is the one place that maps a guard to its vocabulary, so the form request
 * that validates a scope and the controller that filters one are reading the
 * same list. Two copies of that mapping is two chances to validate against
 * one enum and grant from the other.
 */
final readonly class ScopeVocabulary
{
    /**
     * @return list<string>
     */
    public static function valuesFor(Guard $guard): array
    {
        return $guard === Guard::Staff
            ? StaffApiScope::values()
            : array_column(ApiScope::cases(), 'value');
    }

    /**
     * The permissions behind a scope, or null when the guard has no such
     * scope at all — which is a refusal rather than an empty list, because
     * an empty list reads as "no permissions needed".
     *
     * @return list<string>|null
     */
    public static function permissionsFor(Guard $guard, string $value): ?array
    {
        if ($guard === Guard::Staff) {
            return StaffApiScope::tryFrom($value)?->requiredPermissions();
        }

        return ApiScope::tryFrom($value)?->requiredPermissions();
    }
}
