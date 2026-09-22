<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Identity\Impersonator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\ImpersonationRequest;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Support\Errors\ForbiddenException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Starting and stopping impersonation.
 *
 * The policy answers the permission question and the Impersonator answers the
 * boundary one. Both have to agree before a session is swapped.
 */
final class ImpersonationController extends Controller
{
    public function __construct(private readonly Impersonator $impersonator) {}

    public function store(ImpersonationRequest $request, Contact $contact): RedirectResponse
    {
        $this->authorize('impersonate', $contact);

        $actor = Auth::guard('staff')->user();

        if (! $actor instanceof StaffUser) {
            throw new ForbiddenException(__('identity.impersonation.forbidden'));
        }

        // Tightly limited. Impersonation is rare by nature, so a burst of
        // attempts is worth noticing rather than serving.
        $key = 'impersonate:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ForbiddenException(__('identity.auth.throttled', [
                'seconds' => RateLimiter::availableIn($key),
            ]));
        }

        if (! $this->impersonator->canImpersonate($actor, $contact)) {
            throw new ForbiddenException(__('identity.impersonation.forbidden'));
        }

        RateLimiter::hit($key, 300);

        $this->impersonator->start($actor, $contact, $request->string('reason')->toString());

        return redirect()->to('/client')->with(
            'status',
            __('identity.impersonation.started', ['name' => $contact->displayName()]),
        );
    }

    public function destroy(): RedirectResponse
    {
        $actor = $this->impersonator->stop();

        if ($actor === null) {
            return redirect()->to('/admin/login');
        }

        return redirect()->to('/admin')->with('status', __('identity.impersonation.stopped'));
    }
}
