<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * The version of the contracts this platform offers modules.
 *
 * Separate from the platform's own version on purpose. The product can
 * release all year without the extension surface moving; when the surface
 * *does* move, every module that was built against the old one refuses at
 * install time with a sentence naming both versions, rather than breaking
 * at the moment a customer is waiting.
 *
 * The rule for changing it: **adding** a method to `Module` with a default
 * in `BaseModule`, or adding a member to an enum modules only read, is a
 * minor bump. Changing or removing anything a module implements or calls is
 * a major one. A major bump is a decision, not a consequence — it makes
 * every existing module refuse until its author has looked.
 */
final class Sdk
{
    public const string VERSION = '1.2';
}
