<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Reliability\OperationsCalendar;
use App\Http\Controllers\Controller;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\MaintenanceWindow;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * A month of what happened and what is coming (§16).
 *
 * The two questions an operations calendar is opened for are "was anything
 * scheduled when this broke" and "what is coming this week", which is why
 * incidents and maintenance windows sit on one page rather than two.
 *
 * It needs both permissions, not either: a page that showed half the month
 * depending on who was looking would be a page nobody could reason about —
 * and the two are held together by everybody who has either.
 */
final class OperationsCalendarController extends Controller
{
    public function __invoke(Request $request, CurrentActor $actor, OperationsCalendar $calendar): Response
    {
        if (! $actor->can('reliability.incidents.view') || ! $actor->can('reliability.maintenance.manage')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        // `YYYY-MM` in the address bar, so a month can be linked and sent to
        // somebody — which is most of why anybody opens this twice.
        $month = $this->month($request->string('month')->toString());

        return Inertia::render('Admin/Reliability/Calendar', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $this->worded($month, 'MMMM YYYY'),
            'previous' => $month->subMonth()->format('Y-m'),
            'next' => $month->addMonth()->format('Y-m'),
            'days' => array_map(
                fn (array $day): array => [
                    'date' => $day['date']->toDateString(),
                    'label' => $this->worded($day['date'], 'dddd D'),
                    'incidents' => array_map($this->incident(...), $day['incidents']),
                    'windows' => array_map($this->window(...), $day['windows']),
                ],
                $calendar->month($month),
            ),
        ]);
    }

    /**
     * The month asked for, or this one.
     *
     * A bad value is not an error: somebody edited the address bar, and the
     * useful answer is the month they are standing in rather than a 422 about
     * a date format.
     */
    private function month(string $raw): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}$/', $raw) !== 1) {
            return CarbonImmutable::now()->startOfMonth();
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01');
        } catch (Throwable) {
            $parsed = null;
        }

        return $parsed instanceof CarbonImmutable
            ? $parsed->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();
    }

    /**
     * A date worded in the locale the page is being read in.
     *
     * `app()->setLocale()` does not move Carbon's, so a Turkish calendar
     * would otherwise head its days in English — the same trap the status
     * page and a Turkish invoice mail both found.
     */
    private function worded(CarbonInterface $at, string $format): string
    {
        return $at->locale(app()->getLocale())->isoFormat($format);
    }

    /**
     * @return array<string, mixed>
     */
    private function incident(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'title' => $incident->title,
            'reference' => $incident->reference,
            'state' => $incident->state->value,
            'stateLabel' => (string) __($incident->state->labelKey()),
            'stateTone' => $incident->state->tone(),
            'startedAt' => $incident->started_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function window(MaintenanceWindow $window): array
    {
        return [
            'id' => $window->id,
            'title' => $window->title,
            'isPublic' => $window->is_public,
            'isCancelled' => $window->isCancelled(),
            'startsAt' => $window->starts_at->toIso8601String(),
            'endsAt' => $window->ends_at->toIso8601String(),
        ];
    }
}
