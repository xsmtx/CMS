<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Health\HealthChecks;
use App\Application\Health\MaintenanceMode;
use App\Domain\Health\HealthReport;
use App\Http\Controllers\Controller;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What is working and what is not.
 *
 * Nothing on this page is a configuration value. The database check reports
 * a round trip in milliseconds, not a host; the mail check reports what the
 * delivery log says, not a transport DSN. An operator opens this page when
 * something is wrong, often in front of somebody else, and a health screen
 * that proves the database is configured by printing the connection string
 * has published a password to whoever is watching.
 */
final class HealthController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly HealthChecks $checks,
        private readonly MaintenanceMode $maintenance,
    ) {}

    public function index(): Response
    {
        if (! $this->actor->can('platform.health.view')) {
            throw new ForbiddenException(__('automation.errors.not_permitted'));
        }

        $reports = $this->checks->run();

        return Inertia::render('Admin/Health/Index', [
            'overall' => $this->checks->overall($reports)->value,
            'checks' => array_map(
                static fn (HealthReport $report): array => [
                    'key' => $report->key,
                    'label' => (string) __('health.checks.'.$report->key),
                    'state' => $report->state->value,
                    'stateLabel' => (string) __($report->state->labelKey()),
                    'detail' => $report->detail,
                    'measurements' => $report->measurements,
                ],
                $reports,
            ),
            'runtime' => [
                'version' => (string) config('platform.version', 'dev'),
                'php' => PHP_VERSION,
                'environment' => (string) config('app.env'),
                'checkedAt' => CarbonImmutable::now()->toIso8601String(),
            ],
            'maintenance' => [
                'active' => $this->maintenance->isActive(),
                'message' => $this->maintenance->message(),
                'until' => $this->maintenance->until()?->toIso8601String(),
            ],
            'can' => ['manage' => $this->actor->can('platform.maintenance.manage')],
        ]);
    }
}
