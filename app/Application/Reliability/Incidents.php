<?php

declare(strict_types=1);

namespace App\Application\Reliability;

use App\Application\Infrastructure\ImpactSummary;
use App\Application\Shared\AllocateNumber;
use App\Domain\Reliability\AlertSeverity;
use App\Domain\Reliability\Exceptions\IncidentRefused;
use App\Domain\Reliability\IncidentState;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\Incident;
use App\Infrastructure\Reliability\Models\IncidentImpact;
use App\Infrastructure\Reliability\Models\IncidentUpdate;
use App\Infrastructure\Resources\Models\ResourceNode;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Opening, saying something, and ending it (§15).
 *
 * One class for the incident's whole life, because the three are the same
 * decision seen at three moments and splitting them would put the state
 * machine in three places. `EvaluateAlertRule` is separate for the opposite
 * reason: an alert is a machine observation and this is a human record, and
 * nothing here is called from a sweep.
 *
 * **An incident always has at least one update.** Opening writes the first
 * one, so the timeline starts with why it was opened rather than with a gap
 * an operator has to explain afterwards. A postmortem is written from the
 * timeline, and a timeline that begins in the middle is a postmortem with a
 * hole in it.
 *
 * **The state and the update are one act.** Moving to `identified` without
 * saying what was identified is the move that makes a status page useless —
 * so `note()` takes both and there is no way to change the state alone.
 *
 * **Resolving freezes the impact.** `ImpactSummary` reads the graph and the
 * graph moves; an impact recomputed in March is not the impact anybody acted
 * on in January (ADR 0023's reasoning, applied to a figure somebody will
 * quote in a credit conversation). It is computed from the resources the
 * incident's own alerts are about, which is the only honest link between a
 * machine observation and a customer.
 */
