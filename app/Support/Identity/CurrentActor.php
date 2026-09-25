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
     * The guard of the area being asked for, and the other one after it.
     *
     * Separate session **keys** are not separate sessions: one browser holds
     * both, and an operator signed into `/admin` who also signs into the
     * portal — to see what a customer sees, or because the storefront signed
     * them in at checkout — is an ordinary thing rather than an impossible
     * one. Staff-first everywhere made `CurrentCustomer::contact()` answer
     * with a staff user on a client route, which is a 404 on every page of
     * the portal and no way to find out why.
     *
     * The area comes from the route name, which every authenticated route
     * carries, and from the path when the route has not been resolved yet —
     * the organization boundary runs as global middleware, before there is
     * one.
     */
    public function guard(): ?Guard
    {
        foreach ($this->order() as $guard) {
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

    /**
     * Whether this actor bypasses permission checks entirely.
     *
     * Asked directly rather than through a permission, because there is no
     * permission only a super administrator holds: the seeder gives an
     * Administrator every staff-scoped permission there is, deliberately,
     * so a new one would land on both roles the moment it was declared.
     *
     * Used by the one area that is not about what somebody may do but about
     * who they are — Apps and Integrations, where enabling a package runs
     * code this repository does not contain.
     */
    public function isSuperAdmin(): bool
    {
        $model = $this->model();

        return $model !== null
            && method_exists($model, 'isSuperAdmin')
            && (bool) $model->isSuperAdmin();
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

    /**
     * @return list<Guard>
     */
    private function order(): array
    {
        return $this->isAdminArea()
            ? [Guard::Staff, Guard::Client]
            : [Guard::Client, Guard::Staff];
    }

    private function isAdminArea(): bool
    {
        if (! app()->bound('request')) {
            // A console run authenticates nobody, so the order cannot matter.
            return true;
        }

        $request = request();
        $named = Guard::fromRouteName($request->route()?->getName());

        if ($named !== null) {
            return $named === Guard::Staff;
        }

        return $request->is('admin', 'admin/*');
    }
}
