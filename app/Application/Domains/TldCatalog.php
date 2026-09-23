<?php

declare(strict_types=1);

namespace App\Application\Domains;

use App\Domain\Domains\DomainAction;
use App\Domain\Domains\DomainName;
use App\Domain\Shared\Money;
use App\Infrastructure\Domains\Models\Tld;
use Illuminate\Support\Collection;

/**
 * What this installation sells, and for how much.
 *
 * The one place a TLD's price is read. Everything else — search, the cart,
 * fulfilment, the renewal run Phase 9 will write — asks here, so "is this
 * sold" has a single answer rather than one per caller.
 *
 * Absence means not sold ([ADR 0019](../../../docs/adr/0019-price-matrix.md)).
 * A TLD with no register row in the requested currency is not offered and
 * is not searched for: asking a registry about a name nobody can buy wastes
 * a call and produces a page a customer cannot act on.
 */
final class TldCatalog
{
    /**
     * @var Collection<int, Tld>|null
     */
    private ?Collection $cached = null;

    /**
     * @return Collection<int, Tld>
     */
    public function sellable(): Collection
    {
        return $this->cached ??= Tld::query()
            ->sellable()
            ->with('prices')
            ->orderBy('position')
            ->orderBy('extension')
            ->get();
    }

    /**
     * The extensions a name can be parsed against.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return array_values($this->sellable()
            ->map(static fn (Tld $tld): string => $tld->extension)
            ->all());
    }

    public function find(string $extension): ?Tld
    {
        $needle = mb_strtolower(ltrim($extension, '.'));

        return $this->sellable()->first(
            static fn (Tld $tld): bool => $tld->extension === $needle,
        );
    }

    /**
     * Parse a name against the extensions actually on sale.
     */
    public function parse(string $input): DomainName
    {
        return DomainName::parseWithin($input, $this->extensions());
    }

    /**
     * What a name costs, or null when it is not sold that way.
     */
    public function priceFor(
        string $extension,
        DomainAction $action,
        int $years,
        string $currencyCode,
    ): ?Money {
        return $this->find($extension)?->priceFor($action, $years, $currencyCode);
    }

    /**
     * The cheapest register price, for a listing that wants "from".
     */
    public function fromPrice(Tld $tld, string $currencyCode): ?Money
    {
        $terms = $tld->termsFor(DomainAction::Register, $currencyCode);

        if ($terms === []) {
            return null;
        }

        return $tld->priceFor(DomainAction::Register, $terms[0], $currencyCode);
    }

    /**
     * The TLDs with at least one register price in this currency.
     *
     * @return Collection<int, Tld>
     */
    public function offeredIn(string $currencyCode): Collection
    {
        return $this->sellable()->filter(
            fn (Tld $tld): bool => $tld->termsFor(DomainAction::Register, $currencyCode) !== [],
        );
    }

    /**
     * Drop the memoised list. Only for tests and for a save that has just
     * changed the catalog under a long-running process.
     */
    public function forget(): void
    {
        $this->cached = null;
    }
}
