<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Resellers\ResellerPerformance;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * What the provider sold through its resellers.
 *
 * Gated on `resellers.administer`, the same gate the rest of the programme
 * uses: this is a screen about other organizations, and a reseller reading a
 * row about the reseller beside them would be the one disclosure the whole
 * boundary exists to prevent.
 *
 * **The other half of "reseller reports" is the dashboard.** A reseller
 * signing into `/admin` already sees their own customers, their own recurring
 * revenue and their own overdue invoices, because every query in the panel is
 * narrowed by the boundary. A second, reseller-flavoured copy of that would
 * be a second thing to keep in step.
 *
 * The period defaults to the last twelve months, which is the window a
 * question like "is this reseller growing" is actually asked over.
 */
final class ResellerReportController extends Controller
{
    public function __invoke(Request $request, ResellerPerformance $performance): Response
    {
        $this->authorize('resellers.administer');

        $to = $this->date($request->string('to')->toString()) ?? CarbonImmutable::now();
        $from = $this->date($request->string('from')->toString()) ?? $to->subMonths(12);

        // Swapped rather than refused: somebody who typed the dates the other
        // way round meant the period between them.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return Inertia::render('Admin/Resellers/Report', [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'rows' => $performance->handle($from->startOfDay(), $to->endOfDay()),
        ]);
    }

    /**
     * A date somebody typed, or null.
     *
     * Parsed rather than validated, because a malformed date in a query
     * string is not worth a 422 on a report — the default window is a better
     * answer than an error page.
     */
    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
