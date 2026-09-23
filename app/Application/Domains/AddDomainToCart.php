<?php

declare(strict_types=1);

namespace App\Application\Domains;

use App\Application\Domains\Exceptions\DomainNotSellable;
use App\Domain\Domains\DomainAction;
use App\Domain\Ordering\LineKind;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Domains\Models\Tld;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\CartItem;

/**
 * Puts a name in the cart at the price on the board.
 *
 * The price is **read from the matrix now and written onto the line**, the
 * same copy rule as every other line
 * ([ADR 0021](../../../docs/adr/0021-order-lines-copy-the-catalog.md)). A
 * customer who was shown 12.00 pays 12.00, whatever an operator does to the
 * matrix while the cart sits open.
 *
 * Availability is deliberately **not** re-checked here. It was checked a
 * moment ago in the search, re-checking costs another registry call on
 * every click, and the answer would still be stale by checkout — the
 * registration itself is the only authority, which is why it can fail and
 * why failure is a state.
 */
final readonly class AddDomainToCart
{
    public function __construct(private TldCatalog $catalog) {}

    /**
     * @param  list<string>  $addons  dns_management, email_forwarding, id_protection
     * @param  int|null  $overrideMinor  what an operator agreed instead; null is not zero
     */
    public function handle(
        Cart $cart,
        string $input,
        int $years,
        DomainAction $action = DomainAction::Register,
        array $addons = [],
        ?int $overrideMinor = null,
    ): CartItem {
        $name = $this->catalog->parse($input);
        $tld = $this->catalog->find($name->tld);

        if (! $tld instanceof Tld) {
            throw DomainNotSellable::noPrice($name->tld, $cart->currency_code);
        }

        if (! $tld->allowsTerm($years)) {
            // The registry's rule, not a preference. A customer told
            // otherwise finds out after paying.
            throw DomainNotSellable::termRefused($tld->extension, $years);
        }

        $price = $tld->priceFor($action, $years, $cart->currency_code);

        if ($price === null) {
            throw DomainNotSellable::noPrice($tld->extension, $cart->currency_code);
        }

        // A name this installation already holds cannot be sold again —
        // not registered, and not transferred to itself.
        if ($this->alreadyHeld((string) $name)) {
            throw DomainNotSellable::alreadyHeld((string) $name);
        }

        $existing = $cart->allItems()
            ->where('kind', LineKind::Domain->value)
            ->where('domain', (string) $name)
            ->first();

        if ($existing instanceof CartItem) {
            // Adding the same name twice is a double-click, not an order
            // for two of them.
            return $existing;
        }

        $position = (int) $cart->allItems()->max('position');

        return $cart->allItems()->create([
            'organization_id' => $cart->organization_id,
            'kind' => LineKind::Domain->value,
            'quantity' => 1,
            'domain' => (string) $name,
            'domain_tld' => $name->tld,
            'domain_years' => $years,
            // The price on the board at this moment, or what an operator
            // agreed instead. Either way it is written onto the line and
            // never read from the matrix again (ADR 0021).
            'domain_registration_minor' => $overrideMinor ?? $price->minorUnits,
            'domain_action' => $action->value,
            'domain_addons' => $addons === [] ? null : array_values($addons),
            'position' => $position + 1,
        ]);
    }

    private function alreadyHeld(string $name): bool
    {
        return Domain::query()
            ->where('name', $name)
            ->whereNotIn('status', ['cancelled', 'deleted'])
            ->exists();
    }
}
