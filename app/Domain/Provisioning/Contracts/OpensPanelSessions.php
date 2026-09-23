<?php

declare(strict_types=1);

namespace App\Domain\Provisioning\Contracts;

use App\Domain\Provisioning\PanelSession;
use App\Domain\Provisioning\ServerConnection;

/**
 * A provisioning module that can let an operator into a panel without a
 * password being typed, shown or stored anywhere new.
 *
 * **A separate contract, not a method on `ProvisioningModule`.** Most
 * panels cannot do this and the manual module never will; adding a required
 * method to the contract every module implements would break every module
 * that exists to add a capability most of them do not have. An optional
 * contract asks the question honestly: `instanceof` is the answer.
 *
 * The implementation uses the credential the platform already holds for
 * provisioning. It does not ask for a new one, does not read a password out
 * of a field an operator typed, and does not hand anything back but a URL
 * the panel itself issued and will expire.
 */
interface OpensPanelSessions
{
    /**
     * Ask the panel for a session.
     *
     * Returns null when this particular server cannot issue one — an older
     * panel version, a credential with insufficient rights — so the caller
     * can say "this server does not offer it" rather than showing an error
     * that looks like a fault.
     */
    public function openServerSession(ServerConnection $server): ?PanelSession;
}
