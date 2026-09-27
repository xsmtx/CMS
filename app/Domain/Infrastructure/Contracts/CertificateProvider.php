<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Certificates\DeployedCertificate;

/**
 * Something that knows which certificates are deployed (§8).
 *
 * A control panel, an ACME client's state directory, a load balancer's API, a
 * prober that completes a handshake and reads what it was served.
 *
 * **It reads and never issues.** `Capability::CertificateIssueWrite` and
 * `CertificateDeployWrite` exist and have no method here, for the reason the
 * firewall's write did: obtaining a certificate is a provisioning concern
 * with an account key behind it, and deploying one changes what every visitor
 * is served. Both belong behind a workflow rather than behind a method
 * anything could call — and core running ACME would be core taking
 * responsibility for a renewal it cannot see fail.
 *
 * **It answers the whole inventory, not a page.** A sweep replaces what this
 * source reported last time: a certificate the adapter stops naming has been
 * replaced or removed, and core retires its row rather than deleting it —
 * "this was on that machine in March" is a question somebody asks after an
 * outage.
 */
interface CertificateProvider extends InfrastructureAdapter
{
    /**
     * Everything this source currently has deployed.
     *
     * @return list<DeployedCertificate>
     */
    public function certificates(): array;
}
