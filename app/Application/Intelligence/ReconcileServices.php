<?php

declare(strict_types=1);

namespace App\Application\Intelligence;

use App\Application\Provisioning\RunServiceOperation;
use App\Domain\Intelligence\Difference;
use App\Domain\Intelligence\ReconciliationClass;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Provisioning\Models\Service;

/**
 * What this platform believes about a service, against what its provider
 * reports (§22).
 *
 * **It needed no new contract.** `ProvisioningModule::sync()` and
 * `SyncResult` have existed since Phase 6 and nothing has ever called them on
 * a schedule — `SyncResult`'s own docblock says "a sync reports; it does not
 * decide", which is precisely the shape a reconciliation engine wants. The
 * phase that would have added a `ReconciliationProvider` would have added a
 * second way to ask the same question.
 *
 * **Only the status is compared, and that is the whole of the first cut.**
 * §22's other example — expected 4 GB, provider has 8 GB — needs a *spec*
 * from the provider, and `SyncResult::$usage` carries counters: how much disk
 * is used, not how much was sold. Comparing a counter against a plan would
 * report every customer who is not full as drift.
 *
 * The four conclusions, and the reasoning behind each:
 *
 * - **Unreachable is `unknown`, never `drift`.** A provider that did not
 *   answer has told us nothing about the account. Folding the two together
 *   would put a panel's outage in front of an operator as four hundred
 *   customers whose accounts had apparently changed — and the one real
 *   finding would be in there somewhere.
 * - **An adapter that cannot say is also `unknown`.** `remoteStatus` is
 *   nullable on purpose: `ManualModule` is reachable and has no opinion, and
 *   reading its silence as agreement would mark every manually-provisioned
 *   service healthy for ever.
 * - **Terminated here and alive there is an `orphan`.** It is costing
 *   somebody money and it is nobody's account. §22's own second example.
 * - **Alive here and gone there is `missing`**, which is the one that
 *   matters most: the customer is paying for something that is not there.
 */
final readonly class ReconcileServices
{
    public const string Resource = 'service';

    public function __construct(private RunServiceOperation $operations) {}

    /**
     * @return list<Difference>
     */
    public function handle(string $organizationId): array
    {
        $differences = [];

        foreach ($this->services($organizationId) as $service) {
            $differences[] = $this->compare($service);
        }

        return $differences;
    }

    public function compare(Service $service): Difference
    {
        $sync = $this->operations->observe($service);
        $label = $this->label($service);
        /*
         * The raw value, never a translated sentence. A word stored in the
         * language of whichever scheduler run wrote it is a word the next
         * operator cannot read — the rule `health_message` has lived under
         * since the graph was built. The screen words it at render.
         *
         * The exception is a provider's *own* message, which is theirs and
         * is stored verbatim: it is evidence rather than vocabulary.
         */
        $expected = $service->status->value;

        if (! $sync->reachable) {
            return $this->difference(
                $service,
                ReconciliationClass::Unknown,
                $label,
                $expected,
                // The provider's own sentence, not ours: "nobody could ask"
                // is useless without saying why.
                $sync->message,
            );
        }

        $remote = $sync->remoteStatus;

        if (! $remote instanceof ServiceStatus) {
            return $this->difference(
                $service,
                ReconciliationClass::Unknown,
                $label,
                $expected,
                null,
            );
        }

        $here = $service->status;
        $found = $remote->value;

        if ($here === $remote) {
            return $this->difference($service, ReconciliationClass::Healthy, $label, $expected, $found);
        }

        // Terminated here and still there: it is costing somebody money and
        // it is nobody's account.
        if ($here->isTerminal() && $remote->existsRemotely()) {
            return $this->difference($service, ReconciliationClass::Orphan, $label, $expected, $found);
        }

        // Alive here and gone there: the customer is paying for something
        // that is not there.
        if ($here->existsRemotely() && ! $remote->existsRemotely()) {
            return $this->difference($service, ReconciliationClass::Missing, $label, $expected, $found);
        }

        return $this->difference($service, ReconciliationClass::Drift, $label, $expected, $found);
    }

    private function difference(
        Service $service,
        ReconciliationClass $class,
        string $label,
        string $expected,
        ?string $found,
    ): Difference {
        return new Difference(
            class: $class,
            resource: self::Resource,
            label: $label,
            subjectId: $service->id,
            subjectType: $service::class,
            remoteKey: $service->external_id,
            // The status is the only field compared, and naming it keeps the
            // key stable for the day a second one is.
            field: 'status',
            expected: $expected,
            found: $found,
            detail: array_filter([
                'module' => $service->module,
                'server' => $service->server?->name,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * Every service the provider should have an account for.
     *
     * `Terminated` is included, which is the half that finds orphans: a
     * service this platform has finished with is exactly the one nobody
     * looks at again. `Pending`, `Provisioning` and `Failed` are not — an
     * account that was never created is not a difference, it is a job.
     *
     * @return iterable<Service>
     */
    private function services(string $organizationId): iterable
    {
        return Service::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->whereNotNull('module')
            ->whereNotNull('external_id')
            ->whereIn('status', [
                ServiceStatus::Active->value,
                ServiceStatus::Suspended->value,
                ServiceStatus::GracePeriod->value,
                ServiceStatus::CancelPending->value,
                ServiceStatus::Terminated->value,
            ])
            ->with('server')
            ->cursor();
    }

    private function label(Service $service): string
    {
        $name = $service->domain ?? $service->name;

        return $service->server === null ? $name : $name.' — '.$service->server->name;
    }
}
