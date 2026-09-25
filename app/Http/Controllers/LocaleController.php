<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Identity\CurrentActor;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Somebody choosing the language they read the panel in.
 *
 * One controller for both areas, like the other shared auth-adjacent ones:
 * the write is the same row-with-a-column either way, and the actor decides
 * whose it is. Nobody can set anybody else's — there is no id in the
 * payload, only a language.
 *
 * Not audited. A person's own reading language is not a security event, and
 * an audit row for every click of a two-item switch is noise in the log that
 * matters.
 */
final class LocaleController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locales::supported())],
        ]);

        $subject = $this->actor->model();

        if (! $subject instanceof Model) {
            abort(403);
        }

        $subject->forceFill(['locale' => $validated['locale']])->save();

        // Back to where they were, in the new language. The whole page is
        // re-rendered by the redirect, which is the point: every string on
        // it comes from the server.
        return back();
    }
}
