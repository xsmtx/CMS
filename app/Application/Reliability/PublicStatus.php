<?php

declare(strict_types=1);

namespace App\Application\Reliability;

use App\Domain\Reliability\IncidentState;
use App\Domain\Reliability\PublicStatusLevel;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\IncidentUpdate;
use App\Infrastructure\Reliability\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;

/**
 * What the public may read about how things are going (§15).
 *
 * **This class presents as well as reads, which is not the usual division.**
 * Everywhere else in this product the query fetches and a presenter drops
 * what the reader should not see; here the two are one class on purpose,
 * because the reader is *everybody* and a field that escapes is a field that
 * escaped to the internet. There is exactly one place that decides what a
 * status page contains, and it returns arrays rather than models so that no
 * template can reach through to a relation nobody meant to publish.
 *
 * What is published is a deliberate subset and it is short: a title, a state,
 * when it started and ended, and the updates somebody marked public. Not the
 * impact — "47 customers, 12,400 EUR a month" tells the internet the size of
 * the business and which outage was the expensive one. Not the alerts, which
 * name hostnames. Not who wrote the update, because a customer does not need
 * an operator's name and an operator did not agree to be named.
 *
 * The boundary is the installation's (`ResolveStorefrontOrganization`), so a
 * reseller's status page is the reseller's incidents — the same rule that
 * makes the shop the seller's rather than the visitor's.
 */
final readonly class PublicStatus
{
    /** How far back the history goes. Ninety days is what status pages show. */
    public const int HistoryDays = 90;

    public function report(int $days = self::HistoryDays): StatusReport
    {
        $since = CarbonImmutable::now()->subDays(max(1, $days))->startOfDay();

        /** @var list<Incident> $incidents */
        $incidents = Incident::query()
            ->where('is_public', true)
            ->where(static fn ($query) => $query
                ->where('state', '!=', IncidentState::Resolved->value)
                ->orWhere('started_at', '>=', $since))
            // Only the updates somebody published. Filtering in the template
            // would mean the private ones were loaded and one `@foreach`
            // away from the page.
            ->with(['updates' => static fn ($query) => $query->where('is_public', true)])
            ->latest('started_at')
            ->limit(100)
            ->get()
            ->all();

        $open = [];
        $history = [];
        $severities = [];

        foreach ($incidents as $incident) {
            if ($incident->state->isOpen()) {
                $open[] = $this->present($incident);
                $severities[] = $incident->severity;

                continue;
            }

            $history[] = $this->present($incident);
        }

        return new StatusReport(
            level: PublicStatusLevel::fromOpen($severities),
            open: $open,
            history: $history,
            since: $since,
            maintenance: $this->maintenance(),
        );
    }

    /**
     * Whether this installation publishes one at all.
     *
     * An operator who has never published an incident still has a true page
     * to point at, so the default is on; an operator who runs their status
     * page somewhere else turns it off and both the route and the header link
     * go with it — the rule self-registration already follows, because a link
     * to a page that answers 404 is worse than no link.
     */
    public function isPublished(): bool
    {
        return (bool) config('platform.reliability.status_page', true);
    }

    /**
     * Planned work somebody chose to announce (§16).
     *
     * Running and upcoming only, never the history: a customer wants to know
     * what is happening and what is coming, and a list of every window this
     * year is an operator's record rather than an announcement. A cancelled
     * one disappears from here the moment it is cancelled, which is the whole
     * point of being able to call one off.
     *
     * The banner is **not** moved by a window. "All systems operational"
     * during planned work is the truth as far as a customer standing outside
     * is concerned, and a status page that went amber every Sunday night at
     * two would be one nobody reads on a Monday.
     *
     * @return list<array{title: string, body: string|null, startsAt: CarbonImmutable, endsAt: CarbonImmutable, isRunning: bool}>
     */
    private function maintenance(): array
    {
        $now = CarbonImmutable::now();

        $windows = MaintenanceWindow::query()
            ->where('is_public', true)
            ->whereNull('cancelled_at')
            ->where('ends_at', '>', $now)
            ->oldest('starts_at')
            ->limit(20)
            ->get();

        return array_values($windows
            ->map(static fn (MaintenanceWindow $window): array => [
                'title' => $window->title,
                'body' => $window->body,
                'startsAt' => $window->starts_at,
                'endsAt' => $window->ends_at,
                'isRunning' => $window->isActive($now),
            ])
            ->all());
    }

    /**
     * One incident, as the internet may read it.
     *
     * @return array{
     *     reference: string,
     *     title: string,
     *     state: IncidentState,
     *     startedAt: CarbonImmutable,
     *     resolvedAt: CarbonImmutable|null,
     *     updates: list<array{state: IncidentState, body: string, writtenAt: CarbonImmutable}>,
     * }
     */
    private function present(Incident $incident): array
    {
        return [
            'reference' => $incident->reference,
            'title' => $incident->title,
            'state' => $incident->state,
            // Handed over as dates rather than strings, and worded in the
            // template in the locale the page is being rendered in — the rule
            // `RenderTemplate` learned when a Turkish invoice mail printed
            // `2026-10-11` among its Turkish sentences.
            'startedAt' => $incident->started_at,
            'resolvedAt' => $incident->resolved_at,
            'updates' => array_values($incident->updates
                ->map(static fn (IncidentUpdate $update): array => [
                    'state' => $update->state,
                    'body' => $update->body,
                    'writtenAt' => $update->created_at ?? CarbonImmutable::now(),
                ])
                ->all()),
        ];
    }
}
