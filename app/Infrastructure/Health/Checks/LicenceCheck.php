<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Application\Licensing\LicenceState;
use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Health\HealthReport;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Whether this installation's licence is in order.
 *
 * Three states, because a licence has three interesting conditions and
 * collapsing them would make the useful one invisible:
 *
 * - **ok** — live and in contact, or no licence configured at all. The second
 *   is not a problem: a self-hosted installation with no commercial
 *   relationship is a supported way to run this.
 * - **degraded** — inside the grace period. Everything still works and
 *   somebody should look before it stops. This is the state the check exists
 *   for: it is the only warning anybody gets.
 * - **failing** — lapsed. Suspended, revoked, expired, or out of contact for
 *   longer than grace.
 *
 * **It reads a row and never calls the vendor.** A health page is opened when
 * something is already broken, and a check that made an HTTP request to a third
 * party would be a check that hangs for ten seconds during the incident it was
 * needed for. The heartbeat task does the talking.
 *
 * Nothing here returns a configuration value — not the licence key, not the
 * API URL, not the public key. The licence id and the edition are the
 * installation's own commercial facts and are safe; the key is the one secret
 * in this context and a test asserts it never appears.
 */
final readonly class LicenceCheck implements HealthCheck
{
    public function key(): string
    {
        return 'licence';
    }

    public function run(): HealthReport
    {
        try {
            $state = LicenceState::load();
        } catch (Throwable) {
            // A malformed state row is not a reason for the health page to
            // fail — it is a reason for this one line to say it cannot tell.
            return HealthReport::degraded($this->key(), (string) __('health.licence.unreadable'));
        }

        if (! $state->configured) {
            return HealthReport::ok($this->key(), [
                'licence' => (string) __('health.licence.unlicensed'),
            ]);
        }

        $measurements = array_filter([
            'edition' => $state->edition,
            // The word, not the enum's value: this is read by an operator,
            // and `revoked` is a member of an enum rather than a sentence.
            'status' => (string) __($state->status->labelKey()),
            'expires_in_days' => $this->days($state->expiresAt),
            'last_contact_days_ago' => $this->daysAgo($state->lastContactAt),
        ], static fn (mixed $value): bool => $value !== null);

        if (! $state->isLive()) {
            return HealthReport::failing(
                $this->key(),
                (string) __('health.licence.not_active'),
                $measurements,
            );
        }

        if ($state->isInGrace()) {
            return HealthReport::degraded(
                $this->key(),
                (string) __('health.licence.in_grace'),
                $measurements,
            );
        }

        return HealthReport::ok($this->key(), $measurements);
    }

    private function days(?CarbonImmutable $at): ?int
    {
        return $at === null ? null : (int) CarbonImmutable::now()->diffInDays($at, absolute: false);
    }

    private function daysAgo(?CarbonImmutable $at): ?int
    {
        return $at === null ? null : (int) $at->diffInDays(CarbonImmutable::now(), absolute: true);
    }
}
