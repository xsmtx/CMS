<?php

declare(strict_types=1);

namespace App\Domain\Reliability;

/**
 * What the banner at the top of a status page says.
 *
 * Three, and they are about **the service**, not about the incident. A
 * severity says how urgently somebody deals with it; this says what a
 * customer standing outside can expect — and those are different questions
 * asked by different people. Printing "Customers affected" as the headline of
 * a public page would be operator vocabulary on a customer surface, which is
 * the mistake the storefront already taught once.
 *
 * Derived, never stored. A column would be a second answer to a question the
 * open incidents already answer, and it would be the one that went stale.
 */
enum PublicStatusLevel: string
{
    case Operational = 'operational';
    case Disrupted = 'disrupted';
    case Outage = 'outage';

    /**
     * The worst of what is open, which is what a banner has to say.
     *
     * Nothing open is `Operational` — deliberately a positive statement
     * rather than an absence. A status page whose good state is a blank space
     * is a page a customer cannot tell from one that failed to load.
     *
     * @param  list<AlertSeverity>  $open
     */
    public static function fromOpen(array $open): self
    {
        if ($open === []) {
            return self::Operational;
        }

        foreach ($open as $severity) {
            if ($severity === AlertSeverity::Emergency) {
                return self::Outage;
            }
        }

        return self::Disrupted;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Operational => 'healthy',
            self::Disrupted => 'warning',
            self::Outage => 'critical',
        };
    }

    public function labelKey(): string
    {
        return 'reliability.status_page.levels.'.$this->value;
    }
}
