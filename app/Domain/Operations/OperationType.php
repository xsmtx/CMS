<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * The kinds of work worth watching.
 *
 * The handoff's list: provisioning, termination, domain registration,
 * transfer and renewal, imports and bulk operations. A closed enum rather
 * than a free string, because the filter on the operations screen is a list
 * of these and a typo in a job would create a category nobody can find.
 */
enum OperationType: string
{
    case ServiceProvision = 'service.provision';
    case ServiceSuspend = 'service.suspend';
    case ServiceUnsuspend = 'service.unsuspend';
    case ServiceTerminate = 'service.terminate';
    case ServiceChangePackage = 'service.change_package';
    case ServiceSync = 'service.sync';

    case DomainRegister = 'domain.register';
    case DomainTransfer = 'domain.transfer';
    case DomainRenew = 'domain.renew';
    case DomainSync = 'domain.sync';

    /**
     * Pushing a configuration to a network device.
     *
     * Watched like the rest and for the sharpest version of ADR 0032's
     * reason: an apply that never reached a worker is a firewall change an
     * operator believes went out. The operation row opens before the job is
     * handed to the queue, so the wait is visible from the first second.
     */
    case NetworkChangeApply = 'network_change.apply';

    public function labelKey(): string
    {
        return 'operations.types.'.str_replace('.', '_', $this->value);
    }
}
