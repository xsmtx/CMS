<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Intelligence\DetectLeakage;
use App\Application\Reports\MoneyByCurrency;
use App\Domain\Intelligence\LeakageKind;
use App\Http\Controllers\Controller;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Intelligence\Models\FindingDismissal;
use App\Infrastructure\Intelligence\Models\LeakageFinding;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money that quietly stopped arriving (§21).
 *
 * **Largest first**, which is the only sensible order for a commercial
 * screen — and it is ordered within each currency rather than across them,
 * because there is no rate anywhere in this product and sorting by a number
 * whose unit changes row to row is sorting by nothing.
 *
 * The total is a `MoneyByCurrency`: a list, never a number. And the sentence
 * beside it says **what would have been invoiced, not what is owed** —
 * nobody has been invoiced, so nothing is owed, and a screen that said
 * otherwise would be one somebody quotes at a board meeting.
 */
final class LeakageController extends Controller
{
    public function index(Request $request, CurrentActor $actor): Response
    {
        $this->refuseUnless($actor, 'intelligence.commercial.view');

        $showAll = $request->boolean('all');

        /*
         * The currencies in the order the strip above the table shows them,
         * largest total first. Ordering the table alphabetically while the
         * strip ordered by size made the two disagree — and a strip that is
         * not a legend for the table under it is a strip nobody reads twice.
         */
        $currencies = $this->currencyOrder($showAll);

        $findings = LeakageFinding::query()
            ->with('customer')
            ->unless($showAll, static fn ($query) => $query->open())
            ->when(
                $currencies !== [],
                static fn ($query) => $query->orderByRaw(
                    'field(currency_code'.str_repeat(', ?', count($currencies)).')',
                    $currencies,
                ),
            )
            ->orderByDesc('amount_minor')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Intelligence/Leakage', [
            'findings' => [
                'data' => $this->rows(array_values($findings->items())),
                'links' => $findings->linkCollection()->toArray(),
                'currentPage' => $findings->currentPage(),
                'lastPage' => $findings->lastPage(),
                'total' => $findings->total(),
            ],
            'filters' => ['all' => $showAll],
            // A list, never a number: a total across currencies is a figure
            // that means nothing and is the one somebody would quote.
            'atStake' => $this->atStake(),
            'kinds' => array_map(
                static fn (LeakageKind $kind): array => [
                    'value' => $kind->value,
                    'label' => (string) __($kind->labelKey()),
                    'description' => (string) __($kind->descriptionKey()),
                ],
                LeakageKind::cases(),
            ),
            'can' => ['dismiss' => $actor->can('intelligence.commercial.view')],
        ]);
    }

    /**
     * "This one is deliberate."
     *
     * The same table a reconciliation dismissal lives in, keyed the same
     * way: the act is identical and `source` says which family.
     */
    public function dismiss(
        Request $request,
        CurrentActor $actor,
        LeakageFinding $finding,
    ): RedirectResponse {
        $this->refuseUnless($actor, 'intelligence.commercial.view');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'until' => ['nullable', 'date', 'after:now'],
        ]);

        $staff = $this->staff($actor);

        FindingDismissal::query()->updateOrCreate(
            [
                'organization_id' => $finding->organization_id,
                'source' => DetectLeakage::Resource,
                'resource' => $finding->kind->value,
                'subject_id' => $finding->subject_id,
                'remote_key' => null,
                'field' => null,
            ],
            [
                'dismissed_by' => $staff->id,
                'reason' => $data['reason'],
                'dismissed_at' => CarbonImmutable::now(),
                'until' => isset($data['until']) ? CarbonImmutable::parse($data['until']) : null,
            ],
        );

        // Cleared rather than deleted: it was true, and the row says for how
        // long.
        $finding->cleared_at = CarbonImmutable::now();
        $finding->cleared_token = $finding->id;
        $finding->save();

        Audit::action('intelligence.leakage.dismissed')
            ->by($staff)
            ->on($finding)
            ->forOrganization($finding->organization_id)
            ->because($data['reason'])
            ->withMetadata(['kind' => $finding->kind->value])
            ->write();

        return back()->with('status', __('intelligence.reconciliation.dismissed'));
    }

    public function undismiss(CurrentActor $actor, LeakageFinding $finding): RedirectResponse
    {
        $this->refuseUnless($actor, 'intelligence.commercial.view');

        $staff = $this->staff($actor);

        FindingDismissal::query()
            ->where('organization_id', $finding->organization_id)
            ->where('source', DetectLeakage::Resource)
            ->where('resource', $finding->kind->value)
            ->where('subject_id', $finding->subject_id)
            ->delete();

        Audit::action('intelligence.leakage.undismissed')
            ->by($staff)
            ->on($finding)
            ->forOrganization($finding->organization_id)
            ->write();

        return back()->with('status', __('intelligence.reconciliation.undismissed'));
    }

    /**
     * What is open, by currency, with how many findings make it up.
     *
     * The count is the `hint` rather than decoration: a currency code above
     * an amount that already carries its own code says the same thing twice,
     * and “three findings” is the fact the cell was missing.
     *
     * @return list<array{currency: string, amount: string, minor: int, count: int}>
     */
    private function atStake(): array
    {
        $totals = new MoneyByCurrency;
        $counts = [];

        foreach (
            LeakageFinding::query()
                ->open()
                ->get(['currency_code', 'amount_minor']) as $finding
        ) {
            $code = strtoupper($finding->currency_code);

            $totals->add($code, $finding->amount_minor);
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        return array_map(
            static fn (array $row): array => [
                ...$row,
                'count' => $counts[$row['currency']] ?? 0,
            ],
            $totals->toArray(app()->getLocale()),
        );
    }

    /**
     * The currencies the strip lists, in the order it lists them.
     *
     * @return list<string>
     */
    private function currencyOrder(bool $showAll): array
    {
        $totals = new MoneyByCurrency;

        foreach (
            LeakageFinding::query()
                ->unless($showAll, static fn ($query) => $query->open())
                ->get(['currency_code', 'amount_minor']) as $finding
        ) {
            $totals->add($finding->currency_code, $finding->amount_minor);
        }

        return array_map(
            static fn (array $row): string => $row['currency'],
            $totals->toArray(),
        );
    }

    /**
     * @param  list<LeakageFinding>  $findings
     * @return list<array<string, mixed>>
     */
    private function rows(array $findings): array
    {
        $dismissals = $this->dismissalsFor($findings);

        return array_map(
            function (LeakageFinding $finding) use ($dismissals): array {
                $dismissal = $dismissals[$finding->kind->value.'|'.$finding->subject_id] ?? null;

                return [
                    'id' => $finding->id,
                    'subject' => $finding->subject_label,
                    // Two fields, always: the value for the tone and the
                    // word for the screen.
                    'kind' => $finding->kind->value,
                    'kindLabel' => (string) __($finding->kind->labelKey()),
                    'kindTone' => $finding->kind->tone(),
                    'customer' => $finding->customer_label,
                    // Worded in the reader's own locale: a yen has no decimals
                    // and a dinar has three.
                    'amount' => $finding->amount->format(app()->getLocale()),
                    'currency' => $finding->currency_code,
                    'detail' => $finding->detail ?? [],
                    'firstSeenAt' => $finding->first_seen_at->toIso8601String(),
                    'clearedAt' => $finding->cleared_at?->toIso8601String(),
                    'dismissal' => $dismissal === null ? null : [
                        'reason' => $dismissal->reason,
                        'until' => $dismissal->until?->toIso8601String(),
                    ],
                ];
            },
            $findings,
        );
    }

    /**
     * Every dismissal for the page, in one query.
     *
     * One lookup per row would be fifty queries on a full page — and a
     * `first()` in a loop is not a lazy load, so nothing would catch it.
     *
     * @param  list<LeakageFinding>  $findings
     * @return array<string, FindingDismissal>
     */
    private function dismissalsFor(array $findings): array
    {
        if ($findings === []) {
            return [];
        }

        $keyed = [];

        foreach (
            FindingDismissal::query()
                ->where('source', DetectLeakage::Resource)
                ->get() as $dismissal
        ) {
            $keyed[$dismissal->resource.'|'.($dismissal->subject_id ?? '')] = $dismissal;
        }

        return $keyed;
    }

    private function staff(CurrentActor $actor): StaffUser
    {
        $staff = $actor->model();

        if (! $staff instanceof StaffUser) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        return $staff;
    }

    private function refuseUnless(CurrentActor $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }
    }
}
