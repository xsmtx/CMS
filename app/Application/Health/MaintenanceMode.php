<?php

declare(strict_types=1);

namespace App\Application\Health;

use App\Infrastructure\Platform\Models\PlatformState;
use Carbon\CarbonImmutable;

/**
 * The operator-facing maintenance switch.
 *
 * Not `php artisan down`. That one is for a deploy: it is set from a shell,
 * it takes the whole application out including the admin panel, and it has
 * no idea who is asking. This one has a message a customer can read, an
 * optional window, and a bypass for staff — because the person fixing the
 * thing being maintained has to be able to see it.
 *
 * Stored in `platform_state` rather than the cache, for the same reason as
 * the heartbeat: a maintenance window that ends because Redis restarted is
 * a maintenance window that did not happen.
 */
final readonly class MaintenanceMode
{
    public function enable(string $message, ?CarbonImmutable $until = null): void
    {
        PlatformState::query()->updateOrCreate(
            ['key' => PlatformState::MAINTENANCE],
            [
                'value' => [
                    'enabled' => true,
                    'message' => $message,
                    'until' => $until?->toIso8601String(),
                ],
                'updated_at' => CarbonImmutable::now(),
            ],
        );
    }

    public function disable(): void
    {
        PlatformState::query()->where('key', PlatformState::MAINTENANCE)->delete();
    }

    public function isActive(): bool
    {
        return $this->current() !== null;
    }

    public function message(): ?string
    {
        $state = $this->current();

        return $state === null ? null : $state['message'];
    }

    public function until(): ?CarbonImmutable
    {
        $state = $this->current();

        return $state === null || $state['until'] === null
            ? null
            : CarbonImmutable::parse($state['until']);
    }

    /**
     * @return array{message: string, until: string|null}|null
     */
    private function current(): ?array
    {
        $state = PlatformState::query()->find(PlatformState::MAINTENANCE);

        if ($state === null) {
            return null;
        }

        $value = $state->value;

        if (! is_array($value) || ($value['enabled'] ?? false) !== true) {
            return null;
        }

        $until = isset($value['until']) && is_string($value['until']) ? $value['until'] : null;

        // A window that has passed turns itself off. An operator who set an
        // end time should not have to come back and clear it — and a
        // maintenance banner nobody removed is how a storefront stays shut
        // for a week.
        if ($until !== null && CarbonImmutable::parse($until)->isPast()) {
            return null;
        }

        return [
            'message' => is_string($value['message'] ?? null) ? $value['message'] : '',
            'until' => $until,
        ];
    }
}
