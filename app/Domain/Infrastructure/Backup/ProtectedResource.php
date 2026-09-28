<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Backup;

use Carbon\CarbonImmutable;

/**
 * One thing a backup source says it is protecting (§12).
 *
 * **`lastGoodAt` is the number that matters, not the last outcome.** A job
 * that failed last night is a warning; a job that has succeeded every night
 * for a month against a resource deleted three weeks ago is a lie, and a
 * green tick beside it is worse than a red cross. The coverage screen sorts
 * by the age of the last good copy and the alert rule compares against it.
 *
 * **Nullable means the source did not say, never "no".** A source that
 * reports no restore-point count has not reported zero restore points, and
 * storing a zero there would be this platform asserting that a customer has
 * nothing to restore from.
 *
 * `name` is what the source calls it, and it is the only thing core can match
 * a service on. It is kept verbatim: a normalised copy would disagree with
 * the vendor's own console, which is the screen somebody opens next.
 */
final readonly class ProtectedResource
{
    public function __construct(
        /** The source's own identifier for this resource, stable across runs. */
        public string $key,
        /** What the source calls it: a hostname, an account name, a VM name. */
        public string $name,
        public BackupOutcome $lastOutcome = BackupOutcome::Unknown,
        public ?CarbonImmutable $lastRunAt = null,
        /** When a backup of this last *succeeded*, which is not when it last ran. */
        public ?CarbonImmutable $lastGoodAt = null,
        public ?int $restorePoints = null,
        public ?int $sizeBytes = null,
        /** The repository or job this belongs to, where the source says. */
        public ?string $repository = null,
        /**
         * The source's own word for what kind of thing this is: `vm`,
         * `account`, `database`, `volume`. Free text on purpose — core has no
         * business telling Veeam what a protected object is, and an enum here
         * would reject a vendor noun nobody has thought of yet.
         */
        public ?string $resourceType = null,
    ) {}
}
