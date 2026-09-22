<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * The things that can be asked of a provisioning module.
 *
 * Named here rather than as strings at call sites, because every one of
 * them is recorded in the event log and an operator reading that log a year
 * later should not be decoding typos.
 */
enum ServiceOperation: string
{
    case Create = 'create';
    case Suspend = 'suspend';
    case Unsuspend = 'unsuspend';
    case Terminate = 'terminate';
    case ChangePackage = 'change_package';
    case Sync = 'sync';
    case TestConnection = 'test_connection';

    public function labelKey(): string
    {
        return 'provisioning.operations.'.$this->value;
    }

    /**
     * Whether this operation destroys something a customer is using.
     */
    public function isDestructive(): bool
    {
        return $this === self::Terminate;
    }
}
