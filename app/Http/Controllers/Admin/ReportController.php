<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Reports\AgingReport;
use App\Application\Reports\RecurringRevenueReport;
use App\Application\Reports\ReportPeriod;
use App\Application\Reports\RevenueBreakdownReport;
use App\Http\Controllers\Controller;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The monthly review, on one page.
 *
 * One screen rather than six, because that is how the question is actually
 * asked: somebody sits down once a month, sets a period, and reads recurring
 * revenue, movement, what is owed, what is coming and where it came from in one
 * pass — then screenshots it. Six screens with six period pickers would be six
 * chances for two of them to cover different months.
 *
 * **Boundary-scoped, so a reseller reads their own.** Every query goes through
 * the models' global scope or applies the boundary by hand, which means the same
 * screen answers the provider's question and a reseller's without a second
 * implementation. A test drives a reseller at it.
 *
 * Authorized on `billing.invoices.view`, which is the narrowest permission that
 * covers what is on the page: these are money figures, and the roles that hold
 * that permission are the roles that already see every invoice.
 */
final class ReportController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function __invoke(
        Request $request,
        RecurringRevenueReport $recurring,
        AgingReport $aging,
        RevenueBreakdownReport $breakdown,
    ): Response {
        if (! $this->actor->can('billing.invoices.view')) {
            throw new ForbiddenException(__('reports.errors.not_permitted'));
        }

        $period = ReportPeriod::fromInput(
            $request->string('from')->toString(),
            $request->string('to')->toString(),
        );

        $revenue = $recurring->handle($period);

        // The chart needs one currency, because a bar chart of two currencies is
        // two charts. The largest MRR currency is the one the business is in;
        // an installation with no services yet gets the configured default.
        $chartCurrency = $revenue['mrr'][0]['currency']
            ?? strtoupper((string) config('platform.crm.default_currency', 'TRY'));

        return Inertia::render('Admin/Reports/Index', [
            'period' => $period->toArray(),
            'revenue' => $revenue,
            'aging' => $aging->handle(),
            'breakdown' => $breakdown->handle($period),
            'renewals' => $breakdown->renewalsAhead(),
            'collected' => [
                'currency' => $chartCurrency,
                'months' => $this->chart($recurring->collectedByMonth($period, $chartCurrency)),
            ],
        ]);
    }

    /**
     * The chart's rows, labelled the way a person reads a month.
     *
     * `2026-03` is a key, not a label. The chart shows `Mar 2026`, and the key
     * stays out of the payload entirely — a screen that formatted it would be a
     * screen doing date arithmetic in a template.
     *
     * @param  array<string, int>  $byMonth
     * @return list<array{label: string, value: int}>
     */
    private function chart(array $byMonth): array
    {
        $rows = [];

        foreach ($byMonth as $month => $minor) {
            // `createFromFormat` can return null on a string that does not
            // match, and the keys come from `ReportPeriod` rather than from a
            // request — so the fallback is the key itself, which is legible
            // enough and cannot be a crash.
            $at = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');

            $rows[] = [
                'label' => $at instanceof CarbonImmutable ? $at->format('M Y') : $month,
                'value' => $minor,
            ];
        }

        return $rows;
    }
}
