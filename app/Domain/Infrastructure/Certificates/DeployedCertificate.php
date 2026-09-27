<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Certificates;

use Carbon\CarbonImmutable;

/**
 * A certificate as an adapter found it deployed (§8).
 *
 * **The fingerprint is the identity.** One name is served by four
 * certificates over a year and two names by one, so a value keyed on the
 * common name would collapse renewals into each other and lose exactly the
 * history somebody wants when a renewal silently failed.
 *
 * **`notAfter` is the certificate's own date.** An adapter passes on what it
 * read and never computes, adjusts or defaults it: an expiry this platform
 * guessed would be worse than none, because somebody would act on it.
 *
 * **`chainOk` is nullable and the null means "nobody looked".** An adapter
 * reading a file off disk cannot say what a client would be served, and
 * answering `true` there would be the platform asserting something it does
 * not know. Only an adapter that actually completed a handshake may say so.
 *
 * Nothing here is a private key, a CSR or an account key. Core reads what is
 * deployed; issuing is a provisioning module's job and needs none of this.
 */
final readonly class DeployedCertificate
{
    /**
     * @param  list<string>  $subjectAlternativeNames
     */
    public function __construct(
        public string $fingerprint,
        public string $commonName,
        public array $subjectAlternativeNames,
        public string $issuer,
        public CarbonImmutable $notBefore,
        public CarbonImmutable $notAfter,
        public ?string $serial = null,
        public ?bool $chainOk = null,
        /**
         * The node key this was found on, where the adapter knows it. A
         * certificate on a load balancer often belongs to no single machine,
         * and saying so is better than attaching it to the first one.
         */
        public ?string $nodeKey = null,
    ) {}

    /**
     * The names it covers, deduplicated and with the common name included.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_map(
            static fn (string $name): string => mb_strtolower(trim($name, '.')),
            [$this->commonName, ...$this->subjectAlternativeNames],
        );

        return array_values(array_unique(array_filter(
            $names,
            static fn (string $name): bool => $name !== '',
        )));
    }
}
