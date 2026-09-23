<?php

declare(strict_types=1);

namespace App\Application\Domains;

use App\Domain\Domains\DomainAction;
use App\Domain\Domains\DomainStatus;
use App\Domain\Ordering\LineKind;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Domains\Models\Tld;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns the domain lines of a paid order into names the platform holds.
 *
 * The label and the extension are copied, like everything else along this
 * chain ([ADR 0021](../../../docs/adr/0021-order-lines-copy-the-catalog.md)):
 * a TLD removed from the catalog next year must not change what an existing
 * domain is called.
 *
 * Called whenever an order reaches `paid`, which can happen more than once.
 * Two unique indexes make that safe — one per order line, one per name —
 * and the second is the interesting one: two customers ordering the same
 * name in the same minute both get an order, and only the first gets the
 * domain. The second is left for an operator, which is the honest outcome
 * of a race nobody can win twice.
 */
final readonly class CreateDomainsForOrder
{
    public function __construct(private TldCatalog $catalog) {}

    /**
     * @return list<Domain>
     */
    public function handle(Order $order, ?Model $actor = null): array
    {
        $order->loadMissing(['items', 'customer']);

        $created = [];

        foreach ($order->items as $item) {
            if ($item->kind !== LineKind::Domain || $item->domain === null) {
                continue;
            }

            $domain = $this->domainFor($order, $item, $actor);

            if ($domain instanceof Domain) {
                $created[] = $domain;
            }
        }

        return $created;
    }

    private function domainFor(Order $order, OrderItem $item, ?Model $actor): ?Domain
    {
        if (Domain::query()->where('order_item_id', $item->id)->exists()) {
            // Seen before. The first run did the work.
            return null;
        }

        try {
            $name = $this->catalog->parse((string) $item->domain);
        } catch (Throwable) {
            // A line naming an extension the installation no longer sells.
            // Recorded as a domain with no TLD so an operator can see it
            // rather than silently dropped.
            return $this->orphan($order, $item, $actor);
        }

        $tld = $this->catalog->find($name->tld);

        try {
            $domain = DB::transaction(fn (): Domain => Domain::query()->create([
                'organization_id' => $order->organization_id,
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'tld_id' => $tld?->id,
                'registrar' => $tld?->registrar,
                'status' => DomainStatus::Pending->value,
                'label' => $name->sld,
                'extension' => $name->tld,
                'name' => (string) $name,
                'years' => $item->domain_years ?? 1,
                'currency_code' => $order->currency_code,
                'renewal_minor' => $this->renewalPrice($tld, $item, $order->currency_code),
                'whois_privacy' => $tld !== null && $tld->allows_whois_privacy,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Either two runs raced, or somebody else already holds this
            // name here. Both end the same way: the row that exists wins.
            return null;
        }

        Audit::action('domains.domain.created')
            ->by($actor)
            ->on($domain)
            ->forOrganization($domain->organization_id)
            ->withMetadata(['order' => $order->number, 'name' => $domain->name])
            ->write();

        return $domain;
    }

    /**
     * A domain line whose extension is no longer sold.
     *
     * It was paid for, so it exists; it has no registrar, so nothing will
     * try to register it automatically. An operator decides.
     */
    private function orphan(Order $order, OrderItem $item, ?Model $actor): ?Domain
    {
        $raw = mb_strtolower((string) $item->domain);
        $parts = explode('.', $raw, 2);

        try {
            $domain = Domain::query()->create([
                'organization_id' => $order->organization_id,
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'registrar' => null,
                'status' => DomainStatus::Pending->value,
                'label' => $parts[0],
                'extension' => $parts[1] ?? '',
                'name' => $raw,
                'years' => $item->domain_years ?? 1,
                'currency_code' => $order->currency_code,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        Audit::action('domains.domain.created')
            ->by($actor)
            ->on($domain)
            ->forOrganization($domain->organization_id)
            ->because(__('domains.errors.unsupported_tld'))
            ->write();

        return $domain;
    }

    /**
     * What a renewal will cost, recorded now.
     *
     * The renewal price at the time of purchase is what a customer was
     * shown; Phase 9 will decide whether to honour it or to re-read the
     * matrix, and it cannot decide either without this number.
     */
    private function renewalPrice(?Tld $tld, OrderItem $item, string $currencyCode): int
    {
        if ($tld === null) {
            return $item->line_total->minorUnits;
        }

        $years = $item->domain_years ?? 1;
        $renewal = $tld->priceFor(DomainAction::Renew, $years, $currencyCode);

        return $renewal === null ? $item->line_total->minorUnits : $renewal->minorUnits;
    }
}
