<?php

declare(strict_types=1);

namespace App\Application\Reliability;

use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;

/**
 * A month of what happened and what is coming.
 *
 * Two sources, deliberately: incidents (what went wrong) and maintenance
 * windows (what somebody planned). The plan lists contracts as a third and
 * they do not exist yet — so this reads the two that do rather than waiting
 * for a phase that is eight letters away, and a contract joins it by adding
 * one more query.
 *
 * **A day is a row in a month, not a cell in a grid.** An operator asking
 * "what is happening this week" reads a list; a grid is what a calendar looks
 * like and a list is what it is for. It also survives a month where nothing
 * happened, which is most of them — an empty grid of thirty boxes says less
 * than one sentence does.
 *
 * **An entry spans days.** A window from Friday night to Saturday morning
 * belongs to both, because somebody looking at Saturday needs to know the
 * work was still running at two — and a calendar that filed it under Friday
 * alone is a calendar that hides exactly the entry somebody is looking for.
 */
final readonly class OperationsCalendar
{
    /**
     * @return list<array{
     *     date: CarbonImmutable,
     *     incidents: list<Incident>,
     *     windows: list<MaintenanceWindow>,
     * }>
     */
    public function month(CarbonImmutable $anyDayInIt): array
    {
        $from = $anyDayInIt->startOfMonth();
        $to = $anyDayInIt->endOfMonth();

        // Anything that overlaps the month, not only what began in it: a
        // window that started on the 31st of last month and ran past midnight
        // is this month's problem too.
        $incidents = Incident::query()
            ->where('started_at', '<=', $to)
            ->where(static fn ($query) => $query
                ->whereNull('resolved_at')
                ->orWhere('resolved_at', '>=', $from))
            ->oldest('started_at')
            ->get();

        $windows = MaintenanceWindow::query()
            ->where('starts_at', '<=', $to)
            ->where('ends_at', '>=', $from)
            ->oldest('starts_at')
            ->get();

        $days = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $dayEnd = $day->endOfDay();

            $onThisDay = [
                'date' => $day,
                'incidents' => array_values($incidents
                    ->filter(static fn (Incident $incident): bool => $incident->started_at->lessThanOrEqualTo($dayEnd)
                        && ($incident->resolved_at === null || $incident->resolved_at->greaterThanOrEqualTo($day)))
                    ->all()),
                'windows' => array_values($windows
                    ->filter(static fn (MaintenanceWindow $window): bool => $window->starts_at->lessThanOrEqualTo($dayEnd)
                        && $window->ends_at->greaterThanOrEqualTo($day))
                    ->all()),
            ];

            // Only days with something on them. A month is thirty rows of
            // nothing otherwise, and the two that matter are lost in it.
            if ($onThisDay['incidents'] !== [] || $onThisDay['windows'] !== []) {
                $days[] = $onThisDay;
            }
        }

        return $days;
    }
}
