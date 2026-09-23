<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Domain\Crm\CustomerStatus;
use App\Domain\Crm\CustomFieldEntity;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use App\Infrastructure\Provisioning\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Finding a customer, the way an operator actually looks for one.
 *
 * WHMCS operators search by everything: a phone number a customer gave on
 * the telephone, the last four digits of a card on a chargeback notice, a
 * postcode on a returned letter. So the advanced panel exists, and so does
 * the plain box above it — because nine times out of ten a name is enough.
 *
 * **Every criterion is declared and every criterion is real.** A filter that
 * silently matches nothing because the column does not exist is worse than
 * no filter: an operator concludes the customer is not there. So anything
 * this platform does not actually record is absent rather than present and
 * broken, and the fields it does record are searched against the rows they
 * genuinely live in — an address on `addresses`, a card's last four on
 * `payment_methods`, a language on `contacts`.
 *
 * **Anything local belongs in a custom field.** A Turkish installation
 * wants T.C. Kimlik Numarası, Vergi Dairesi and a separate mobile number; a
 * German one wants Handelsregisternummer. Hard-coding either into an
 * international product means the other is a second-class citizen forever.
 * Custom fields already exist (Phase 1), so every customer custom field an
 * installation has defined appears in this panel automatically, typed the
 * way it was defined.
 */
