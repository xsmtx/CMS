<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Domain\Billing\PaymentStatus;
use App\Domain\Catalog\BillingCycle;
use App\Infrastructure\Billing\Models\Payment;
use App\Infrastructure\Provisioning\Models\Service;
use Carbon\CarbonImmutable;

/**
 * Where the money came from: by product, and by gateway.
 *
 * Two questions that look similar and are answered from different tables,
 * deliberately.
 *
 * **By gateway is what was actually collected**, from `payments` — the only place
 * that knows which gateway took the money. Net of refunds, because a gateway that
 * took 1,000 and gave 400 back brought in 600, and a report showing the gross
 * would make a refund-heavy month look like a good one.
 *
 * **By product is what recurs**, from the services, normalised to a month. It is
 * deliberately *not* built from invoice lines: a line here copies a description
 * rather than referencing a product (ADR 0021), which is right for a frozen
 * document and useless for grouping — two products renamed the same thing would
 * merge, and one renamed last March would split in half. The services table has
 * the product id.
 *
 * So the two blocks answer "which gateway is worth keeping" and "which product is
 * the business", and the screen does not add them together.
 */
final readonly class RevenueBreakdownReport
{
    /**
     * @return array<string, mixed>
     */
    public function handle(ReportPeriod $period): array
    {
        return [
            'gateways' => $this->byGateway($period),
            'products' => $this->byProduct(),
        ];
    }

    /**
     * What is due to renew, in windows an operator plans around.
     *
     * Ahead rather than behind, and counted from today rather than from the
     * report period: "what is coming" is not a question about last quarter.
     *
     * @return list<array<string, mixed>>
     */
    public function renewalsAhead(?CarbonImmutable $from = null): array
    {
        $from = ($from ?? CarbonImmutable::now())->startOfDay();

        $windows = [30, 60, 90];
        $rows = [];

        foreach ($windows as $days) {
            $to = $from->addDays($days);

            $services = Service::query()
                ->toBase()
                ->select(['billing_cycle', 'currency_code'])
                ->selectRaw('count(*) as services')
                ->selectRaw('sum(recurring_minor) as total')
                ->where('status', 'active')
                ->whereBetween('next_due_on', [$from->toDateString(), $to->toDateString()])
                ->groupBy('billing_cycle', 'currency_code')
                ->get();

            $count = 0;
            $money = new MoneyByCurrency;

            foreach ($services as $service) {
                $count += (int) $service->services;
                // The whole amount, not a monthly share: a renewal invoice is
                // for the term, and this figure is what will be billed.
                $money->add((string) $service->currency_code, (int) $service->total);
            }

            $rows[] = [
                'days' => $days,
                'services' => $count,
                'value' => $money->toArray(app()->getLocale()),
            ];
        }

        return $rows;
    }

    /**
     * What each gateway collected in the period, net of what it gave back.
     *
     * @return list<array<string, mixed>>
     */
    private function byGateway(ReportPeriod $period): array
    {
        $rows = Payment::query()
            ->toBase()
            ->select(['gateway', 'currency_code'])
            ->selectRaw('sum(amount_minor) as taken')
            ->selectRaw('sum(refunded_minor) as returned')
            ->selectRaw('count(*) as payments')
            ->where('status', PaymentStatus::Completed->value)
            ->whereBetween('received_at', [$period->from, $period->to])
            ->groupBy('gateway', 'currency_code')
            ->get();

        $byGateway = [];

        foreach ($rows as $row) {
            $gateway = (string) $row->gateway;

            $byGateway[$gateway] ??= [
                'gateway' => $gateway,
                'label' => (string) __('billing.gateways.'.$gateway),
                'payments' => 0,
                'net' => new MoneyByCurrency,
                'refunded' => new MoneyByCurrency,
            ];

            $byGateway[$gateway]['payments'] += (int) $row->payments;

            // Net, because a gateway that took 1,000 and gave 400 back brought
            // in 600 — and a gross figure makes a refund-heavy month look good.
            $byGateway[$gateway]['net']->add(
                (string) $row->currency_code,
                (int) $row->taken - (int) $row->returned,
            );

            $byGateway[$gateway]['refunded']->add((string) $row->currency_code, (int) $row->returned);
        }

        $locale = app()->getLocale();

        return array_values(array_map(
            static fn (array $row): array => [
                'gateway' => $row['gateway'],
                'label' => $row['label'],
                'payments' => $row['payments'],
                'net' => $row['net']->toArray($locale),
                'refunded' => $row['refunded']->toArray($locale),
            ],
            $byGateway,
        ));
    }

    /**
     * What each product recurs at, per month.
     *
     * From the services rather than from invoice lines. An invoice line copies a
     * description (ADR 0021) and grouping by it would merge two products renamed
     * the same thing and split one renamed last March.
     *
     * @return list<array<string, mixed>>
     */
    private function byProduct(): array
    {
        $rows = Service::query()
            ->toBase()
            ->leftJoin('products', 'products.id', '=', 'services.product_id')
            ->select([
                'services.product_id',
                'services.billing_cycle',
                'services.currency_code',
                'products.name as product_name',
            ])
            ->selectRaw('count(*) as services')
            ->selectRaw('sum(services.recurring_minor) as total')
            ->where('services.status', 'active')
            ->groupBy(
                'services.product_id',
                'services.billing_cycle',
                'services.currency_code',
                'products.name',
            )
            ->get();

        $byProduct = [];

        foreach ($rows as $row) {
            $months = BillingCycle::tryFrom((string) $row->billing_cycle)?->months() ?? 0;

            if ($months < 1) {
                continue;
            }

            // A service with no product still recurs and still counts. Grouped
            // under a name that says so rather than dropped, because a report
            // whose total does not match the MRR figure above it is a report
            // nobody trusts.
            $key = (string) ($row->product_id ?? 'unassigned');

            $byProduct[$key] ??= [
                'id' => $row->product_id,
                'name' => is_string($row->product_name) && $row->product_name !== ''
                    ? $row->product_name
                    : null,
                'services' => 0,
                'recurring' => new MoneyByCurrency,
            ];

            $byProduct[$key]['services'] += (int) $row->services;
            $byProduct[$key]['recurring']->add(
                (string) $row->currency_code,
                intdiv((int) $row->total, $months),
            );
        }

        $locale = app()->getLocale();

        $products = array_values(array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'name' => $row['name'],
                'services' => $row['services'],
                'recurring' => $row['recurring']->toArray($locale),
            ],
            $byProduct,
        ));

        // Most services first: it is the closest thing to "biggest" that works
        // across currencies, and sorting by money would need a rate.
        usort($products, static fn (array $a, array $b): int => $b['services'] <=> $a['services']);

        return $products;
    }
}
