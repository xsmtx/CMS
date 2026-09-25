<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Identity\Models\Contact;
use App\Support\Identity\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The interface in the language of the person reading it.
 *
 * Every string in this product is translatable and every one of them was
 * written twice, and none of it was reachable: `locale` on a staff user and
 * on a contact was read by exactly one thing — the notifier, choosing which
 * wording to email — while the panel itself rendered whatever
 * `config('app.locale')` said, for everybody, for ever. A column stored and
 * read by nothing is the rule this breaks at the largest scale in the
 * product.
 *
 * The chain is the person, then the account they belong to, then the
 * installation. A contact with no preference of their own gets their
 * customer's, because that is the language somebody chose when the account
 * was opened; a staff member's falls straight through to the installation.
 *
 * Only a locale this installation actually ships is accepted. A value from
 * an older import or a hand-edited row cannot make the panel render its own
 * translation keys.
 */
final readonly class SetLocale
{
    public function __construct(private CurrentActor $actor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->preferred();

        if ($locale !== null) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    private function preferred(): ?string
    {
        $subject = $this->actor->model();

        if ($subject === null) {
            return null;
        }

        $own = $this->supported($subject->getAttribute('locale'));

        if ($own !== null) {
            return $own;
        }

        // A contact's account answers when the person has not. Resolved
        // through the relation rather than a query of its own, so the
        // organization boundary applies exactly as it does everywhere else.
        return $subject instanceof Contact
            ? $this->supported($subject->owningCustomer()->locale)
            : null;
    }

    private function supported(mixed $locale): ?string
    {
        if (! is_string($locale) || $locale === '') {
            return null;
        }

        /** @var list<string> $available */
        $available = (array) config('platform.locales', []);

        return in_array($locale, $available, true) ? $locale : null;
    }
}
