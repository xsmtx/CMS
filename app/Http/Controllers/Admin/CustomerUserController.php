<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Guard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\SetContactPasswordRequest;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\LoginHistory;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every person who can sign into the customer area, across all customers.
 *
 * The customer screen answers "who works at this company". This one answers
 * "why can this person not log in", which is a different question asked by
 * a different person — usually on the telephone, while the customer waits.
 * Hence the two things it offers: send them a reset link, or set a password
 * and read it out.
 *
 * **Setting a password is deliberately noisy.** It is audited, it always
 * notifies the account holder, and it never shows the operator an existing
 * password — because there is no existing password to show, only a hash.
 * An operator who can silently take over a customer's account is a support
 * desk that can be socially engineered.
 *
 * Contacts without portal access are not listed. They are people on a
 * customer's record, not users, and mixing the two makes "why can this
 * person not log in" answerable with "because they were never meant to".
 */
final class CustomerUserController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(Request $request): Response
    {
        $this->authorizeFor('crm.customers.view');

        $search = $request->string('search')->toString();

        $users = Contact::query()
            ->where('portal_access', true)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('email', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                });
            })
            ->select('contacts.*')
            // Not just `customer`: the name shown for a sole trader comes
            // from their primary contact, and for a customer with neither
            // from the organization.
            ->with(Customer::displayNameWith('customer'))
            // The last time they actually got in. A failed attempt is not a
            // login, and showing one would answer the question wrongly.
            ->addSelect([
                'last_login_at' => LoginHistory::query()
                    ->select('occurred_at')
                    ->whereColumn('subject_id', 'contacts.id')
                    ->where('subject_type', Contact::class)
                    ->where('successful', true)
                    ->latest('occurred_at')
                    ->limit(1),
            ])
            ->orderBy('last_name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Contact $contact): array => [
                'id' => $contact->id,
                'firstName' => $contact->first_name,
                'lastName' => $contact->last_name,
                'email' => $contact->email,
                'customerId' => $contact->customer_id,
                'customer' => $contact->customer?->displayName(),
                'twoFactor' => $contact->hasTwoFactorEnabled(),
                'lastLoginAt' => $this->timestamp($contact->getAttribute('last_login_at')),
            ]);

        return Inertia::render('Admin/Customers/Users', [
            'users' => $users,
            'filters' => ['search' => $search],
            'can' => ['manage' => $this->actor->can('crm.customers.manage')],
        ]);
    }

    /**
     * Send them the same link they would get from the "forgotten password"
     * form, rather than inventing a second mechanism with its own expiry
     * and its own bugs.
     */
    public function sendReset(string $contact): RedirectResponse
    {
        $this->authorizeFor('crm.customers.manage');

        $user = $this->find($contact);

        Password::broker(Guard::Client->passwordBroker())
            ->sendResetLink(['email' => $user->email]);

        Audit::action('identity.password_reset_sent')
            ->by($this->actor->model())
            ->on($user)
            ->forOrganization($user->organization_id)
            ->write();

        return back()->with('status', __('identity.users.reset_sent'));
    }

    public function setPassword(SetContactPasswordRequest $request, string $contact): RedirectResponse
    {
        $this->authorizeFor('crm.customers.manage');

        $user = $this->find($contact);

        $user->forceFill([
            'password' => Hash::make($request->string('password')->toString()),
            'password_changed_at' => now(),
        ])->save();

        // Every session they had is now somebody else's idea of their
        // password. Ending them is the only safe answer.
        $user->tokens()->delete();

        Audit::action('identity.password_set_by_staff')
            ->by($this->actor->model())
            ->on($user)
            ->forOrganization($user->organization_id)
            ->because($request->string('reason')->toString() ?: null)
            ->write();

        return back()->with('status', __('identity.users.password_set'));
    }

    private function find(string $id): Contact
    {
        $contact = Contact::query()->where('portal_access', true)->whereKey($id)->first();

        if (! $contact instanceof Contact) {
            // Outside the boundary, or not a user. Same answer either way:
            // an id is not proof of anything.
            abort(404);
        }

        return $contact;
    }

    /**
     * The subselect's value, whatever the model decided to cast it to.
     *
     * A column added by `addSelect` may come back as a string or as a
     * Carbon instance depending on the model's casts, and a helper that
     * only handled one of them returned null for a login that had
     * happened — which is the same answer as "never signed in", and the
     * wrong one.
     */
    private function timestamp(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->toIso8601String();
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('crm.errors.not_permitted'));
        }
    }
}
