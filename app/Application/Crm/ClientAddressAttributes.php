<?php

declare(strict_types=1);

namespace App\Application\Crm;

/**
 * Where to invoice them.
 *
 * A billing address, specifically. A shipping address is a different thing
 * this platform does not sell anything that needs.
 */
final readonly class ClientAddressAttributes
{
    public function __construct(
        public string $lineOne,
        public string $city,
        public string $countryCode,
        public ?string $lineTwo = null,
        public ?string $region = null,
        public ?string $postalCode = null,
    ) {}
}
