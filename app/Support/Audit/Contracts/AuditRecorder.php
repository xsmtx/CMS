<?php

declare(strict_types=1);

namespace App\Support\Audit\Contracts;

use App\Support\Audit\AuditEntry;
use App\Support\Audit\PendingAudit;

/**
 * The platform-owned audit API.
 *
 * Modules depend on this contract, never on the Eloquent model behind it, so
 * that an installation can ship audit records to an external system without
 * touching feature code.
 */
interface AuditRecorder
{
    /**
     * Begin recording an action, e.g. `service.suspended`.
     */
    public function action(string $action): PendingAudit;

    public function record(AuditEntry $entry): void;
}
