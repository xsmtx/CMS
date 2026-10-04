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
 * minor bump.
 *
 * 1.3 was such a bump: `NotificationChannel` gained `Sms` and `Chat`, and
 * `NotificationRecipient` gained a phone number with a default. Nothing a
 * module implements changed — `DeliversNotifications` is untouched, which is
 * why two chat providers coexist through a registry key derived from the
 * implementation rather than through a method the interface had to grow.
 *
 * 1.4 is another: four new capability contracts (`NetworkDeviceProvider`,
 * `FirewallProvider`, `SwitchProvider`, `RoutingProvider`) and the value
 * objects they speak in. Purely additive — a contract that did not exist
 * cannot have been implemented, so no existing module has anything to look
 * at.
 *
 * 1.11 is the same shape: `SiteProvider` and the value objects it speaks
 * in (§18), plus `AdapterArea::Site` and two capabilities. New members on
 * enums a module only reads, and a contract nothing has implemented.
 *
 * 1.12 is the borderline one, and it is minor by this file's own rule:
 * `AiProvider` is a contract nothing had implemented, but `aiProviders()`
 * was added to the `Module` **interface** as well as to `BaseModule`. A
 * module extending `BaseModule` — which is the documented extension path and
 * what every module in `modules/` does — gains the default and has nothing
 * to look at. One implementing the interface directly would not compile, and
 * that is the case this paragraph exists to warn about rather than to hide.
 *
 * 1.13 is additive again: `InfrastructureAsCodeProvider` and
 * `InfrastructureAsCodeWriter` with the three value objects they speak in
 * (§25), plus `ChangeTarget` — an enum core branches on and a module only
 * reads. Two contracts nothing had implemented, so no existing module has
 * anything to look at.
 *
 * Changing or removing anything a module implements or calls is a major one.
 * A major bump is a decision, not a consequence — it makes every existing
 * module refuse until its author has looked.
 */
final class Sdk
{
    public const string VERSION = '1.13';
}
