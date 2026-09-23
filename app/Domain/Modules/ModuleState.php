<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * Where an installed module is.
 *
 * `installed` is a real, useful state and not a step on the way to
 * `enabled`: installing runs migrations and registers permissions, which is
 * work an operator may want done before a maintenance window, and it is
 * deliberately **not** consent to run the module's code.
 *
 * `failed` exists because a module that throws while registering must not
 * take the installation down with it. It is disabled, the reason is kept,
 * and everything else keeps working.
 */
enum ModuleState: string
{
    /** Files on disk and a row, migrations run, permissions synced. */
    case Installed = 'installed';

    /** Registered into the platform. The audited state. */
    case Enabled = 'enabled';

    /** Turned off deliberately. Reversible, and loses nothing. */
    case Disabled = 'disabled';

    /** Turned off by the platform, with a reason. */
    case Failed = 'failed';

    public function labelKey(): string
    {
        return 'modules.states.'.$this->value;
    }

    public function isRunning(): bool
    {
        return $this === self::Enabled;
    }

    /**
     * Whether an operator may turn this on from here.
     *
     * A failed module can be enabled again: the reason it failed is
     * usually configuration, and refusing to retry would mean an operator
     * reinstalling a module to fix a typo.
     */
    public function canEnable(): bool
    {
        return $this !== self::Enabled;
    }
}
