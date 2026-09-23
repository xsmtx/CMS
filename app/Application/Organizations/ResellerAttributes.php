<?php

declare(strict_types=1);

namespace App\Application\Organizations;

/**
 * What the provider knows about a reseller when it creates one.
 *
 * The first staff account is part of it rather than a second step, because
 * a reseller organization with nobody in it is a node an operator has to
 * remember to come back to — and the thing they came to do was give
 * somebody an account.
 */
final readonly class ResellerAttributes
{
    public function __construct(
        public string $name,
        public string $ownerName,
        public string $ownerEmail,
        public ?string $slug = null,
        public ?string $locale = null,
        public ?string $timezone = null,
    ) {}
}
