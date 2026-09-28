<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Contracts;

use App\Domain\Infrastructure\Sites\SiteInstallation;

/**
 * Something that knows which web applications are installed on this
 * installation's hosting accounts (§18).
 *
 * WP Toolkit on Plesk or cPanel, Softaculous, Installatron, a control panel's
 * own inventory. **Never the site itself**: a WordPress installation cannot
 * be asked what it is running without a plugin inside it, and a platform that
 * required one would be a platform that asks every customer's permission
 * before it can answer "which of our sites has the vulnerable plugin".
 *
 * **It reads and never updates.** §18 asks for update and maintenance
 * operations as well, and they are deliberately not here: changing somebody
 * else's site is a guarded workflow — back up, apply, verify, roll back — and
 * the only one this product has is for network devices. There is no
 * `site.update.write` capability either, unlike the firewall's write that
 * exists with no method: a firewall's write is one somebody could call today
 * over the same connection, and an update capability an operator could switch
 * on with nothing in core that would ever call it is a switch that lies.
 *
 * **A read that failed must throw, never return `[]`.** An empty answer means
 * this panel hosts no sites, which retires every site node the adapter has
 * ever written — and a fleet that empties itself during an outage is a fleet
 * that says the vulnerable plugin is gone.
 */
interface SiteProvider extends InfrastructureAdapter
{
    /**
     * Every application this panel can see.
     *
     * The components come with them rather than through a second call per
     * site: a panel with four hundred sites would otherwise be four hundred
     * requests, which is the mistake `monitoring-prometheus` was written to
     * avoid. An adapter that genuinely has to ask per site is free to, and
     * `RateLimits` is how it says so.
     *
     * @return list<SiteInstallation>
     */
    public function sites(): array;
}
