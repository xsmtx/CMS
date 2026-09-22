<?php

declare(strict_types=1);

namespace App\Support\Audit\Facades;

use App\Support\Audit\AuditEntry;
use App\Support\Audit\Contracts\AuditRecorder;
use App\Support\Audit\PendingAudit;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PendingAudit action(string $action)
 * @method static void record(AuditEntry $entry)
 *
 * @see AuditRecorder
 */
final class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditRecorder::class;
    }
}
