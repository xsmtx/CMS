<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * What kind of value a module's configuration field holds.
 *
 * `Secret` is the member this enum exists for. A field declared secret is
 * encrypted at rest, never sent to the browser once set, and redacted in
 * logs — the same treatment a server's control-panel token already gets. A
 * module author who stores an API key in a `Text` field has made a mistake
 * the platform cannot detect, so the choice is put in front of them.
 */
enum ConfigFieldType: string
{
    case Text = 'text';
    case Secret = 'secret';
    case Boolean = 'boolean';
    case Number = 'number';
    case Select = 'select';
    case Url = 'url';

    public function isSecret(): bool
    {
        return $this === self::Secret;
    }
}
