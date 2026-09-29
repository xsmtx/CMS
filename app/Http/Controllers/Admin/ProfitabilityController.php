<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Intelligence\Profitability;
use App\Application\Intelligence\ProfitRow;
use App\Application\Reports\MoneyByCurrency;
use App\Domain\Intelligence\ProfitGrouping;
use App\Http\Controllers\Controller;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * What a month earned and what it cost to earn it (§21).
 *
 * **A margin is stated only where it can honestly be stated.** There is no
 * rate anywhere in this product, so a customer earning euros on a server
 * costing lira has a revenue, a cost and no margin — and printing the
 * revenue as though the cost were zero would be the single most misleading
 * number this product could produce.
 *
 * The month is in the address bar, because somebody looking at July is about
 * to send July to a colleague. A month that will not parse falls back to
 * this one rather than refusing: they edited the URL, and the useful answer
 * is the month they are standing in.
 */
final class ProfitabilityController extends Controller
{
    public function index(
        Request $request,
        CurrentActor $actor,
        OrganizationContext $organizations,
        Profitability $profitability,
    ): Response {
        if (! $actor->can('intelligence.commercial.view')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        $month = $this->month($request);
        $grouping = ProfitGrouping::tryFrom((string) $request->query('by', 'customer'))
            ?? ProfitGrouping::Customer;

        $organizationId = $organizations->id();

        if ($organizationId === null) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        $report = $profitability->forMonth($organizationId, $month, $grouping);

        return Inertia::render('Admin/Intelligence/Profitability', [
            // A date, worded at the edge like every other date this
            // product sends — `toLocaleDateString` follows the reader.
            'month' => $month->toDateString(),
            'previous' => $month->subMonth()->toDateString(),
            'next' => $month->addMonth()->toDateString(),
            'grouping' => $grouping->value,
            'groupings' => array_map(
                static fn (ProfitGrouping $by): array => [
                    'value' => $by->value,
                    'label' => (string) __($by->labelKey()),
                ],
                ProfitGrouping::cases(),
            ),
            'rows' => array_map($this->row(...), $report['rows']),
            'totals' => [
                'revenue' => $report['revenue']->toArray(app()->getLocale()),
                'cost' => $report['cost']->toArray(app()->getLocale()),
                'unallocated' => $report['unallocated']->toArray(app()->getLocale()),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ProfitRow $row): array
    {
        $margin = $row->margin();

        return [
            'key' => $row->key,
            'label' => $row->label,
            'services' => $row->services,
            'revenue' => $row->revenue->toArray(app()->getLocale()),
            'cost' => $row->cost->toArray(app()->getLocale()),
            // Null rather than a number, and the screen says why. A margin
            // invented across currencies is the one figure that would make
            // this whole report worse than not having it.
            'margin' => $margin instanceof MoneyByCurrency
                ? $margin->toArray(app()->getLocale())
                : null,
            'mixedCurrency' => $row->isMixedCurrency(),
        ];
    }

    private function month(Request $request): CarbonImmutable
    {
        $value = $request->query('month');

        if (! is_string($value) || $value === '') {
            return CarbonImmutable::now()->startOfMonth();
        }

        try {
            return CarbonImmutable::parse($value)->startOfMonth();
        } catch (Throwable) {
            // They edited the address bar. The useful answer is the month
            // they are standing in, not a refusal.
            return CarbonImmutable::now()->startOfMonth();
        }
    }
}
