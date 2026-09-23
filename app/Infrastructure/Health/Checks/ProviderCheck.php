<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Health\HealthReport;
use App\Domain\Provisioning\ServerStatus;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Organizations\OrganizationContext;

/**
 * Are the servers this platform provisions onto reachable.
 *
 * Read from what the last connection test recorded, not by calling every
 * control panel while somebody waits for a page. A health page that makes
 * ten outbound HTTPS calls takes ten seconds to load and times out on the
 * day a provider is down, which is the day it is opened.
 *
 * An installation with no servers configured is ok, not degraded. Not every
 * installation sells hosting.
 */
final readonly class ProviderCheck implements HealthCheck
{
    public function __construct(private OrganizationContext $organizations) {}

    public function key(): string
    {
        return 'providers';
    }

    public function run(): HealthReport
    {
        /** @var array{total: int, unreachable: int} $counts */
        $counts = $this->organizations->withoutBoundary(
            static fn (): array => [
                'total' => Server::query()->count(),
                'unreachable' => Server::query()
                    ->where('status', ServerStatus::Offline->value)
                    ->count(),
            ],
        );

        if ($counts['total'] === 0) {
            return HealthReport::ok($this->key(), $counts);
        }

        if ($counts['unreachable'] === $counts['total']) {
            return HealthReport::failing($this->key(), (string) __('health.providers.all_down'), $counts);
        }

        if ($counts['unreachable'] > 0) {
            return HealthReport::degraded($this->key(), (string) __('health.providers.some_down'), $counts);
        }

        return HealthReport::ok($this->key(), $counts);
    }
}
