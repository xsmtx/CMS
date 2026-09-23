<?php

declare(strict_types=1);

namespace App\Application\Shared;

use App\Infrastructure\Identity\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

/**
 * What an operator typed into a search box, turned into a LIKE pattern.
 *
 * Two rules, and both come from watching people use one.
 *
 * **`%` belongs to the operator.** Somebody who types `Zeyn%` means "starts
 * with Zeyn", and `%nep` means "ends with nep". Escaping it into a literal
 * per cent sign — which is what a naive search does — answers a question
 * nobody asked and returns nothing. So when the term contains a `%` it is
 * used exactly as written, and only then; a plain term is still wrapped in
 * wildcards so that typing a fragment keeps working.
 *
 * `_` is never a wildcard here. It is a character in half the email
 * addresses in the table, and an operator searching `zeynep_kaya` means
 * that address rather than "any character between".
 *
 * **A person's name is two columns and one word.** Nobody searches for
 * "Zeynep" when they are looking at "Zeynep Kaya" on a ticket; they type
 * what they see. So `name()` matches the columns joined, in both orders,
 * because a Turkish list is as often sorted by surname.
 */
final readonly class SearchPattern
{
    /**
     * The pattern itself.
     *
     * The backslash is escaped first and always: MariaDB reads one inside a
     * LIKE as the escape character, so escaping the wildcards without
     * escaping the escape character is how a search looks fixed and still
     * matches nothing.
     */
    public static function like(string $term): string
    {
        $value = str_replace(['\\', '_'], ['\\\\', '\_'], trim($term));

        // A term the operator already anchored is left alone. Wrapping it
        // would turn "Zeyn%" back into "contains Zeyn", which is the
        // opposite of what they asked for.
        return str_contains($value, '%') ? $value : '%'.$value.'%';
    }

    /**
     * Match a person against the term, whole name included.
     *
     * Unqualified columns on purpose. Both callers are querying contacts —
     * one directly, one inside a `whereHas` — and neither joins a second
     * table that has a `first_name`, so there is nothing to disambiguate
     * and the SQL stays a literal string.
     *
     * @param  Builder<Contact>  $query
     */
    public static function name(Builder $query, string $pattern): void
    {
        $query->orWhere('first_name', 'like', $pattern)
            ->orWhere('last_name', 'like', $pattern)
            // Both orders: a Turkish list is as often read surname first,
            // and an operator types what is in front of them.
            ->orWhereRaw("concat(first_name, ' ', last_name) like ?", [$pattern])
            ->orWhereRaw("concat(last_name, ' ', first_name) like ?", [$pattern]);
    }
}
