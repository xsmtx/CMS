<?php

declare(strict_types=1);

namespace App\Support\Identity;

use App\Domain\Identity\Guard;
use App\Infrastructure\Identity\Contracts\AuthenticatableAccount;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Who is acting, across both guards.
 *
 * Laravel's `$request->user()` resolves the default guard, which in a
 * two-guard application silently answers the wrong question. Everything that
 * needs "the current subject" without caring which guard it came from asks
 * here instead: the organization boundary, the audit trail and the props
 * shared with the front end.
 */
final class CurrentActor
{
    /**
     * Guards are checked staff-first. A request can only ever be
     * authenticated on one of them, because they use separate session keys;
     * the order only decides which answer wins in the impossible case.
     *
     * @return list<Guard>
     */
    private const array ORDER = [Guard::Staff, Guard::Client];

    public function guard(): ?Guard
    {
        foreach (self::ORDER as $guard) {
            if (Auth::guard($guard->value)->check()) {
                return $guard;
            }
        }

        return null;
    }

    public function subject(): ?Authenticatable
    {
        $guard = $this->guard();

        if ($guard === null) {
            return null;
        }

        $user = Auth::guard($guard->value)->user();

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * The subject as an Eloquent model, for the audit trail and for anything
     * that needs a morph class.
     */
    public function model(): ?Model
    {
        $subject = $this->subject();

        return $subject instanceof Model ? $subject : null;
    }

    /**
     * The subject as a platform account.
     *
     * Used by anything that needs the account behaviour rather than just a
     * row: two-factor, session management, the security screens.
     */
    public function account(): (Model&AuthenticatableAccount)|null
    {
        $subject = $this->subject();

        return $subject instanceof Model && $subject instanceof AuthenticatableAccount
            ? $subject
            : null;
    }

    public function organizationId(): ?string
    {
        $organizationId = $this->model()?->getAttribute('organization_id');

        return is_string($organizationId) ? $organizationId : null;
    }

    /**
     * Whether the current actor may do something.
     *
     * Goes through the gate rather than the model, so it works for either
     * guard and returns false for an anonymous visitor instead of throwing.
     */
    public function can(string $ability, mixed $arguments = []): bool
    {
        $subject = $this->subject();

        return $subject !== null && Gate::forUser($subject)->allows($ability, $arguments);
    }

    public function isStaff(): bool
    {
        return $this->guard() === Guard::Staff;
    }

    public function isClient(): bool
    {
        return $this->guard() === Guard::Client;
    }

    public function check(): bool
    {
        return $this->guard() !== null;
    }
}
