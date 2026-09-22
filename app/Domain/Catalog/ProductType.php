<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * What kind of thing is being sold.
 *
 * The type decides which provisioning contract a service uses once Phase 6
 * arrives, and which extra fields the order form collects. Keeping it on the
 * product rather than inferring it from the provisioning module means a
 * product can exist before its module does.
 */
enum ProductType: string
{
    case SharedHosting = 'shared_hosting';
    case Reseller = 'reseller';
    case Vps = 'vps';
    case Dedicated = 'dedicated';
    case Ssl = 'ssl';
    case Email = 'email';
    case License = 'license';
    case Service = 'service';
    case Other = 'other';

    public function labelKey(): string
    {
        return 'catalog.types.'.$this->value;
    }

    /**
     * Whether ordering this asks the customer for a domain name.
     *
     * Hosting is bound to a hostname; a software licence is not.
     */
    public function requiresDomain(): bool
    {
        return match ($this) {
            self::SharedHosting, self::Reseller, self::Ssl, self::Email => true,
            default => false,
        };
    }

    /**
     * Whether a server is assigned at provisioning time.
     */
    public function requiresServer(): bool
    {
        return match ($this) {
            self::SharedHosting, self::Reseller, self::Vps, self::Dedicated => true,
            default => false,
        };
    }
}