final readonly class Incidents
{
    public function __construct(
        private AllocateNumber $numbers,
        private ImpactSummary $impact,
    ) {}

    public function open(
        string $organizationId,
        string $title,
        string $body,
        AlertSeverity $severity,
        ?StaffUser $actor = null,
        ?CarbonImmutable $startedAt = null,
        bool $public = false,
    ): Incident {
        return DB::transaction(function () use (
            $organizationId, $title, $body, $severity, $actor, $startedAt, $public
        ): Incident {
            $now = CarbonImmutable::now();

            $incident = Incident::query()->create([
                'organization_id' => $organizationId,
                // The seller's, like every other document number (ADR 0025):
                // `AllocateNumber` resolves the nearest non-customer ancestor
                // itself, so no call site passes it.
                'reference' => $this->numbers->handle($organizationId, 'incident', 'INC-'),
                'title' => $title,
                'state' => IncidentState::Investigating,
                'severity' => $severity,
                'opened_by' => $actor?->id,
                // When the customer's world broke, which is usually earlier
                // than anybody noticed — and what an SLA is measured from.
                'started_at' => $startedAt ?? $now,
                'detected_at' => $now,
                'is_public' => $public,
            ]);

            $this->write($incident, $body, IncidentState::Investigating, $actor, $public);

            Audit::action('reliability.incident.opened')
                ->by($actor)
                ->on($incident)
                ->forOrganization($organizationId)
                ->because($title)
                ->withMetadata(['severity' => $severity->value, 'public' => $public])
                ->write();

            return $incident;
        });
    }

    /**
     * Say something, and move the state if it has moved.
     *
     * One act rather than two: moving to `identified` without saying what was
     * identified is the move that makes a status page useless.
     */
    public function note(
        Incident $incident,
        string $body,
        IncidentState $state,
        ?StaffUser $actor = null,
        bool $public = false,
    ): IncidentUpdate {
        if ($incident->state === IncidentState::Resolved) {
            throw IncidentRefused::alreadyResolved($incident->reference);
        }

        if ($state === IncidentState::Resolved) {
            // Resolving has a figure to freeze and a timestamp to write.
            // Letting it happen through the ordinary update path would be a
            // second way to end an incident, and the second one would be the
            // one that forgot.
            throw IncidentRefused::resolveSeparately();
        }

        return DB::transaction(function () use ($incident, $body, $state, $actor, $public): IncidentUpdate {
            $update = $this->write($incident, $body, $state, $actor, $public);

            $incident->state = $state;
            $incident->save();

            Audit::action('reliability.incident.updated')
                ->by($actor)
                ->on($incident)
                ->forOrganization($incident->organization_id)
                ->because($body)
                ->withMetadata(['state' => $state->value, 'public' => $public])
                ->write();

            return $update;
        });
    }

    /**
     * End it, and freeze what it was worth.
     */
    public function resolve(
        Incident $incident,
        string $body,
        ?StaffUser $actor = null,
        bool $public = false,
    ): Incident {
        if ($incident->state === IncidentState::Resolved) {
            throw IncidentRefused::alreadyResolved($incident->reference);
        }

        return DB::transaction(function () use ($incident, $body, $actor, $public): Incident {
            $this->write($incident, $body, IncidentState::Resolved, $actor, $public);

            $incident->state = IncidentState::Resolved;
            $incident->resolved_at = CarbonImmutable::now();
            $incident->save();

            $this->freezeImpact($incident);

            Audit::action('reliability.incident.resolved')
                ->by($actor)
                ->on($incident)
                ->forOrganization($incident->organization_id)
                ->because($body)
                ->write();

            return $incident;
        });
    }

    /**
     * Write or rewrite the postmortem (§15).
     *
     * **Only once it is resolved**, because a postmortem written during an
     * outage is a guess, and the timeline is what it is written from.
     *
     * **It is the one thing here that may be edited**, and deliberately so:
     * the timeline is append-only because what was believed at half past two
     * is evidence, and a postmortem is the opposite — a conclusion somebody
     * revises when the third person reads it and remembers something. The
     * audit row carries the change; `postmortem_at` says when it last did.
     *
     * Not published with the incident. A postmortem names what broke and
     * often who was on, and a seller who wants to publish one writes the
     * customer-facing version as a final public update.
     */
    public function recordPostmortem(
        Incident $incident,
        ?string $body,
        ?StaffUser $actor = null,
    ): Incident {
        if ($incident->state !== IncidentState::Resolved) {
            throw IncidentRefused::notResolvedYet($incident->reference);
        }

        $body = $body === null || trim($body) === '' ? null : trim($body);
        $existed = $incident->postmortem !== null;

        $incident->postmortem = $body;
        // Null when it is cleared: a date saying a postmortem was written,
        // beside no postmortem, is a record that contradicts itself.
        $incident->postmortem_at = $body === null ? null : CarbonImmutable::now();
        $incident->save();

        Audit::action($body === null
            ? 'reliability.incident.postmortem_cleared'
            : ($existed ? 'reliability.incident.postmortem_revised' : 'reliability.incident.postmortem_written'))
            ->by($actor)
            ->on($incident)
            ->forOrganization($incident->organization_id)
            ->write();

        return $incident;
    }

    /**
     * Attach an alert, or detach it.
     *
     * The direction is deliberate: an alert points at an incident and never
     * the other way round. An incident outlives the rule that raised its
     * alerts — somebody deletes the rule afterwards, which is the ordinary
     * thing to do once it has fired badly — and a reference in the other
     * direction would take the incident's evidence with it.
     */
    public function attach(Incident $incident, Alert $alert, ?StaffUser $actor = null): void
    {
        if ($alert->organization_id !== $incident->organization_id) {
            throw IncidentRefused::differentOrganization();
        }

        $this->refuseIfSettled($incident);

        $alert->incident_id = $incident->id;
        $alert->save();

        Audit::action('reliability.incident.alert_attached')
            ->by($actor)
            ->on($incident)
            ->forOrganization($incident->organization_id)
            ->withMetadata(['alert' => $alert->subject_label])
            ->write();
    }

    public function detach(Alert $alert, ?StaffUser $actor = null): void
    {
        $incident = $alert->incident_id === null
            ? null
            : Incident::query()->whereKey($alert->incident_id)->first();

        if ($incident instanceof Incident) {
            $this->refuseIfSettled($incident);
        }

        $alert->incident_id = null;
        $alert->save();

        Audit::action('reliability.incident.alert_detached')
            ->by($actor)
            ->on($alert)
            ->forOrganization($alert->organization_id)
            ->write();
    }

    /**
     * The evidence stops moving when the figure computed from it does.
     */
    private function refuseIfSettled(Incident $incident): void
    {
        if ($incident->state === IncidentState::Resolved) {
            throw IncidentRefused::evidenceIsSettled($incident->reference);
        }
    }

    /**
     * What was underneath, at the moment it ended.
     *
     * The resources come from the incident's own alerts, which is the only
     * honest link between a machine observation and a customer: the alert
     * knows a node key, the graph knows what is under that node, and
     * `services.recurring_minor` knows what those are worth. An incident with
     * no alerts freezes zeroes rather than nothing, because "nobody attached
     * anything" and "it affected nobody" are different and only the first is
     * true here.
     */
    private function freezeImpact(Incident $incident): void
    {
        $keys = $incident->alerts()->pluck('subject_key')->unique()->all();

        $services = 0;
        $customers = 0;
        $recurring = [];

        if ($keys !== []) {
            $nodes = ResourceNode::query()
                ->whereIn('node_key', $keys)
                ->whereNull('retired_at')
                ->get();

            $seenServices = [];
            $seenCustomers = [];

            foreach ($nodes as $node) {
                $figures = $this->impact->for($node);

                $services += $figures->services;
                $seenCustomers[$figures->customers] = true;

                foreach ($figures->recurring->toArray() as $row) {
                    $recurring[$row['currency']] = ($recurring[$row['currency']] ?? 0) + $row['minor'];
                }
            }

            unset($seenServices);

            // The customer count is the largest single answer rather than a
            // sum: two nodes under one customer would otherwise count them
            // twice, and this platform would tell an operator an outage hit
            // more customers than it has.
            $customers = $seenCustomers === [] ? 0 : max(array_keys($seenCustomers));
        }

        IncidentImpact::query()->updateOrCreate(
            ['incident_id' => $incident->id],
            [
                'organization_id' => $incident->organization_id,
                'services' => $services,
                'customers' => $customers,
                'recurring' => array_values(array_map(
                    static fn (int $minor, string $currency): array => [
                        'currency' => $currency,
                        'minor' => $minor,
                    ],
                    $recurring,
                    array_keys($recurring),
                )),
                'frozen_at' => CarbonImmutable::now(),
            ],
        );
    }

    private function write(
        Incident $incident,
        string $body,
        IncidentState $state,
        ?StaffUser $actor,
        bool $public,
    ): IncidentUpdate {
        return IncidentUpdate::query()->create([
            'organization_id' => $incident->organization_id,
            'incident_id' => $incident->id,
            'written_by' => $actor?->id,
            'state' => $state,
            'body' => $body,
            // An incident that is not public cannot have a public update:
            // publishing a sentence about something nobody has been told
            // exists would be the worst possible order to say things in.
            'is_public' => $public && $incident->is_public,
        ]);
    }
}
