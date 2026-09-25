<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Infrastructure\AdapterRegistry;
use App\Application\Infrastructure\RegisteredAdapter;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Health\HealthState;
use App\Domain\Infrastructure\AdapterHealth;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Resources\Models\ResourceAdapter;
use App\Support\Logging\SecretRedactor;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Asks every adapter whether the thing on the other end is still there.
 *
 * Phase A left health as a button on purpose: "a sweep polling twenty devices
 * every five minutes before anybody had configured a timeout would be this
 * platform's first denial of service against its own operator". Phase B adds
 * the sweep, and what makes it safe is the pacing the adapters already
 * declare.
 *
 * **It asks a question about rows, never about the clock** (ADR 0031): which
 * adapters have not been checked recently enough. A scheduler that was down
 * for three days therefore checks everything once when it comes back, rather
 * than checking nothing because the window passed — and an adapter checked
 * two minutes ago by an operator pressing the button is skipped, because the
 * answer is already on the row.
 *
 * **The interval comes from the adapter, not from a constant here.** An
 * adapter that says it tolerates 60 requests a minute is asked at most every
 * minute; one that declares nothing gets the dull default. The number an
 * operator wants is "as often as the device tolerates", and the device's
 * author is the only one who knows it.
 *
 * **One adapter failing never stops the others**, and a thrown exception is
 * recorded as a failing health state rather than escaping — an adapter author
 * should not have to write a try/catch to report that a box is down. The
 * message is redacted before it is stored, because a provider exception is
 * the likeliest place a credential reaches a database column, and this column
 * is rendered on a screen.
 */
final readonly class CheckAdapterHealth implements AutomationRun
{
    /**
     * How long an answer stays good enough when the adapter says nothing.
     *
     * Five minutes rather than one: health is not telemetry, and a device
     * that went down forty seconds ago is found by the thing that was talking
     * to it rather than by this sweep.
     */
    private const int DEFAULT_INTERVAL_SECONDS = 300;

    public function __construct(
        private OrganizationContext $organizations,
        private AdapterRegistry $registry,
        private SecretRedactor $redactor,
    ) {}

    public function handle(): RunSummary
    {
        return $this->organizations->withoutBoundary(function (): RunSummary {
            $summary = new RunSummary;

            foreach ($this->providerOrganizationIds() as $organizationId) {
                foreach ($this->registry->all($organizationId) as $registered) {
                    $summary = $this->check($summary, $registered);
                }
            }

            return $summary;
        });
    }

    private function check(RunSummary $summary, RegisteredAdapter $registered): RunSummary
    {
        $row = $registered->row;

        $summary = $summary->examining();

        if (! $row->enabled) {
            // An adapter an operator switched off is examined and skipped, so
            // "why is this one's health from yesterday" has an answer on the
            // run record rather than being a silence.
            return $summary->skipping();
        }

        if (! $this->isDue($registered)) {
            return $summary->skipping();
        }

        try {
            $health = $registered->adapter()->health();
        } catch (Throwable $exception) {
            $health = AdapterHealth::failing($this->redactor->redactString($exception->getMessage()));
        }

        $before = $row->health;

        $row->forceFill([
            'health' => $health->state->value,
            // Never a translated sentence into a column: an adapter's own
            // message is English or the vendor's, and the alternative here
            // would be a message stored in the language of whichever
            // scheduler run wrote it.
            'health_message' => $health->message === null
                ? null
                : mb_substr($this->redactor->redactString($health->message), 0, 191),
            'remote_version' => $health->remoteVersion ?? $row->remote_version,
            'supported' => $health->supported,
            'health_checked_at' => $health->checkedAt ?? CarbonImmutable::now(),
        ])->save();

        /*
         * A check that found the same state is a run item too, but a
         * "checked" one rather than a "changed" one: a sweep that reported
         * twenty changes every five minutes would make the one adapter that
         * actually changed impossible to see.
         */
        return $before === $health->state->value
            ? $summary->skipping()
            : $summary->changing(new RunItem(
                ItemOutcome::Changed,
                ResourceAdapter::class,
                $row->id,
                $registered->descriptor->name,
                $this->transition($before, $health->state),
            ));
    }

    /**
     * Whether enough time has passed for this adapter's own declared pace.
     */
    private function isDue(RegisteredAdapter $registered): bool
    {
        $checkedAt = $registered->row->health_checked_at;

        if ($checkedAt === null) {
            return true;
        }

        $perMinute = $registered->descriptor->limits->perMinute;

        $interval = $perMinute > 0
            ? max(60, (int) ceil(60 / $perMinute))
            : self::DEFAULT_INTERVAL_SECONDS;

        return $checkedAt->addSeconds($interval)->isPast();
    }

    private function transition(string $before, HealthState $after): string
    {
        return $before.' → '.$after->value;
    }

    /**
     * @return list<string>
     */
    private function providerOrganizationIds(): array
    {
        /** @var list<string> $ids */
        $ids = Organization::query()
            ->withoutGlobalScope('organization')
            ->whereIn('type', [OrganizationType::Provider->value, OrganizationType::Reseller->value])
            ->pluck('id')
            ->all();

        return $ids;
    }
}
