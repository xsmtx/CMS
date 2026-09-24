<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\SampleBatch;

/**
 * Something that knows numbers about resources this platform knows names for.
 *
 * The first capability contract, and the shape the other twenty-two follow.
 * Three things about it are the convention rather than this area's particulars.
 *
 * **It takes node keys, not models and not ids.** An adapter has never heard of
 * a ULID and must not be handed one; it knows hostnames and device
 * identifiers, which is what a node key is. This is also what keeps an adapter
 * out of the database — the rule ADR 0026 set for provisioning adapters, which
 * take a value object and return one.
 *
 * **It returns raw samples and lets core name them.** An adapter reports what the
 * source called a thing and in which unit; `MetricKind` and `MetricUnit` do the
 * translating. Twenty-three adapters each deciding what "cpu" means is
 * twenty-three screens that cannot be compared.
 *
 * **A partial answer is a normal answer.** `SampleBatch` carries the targets it
 * could not answer for, because nine of ten is not success and must not look
 * like it.
 */
interface MonitoringProvider extends InfrastructureAdapter
{
    /**
     * Read what this source currently knows about these targets.
     *
     * Core calls this in batches no larger than `limits()->batchSize` when the
     * adapter declares one, so an implementation may assume the list is a size
     * it said it could handle.
     *
     * @param  list<string>  $targets  Node keys.
     */
    public function collect(array $targets): SampleBatch;
}
