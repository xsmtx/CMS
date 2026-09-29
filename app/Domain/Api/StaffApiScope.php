<?php

declare(strict_types=1);

namespace App\Domain\Api;

/**
 * What a staff device may reach (ADR 0049).
 *
 * A separate vocabulary from `ApiScope` and not an extension of it, because
 * they narrow different things: a client scope narrows what a customer shares
 * about their own account, and a staff scope narrows what an operator can do
 * to everybody's. One enum holding both would let a route ask for
 * `services:read` and get either meaning depending on which guard answered.
 *
 * **There is no member that means everything**, and there must not be. The
 * whole problem this solves is that an Administrator already holds every
 * staff permission by design — the fact that made `resellers.administer` a
 * gate rather than a permission — so a bearer string carrying all of them
 * would be root on the installation, in a pocket.
 *
 * **What is absent is as deliberate as what is here.** Firewall apply, power
 * actions, termination, restore, drain and every bulk endpoint have no scope,
 * because they have no staff route: §26 says some actions are web-only by
 * policy, and a client can be rewritten, so the refusal has to be the absence
 * of the endpoint rather than the absence of a button.
 */
enum StaffApiScope: string
{
    case AlertsRead = 'alerts:read';

    case IncidentsRead = 'incidents:read';
    case IncidentsWrite = 'incidents:write';

    case TicketsRead = 'tickets:read';
    case TicketsWrite = 'tickets:write';

    case InfrastructureRead = 'infrastructure:read';

    case AbuseRead = 'abuse:read';

    case ChangesRead = 'changes:read';
    case ChangesWrite = 'changes:write';

    case MaintenanceRead = 'maintenance:read';

    case RemoteHandsRead = 'remote_hands:read';
    case RemoteHandsWrite = 'remote_hands:write';

    case AccessRead = 'access:read';
    case AccessWrite = 'access:write';

    case DcimRead = 'dcim:read';

    public function labelKey(): string
    {
        return 'api.staff_scopes.'.$this->slug().'.label';
    }

    public function descriptionKey(): string
    {
        return 'api.staff_scopes.'.$this->slug().'.description';
    }

    public function group(): string
    {
        return explode(':', $this->value)[0];
    }

    public function isWrite(): bool
    {
        return str_ends_with($this->value, ':write');
    }

    /**
     * The permissions the holder must have for this scope to reach anything.
     *
     * A scope **only narrows** (ADR 0033): it is never a grant. A token
     * carrying `incidents:write` held by somebody without
     * `reliability.incidents.manage` reaches nothing, and `:write` never
     * implies `:read` — they are listed separately here for that reason,
     * even where one permission covers both.
     *
     * A `match` with no default, so a new member cannot be added without
     * answering "and what does the person need to be allowed to do".
     *
     * @return list<string>
     */
    public function requiredPermissions(): array
    {
        return match ($this) {
            self::AlertsRead => ['reliability.alerts.view'],
            self::IncidentsRead => ['reliability.incidents.view'],
            self::IncidentsWrite => ['reliability.incidents.manage'],
            self::TicketsRead => ['support.tickets.view'],
            self::TicketsWrite => ['support.tickets.manage'],
            self::InfrastructureRead => ['infrastructure.resources.view'],
            self::AbuseRead => ['security.abuse.view'],
            self::ChangesRead => ['network.devices.view'],
            /*
             * Deciding a change, never applying one. `network.changes.apply`
             * carries the password challenge and pushes a configuration to a
             * box; approving is a person saying yes, which is exactly what
             * §26 asks a phone to be able to do at two in the morning.
             */
            self::ChangesWrite => ['network.changes.approve'],
            self::MaintenanceRead => ['reliability.incidents.view'],
            self::RemoteHandsRead => ['dcim.remote_hands.request'],
            self::RemoteHandsWrite => ['dcim.remote_hands.complete'],
            self::AccessRead => ['infrastructure.connect'],
            self::AccessWrite => ['infrastructure.connect'],
            self::DcimRead => ['dcim.view'],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /*
     * Underscores rather than the colon, matching `ApiScope`: a dotted key
     * asks the translator to walk a level of nesting per segment, which is
     * the permission-slug trap through another door.
     */
    private function slug(): string
    {
        return str_replace(':', '_', $this->value);
    }
}
