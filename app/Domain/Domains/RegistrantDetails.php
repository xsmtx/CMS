<?php

declare(strict_types=1);

namespace App\Domain\Domains;

/**
 * Who the registry is told owns the name.
 *
 * Built from the customer's own contact and address at the moment of
 * registration. Not stored alongside the domain: the registry holds the
 * authoritative copy, and a second one here would drift the first time
 * somebody corrected their address on one side only.
 */
final readonly class RegistrantDetails
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $organization = null,
        public ?string $phone = null,
        public ?string $addressLine = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $postalCode = null,
        public ?string $countryCode = null,
    ) {}

    public function fullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    /**
     * Whether a registry would accept this.
     *
     * Checked before a call rather than after a rejection, because a
     * registry's complaint about a missing postal code arrives as an
     * unhelpful numeric code.
     */
    public function isComplete(): bool
    {
        return $this->firstName !== ''
            && $this->lastName !== ''
            && $this->email !== ''
            && $this->addressLine !== null && $this->addressLine !== ''
            && $this->city !== null && $this->city !== ''
            && $this->countryCode !== null && $this->countryCode !== ''
            && $this->phone !== null && $this->phone !== '';
    }
}
