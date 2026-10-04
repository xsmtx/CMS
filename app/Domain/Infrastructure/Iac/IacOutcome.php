<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Iac;

/**
 * What the tool said when it ran (§25).
 *
 * **`succeeded` is the tool's own verdict and core does not trust it alone.**
 * `ApplyNetworkChange` re-plans afterwards and treats an empty plan as the
 * proof, for the same reason it re-reads a device's configuration: a run that
 * reported success and left half the estate unchanged is exactly the failure
 * worth catching, and no adapter can report it about itself.
 *
 * `output` is the transcript, in the tool's words, kept so an operator reading
 * the record afterwards sees what the runner saw. It is written to a column
 * and never translated — it is evidence, not vocabulary.
 */
final readonly class IacOutcome
{
    public function __construct(
        public bool $succeeded,
        public string $output = '',
        public ?int $stateSerial = null,
    ) {}
}