final readonly class SearchCustomers
{
    /**
     * Text criteria: name → the column and relation to match it against.
     *
     * Declared here rather than assembled in the controller, so that the
     * screen can be generated from the same list that executes — a filter
     * offered but not implemented is the failure this whole class is
     * written to avoid.
     */
    private const array TEXT = [
        'email' => 'contact:email',
        'phone' => 'contact:phone',
        'address_one' => 'address:line_one',
        'address_two' => 'address:line_two',
        'city' => 'address:city',
        'region' => 'address:region',
        'postcode' => 'address:postal_code',
        'tax_id' => 'customer:tax_id',
        'card_last_four' => 'card:last_four',
    ];

    /**
     * Exact criteria, offered as a list of what is actually in use.
     */
    private const array EXACT = [
        'status' => 'customer:status',
        'currency' => 'customer:currency_code',
        'country' => 'address:country_code',
        'locale' => 'contact:locale',
        'gateway' => 'card:gateway',
        'card_brand' => 'card:brand',
    ];

    /**
     * @param  Builder<Customer>  $query
     * @param  array<string, mixed>  $criteria
     * @return Builder<Customer>
     */
    public function apply(Builder $query, array $criteria): Builder
    {
        $term = $this->text($criteria, 'search');

        if ($term !== null) {
            // The same columns `Customer::scopeSearch` matches, inlined
            // because PHPStan cannot see a model scope through a generic
            // builder — and a search that silently stopped matching
            // contacts would be worse than a little repetition.
            $query->where(function (Builder $inner) use ($term): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $inner->where('company_name', 'like', $like)
                    ->orWhere('legal_name', 'like', $like)
                    ->orWhere('tax_id', 'like', $like)
                    ->orWhereHas('contacts', function (Builder $contacts) use ($like): void {
                        $contacts->where('email', 'like', $like)
                            ->orWhere('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like);
                    });
            });
        }

        foreach (self::TEXT as $name => $target) {
            $value = $this->text($criteria, $name);

            if ($value !== null) {
                $this->matchLike($query, $target, $value);
            }
        }

        foreach (self::EXACT as $name => $target) {
            $value = $this->text($criteria, $name);

            if ($value !== null) {
                $this->matchExact($query, $target, $value);
            }
        }

        $this->applyFlags($query, $criteria);
        $this->applyDates($query, $criteria);
        $this->applyTags($query, $criteria);
        $this->applyPermissions($query, $criteria);
        $this->applyCustomFields($query, $criteria);

        return $query;
    }

    /**
     * The list itself.
     *
     * Built here rather than in the controller because it is a query, and
     * a controller that assembles one is a controller that will eventually
     * own a rule. The architecture tests enforce that, and they are right
     * to: the service counts below are exactly the sort of thing that
     * grows into business logic if it lives in the wrong layer.
     *
     * **Closed accounts are excluded by default.** A closed customer is a
     * record the accounts department keeps, not somebody an operator is
     * working with, and after a few years they are most of the table. An
     * explicit status filter wins: somebody who asked for closed accounts
     * meant it.
     *
     * @param  array<string, mixed>  $criteria
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(array $criteria, bool $includeClosed = false): LengthAwarePaginator
    {
        $query = $this->apply(Customer::query(), $criteria);

        if (! $includeClosed && $this->text($criteria, 'status') === null) {
            $query->whereNot('status', CustomerStatus::Closed->value);
        }

        return $query
            ->with([
                'primaryContact:id,customer_id,first_name,last_name,email',
                'tags:id,name',
            ])
            // Counted in the list query, never read per row: a hundred
            // customers on a page is a hundred queries the moment somebody
            // touches `$customer->services`.
            ->withCount([
                'services as active_services_count' => $this->activeServices(...),
                'services as inactive_services_count' => $this->inactiveServices(...),
            ])->latest()
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * What the screen should offer, built from what this installation has.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'text' => array_keys(self::TEXT),
            'exact' => array_keys(self::EXACT),
            'customFields' => $this->customFields()
                ->map(static fn (CustomFieldDefinition $field): array => [
                    'key' => $field->key,
                    'label' => $field->label,
                    'type' => $field->type->value,
                    'options' => $field->options ?? [],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Named methods rather than inline closures, because that is the only
     * way to give the constrained relation its element type — and a count
     * typed as `Builder<Model>` is one `where('status', ...)` away from a
     * runtime type error that no test with a single customer would catch.
     *
     * @param  Builder<Service>  $services
     */
    private function activeServices(Builder $services): void
    {
        $services->where('status', ServiceStatus::Active->value);
    }

    /**
     * @param  Builder<Service>  $services
     */
    private function inactiveServices(Builder $services): void
    {
        $services->where('status', '!=', ServiceStatus::Active->value);
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  array<string, mixed>  $criteria
     */
    private function applyFlags(Builder $query, array $criteria): void
    {
        $marketing = $this->boolean($criteria, 'marketing_opt_in');

        if ($marketing !== null) {
            $query->where('marketing_opt_in', $marketing);
        }

        $corporate = $this->boolean($criteria, 'corporate');

        if ($corporate !== null) {
            // A company name is what makes a customer a business here.
            $corporate
                ? $query->whereNotNull('company_name')->where('company_name', '!=', '')
                : $query->where(fn (Builder $inner) => $inner->whereNull('company_name')->orWhere('company_name', ''));
        }

        $validated = $this->boolean($criteria, 'tax_id_validated');

        if ($validated !== null) {
            // Named for what it is rather than "tax exempt": this platform
            // never decides a country's tax rules (ADR 0022), it records
            // whether a number was checked.
            $validated
                ? $query->whereNotNull('tax_id_validated_at')
                : $query->whereNull('tax_id_validated_at');
        }

        $hasCard = $this->boolean($criteria, 'has_card');

        if ($hasCard !== null) {
            $hasCard
                ? $query->whereHas('paymentMethods')
                : $query->whereDoesntHave('paymentMethods');
        }
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  array<string, mixed>  $criteria
     */
    private function applyDates(Builder $query, array $criteria): void
    {
        $from = $this->text($criteria, 'signed_up_from');
        $to = $this->text($criteria, 'signed_up_to');

        if ($from !== null) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('created_at', '<=', $to);
        }
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  array<string, mixed>  $criteria
     */
    private function applyTags(Builder $query, array $criteria): void
    {
        $tag = $this->text($criteria, 'tag');

        if ($tag === null) {
            return;
        }

        // Tags are this platform's client groups. A customer can be in
        // several, which WHMCS's single group cannot express.
        $query->whereHas('tags', fn (Builder $tags) => $tags->where('tags.id', $tag));
    }

    /**
     * Customers who have somebody allowed to do a thing.
     *
     * Asked of permissions rather than of a flag per capability, because
     * that is how this platform models what a contact may do — there is no
     * `can_open_tickets` column and there should not be one.
     *
     * @param  Builder<Customer>  $query
     * @param  array<string, mixed>  $criteria
     */
    private function applyPermissions(Builder $query, array $criteria): void
    {
        /** @var list<string> $permissions */
        $permissions = is_array($criteria['permissions'] ?? null)
            ? array_values(array_filter($criteria['permissions'], is_string(...)))
            : [];

        foreach ($permissions as $permission) {
            $query->whereHas('contacts', function (Builder $contacts) use ($permission): void {
                $contacts->where('portal_access', true)
                    ->whereHas('roles.permissions', fn (Builder $p) => $p->where('slug', $permission));
            });
        }
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  array<string, mixed>  $criteria
     */
    private function applyCustomFields(Builder $query, array $criteria): void
    {
        /** @var array<string, mixed> $values */
        $values = is_array($criteria['custom'] ?? null) ? $criteria['custom'] : [];

        if ($values === []) {
            return;
        }

        $defined = $this->customFields()->keyBy(
            static fn (CustomFieldDefinition $field): string => $field->key,
        );

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_string($value) || trim($value) === '') {
                continue;
            }

            // An undefined key is somebody's stale bookmark, not a search.
            if (! $defined->has($key)) {
                continue;
            }

            $definition = $defined->get($key);

            $definitionId = $definition?->id;
            $term = trim($value);

            $query->whereHas('customFieldValues', function (Builder $inner) use ($definitionId, $term): void {
                $inner->where('definition_id', $definitionId)
                    ->where(function (Builder $match) use ($term): void {
                        foreach ($this->needles($term) as $needle) {
                            $match->orWhere('value', 'like', $needle);
                        }
                    });
            });
        }
    }

    /**
     * The forms a value could be stored in.
     *
     * A custom field's value is cast to JSON, and `json_encode` escapes
     * anything non-ASCII: "Kadıköy" is stored as `"Kadıköy"`.
     * Matching only the plain string means **every search for a Turkish,
     * German or Greek value silently returns nothing** — the exact failure
     * this class exists to avoid, arrived at through a cast nobody was
     * thinking about.
     *
     * So both forms are tried. An installation whose values predate the
     * cast still matches, and so does one whose values do not.
     *
     * @return list<string>
     */
    private function needles(string $term): array
    {
        // The backslash first, and it matters: a JSON-escaped value is
        // full of them, and MySQL reads a backslash in a LIKE as an
        // escape character. Escaping the wildcards without escaping the
        // escape character is how this looks fixed and still matches
        // nothing.
        $escape = static fn (string $value): string => '%'
            .str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value)
            .'%';

        $encoded = trim(json_encode($term, JSON_THROW_ON_ERROR), '"');

        return $encoded === $term
            ? [$escape($term)]
            : [$escape($term), $escape($encoded)];
    }

    /**
     * @return Collection<int, CustomFieldDefinition>
     */
    private function customFields(): Collection
    {
        return CustomFieldDefinition::query()
            ->where('entity_type', CustomFieldEntity::Customer->value)
            ->orderBy('position')
            ->get();
    }

    /**
     * @param  Builder<Customer>  $query
     */
    private function matchLike(Builder $query, string $target, string $value): void
    {
        [$relation, $column] = explode(':', $target, 2);

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $value).'%';

        match ($relation) {
            'customer' => $query->where($column, 'like', $like),
            'contact' => $query->whereHas('contacts', fn (Builder $q) => $q->where($column, 'like', $like)),
            'address' => $query->whereHas('addresses', fn (Builder $q) => $q->where($column, 'like', $like)),
            'card' => $query->whereHas('paymentMethods', fn (Builder $q) => $q->where($column, 'like', $like)),
            default => null,
        };
    }

    /**
     * @param  Builder<Customer>  $query
     */
    private function matchExact(Builder $query, string $target, string $value): void
    {
        [$relation, $column] = explode(':', $target, 2);

        match ($relation) {
            'customer' => $query->where($column, $value),
            'contact' => $query->whereHas('contacts', fn (Builder $q) => $q->where($column, $value)),
            'address' => $query->whereHas('addresses', fn (Builder $q) => $q->where($column, $value)),
            'card' => $query->whereHas('paymentMethods', fn (Builder $q) => $q->where($column, $value)),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function text(array $criteria, string $key): ?string
    {
        $value = $criteria[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Three states, not two: unset means "do not filter on this at all",
     * which a plain boolean cannot say.
     *
     * @param  array<string, mixed>  $criteria
     */
    private function boolean(array $criteria, string $key): ?bool
    {
        $value = $criteria[$key] ?? null;

        if (in_array($value, [null, '', 'any'], true)) {
            return null;
        }

        return in_array($value, ['1', 1, true, 'true', 'yes'], true);
    }
}
