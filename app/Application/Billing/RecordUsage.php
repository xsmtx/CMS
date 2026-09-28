<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Domain\Billing\UsageReading;
use App\Infrastructure\Billing\Models\UsageMeterRecord;
use App\Infrastructure\Billing\Models\UsageSnapshot;
use App\Support\Organizations\OrganizationSubtree;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Writes down what a metering source measured (§25).
 *
 * **Append-only, and idempotent on the period.** Running the sweep twice for
 * one month writes one snapshot — the rule every automation task is held to
 * (ADR 0031), enforced by the unique key rather than by a check somebody
 * could forget. The second run is a skip, not an update: a snapshot is what
 * somebody was charged for, and the moment it has been quoted by an invoice
 * line it must never change.
 *
 * **A reading for a meter nobody configured is dropped, and counted.** The
 * seller states which services are metered, in what unit and at what rate; a
 * source that reports about something else is reporting about a service this
 * platform does not sell that way, and inventing a meter for it would be
 * inventing a price.
 *
 * **A reading in the wrong unit is refused, not converted.** The meter
 * declares the billing unit and the source answers in it. That is the rule
 * `RecordSamples` already states about a unit from the wrong dimension,
 * applied where the consequence is money — and converting would put a
 * rounding error nobody can find between the invoice and the screen.
 */
final readonly class RecordUsage
{
    public function __construct(private OrganizationSubtree $subtree) {}

    /**
     * @param  list<UsageReading>  $readings
     * @return array{recorded: int, skipped: int, unmetered: int, refused: int}
     */
    public function handle(string $organizationId, string $source, array $readings, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        $meters = $this->metersFor($organizationId, $source);

        $recorded = 0;
        $skipped = 0;
        $unmetered = 0;
        $refused = 0;

        foreach ($readings as $reading) {
            $meter = $meters[$reading->serviceKey.'|'.$reading->meterKey] ?? null;

            if (! $meter instanceof UsageMeterRecord) {
                $unmetered++;

                continue;
            }

            if ($meter->unit !== $reading->unit) {
                $refused++;

                continue;
            }

            $this->record($meter, $reading, $source, $at) ? $recorded++ : $skipped++;
        }

        return [
            'recorded' => $recorded,
            'skipped' => $skipped,
            'unmetered' => $unmetered,
            'refused' => $refused,
        ];
    }

    /**
     * True when this was the first time that period was reported.
     */
    private function record(
        UsageMeterRecord $meter,
        UsageReading $reading,
        string $source,
        CarbonImmutable $at,
    ): bool {
        try {
            UsageSnapshot::query()->create([
                'organization_id' => $meter->organization_id,
                'usage_meter_id' => $meter->id,
                'service_id' => $meter->service_id,
                'period_start' => $reading->periodStart,
                'period_end' => $reading->periodEnd,
                'quantity' => $reading->quantity,
                // Copied, not read through the meter: the meter's unit may
                // change and this is what somebody was charged for.
                'unit' => $reading->unit,
                'source' => $source,
                'recorded_at' => $at,
            ]);
        } catch (QueryException) {
            // Already reported. Tested at the guard rather than by racing
            // threads: a flaky test is worse than none, because it gets
            // retried until it passes.
            return false;
        }

        return true;
    }

    /**
     * @return array<string, UsageMeterRecord>
     */
    private function metersFor(string $organizationId, string $source): array
    {
        $meters = [];

        foreach (
            UsageMeterRecord::query()
                ->withoutGlobalScope('organization')
                // A meter belongs to the customer's organization and the
                // sweep runs as the seller, so the narrowing a boundary
                // would have done is written out.
                ->whereIn('organization_id', $this->subtree->ids($organizationId))
                ->where('source', $source)
                ->cursor() as $meter
        ) {
            $meters[$meter->service_key.'|'.$meter->meter_key] = $meter;
        }

        return $meters;
    }
}
