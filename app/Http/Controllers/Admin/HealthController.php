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
use Illuminate\Support\Facades\Lang;
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
                    'measurements' => self::worded($report->measurements),
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

    /**
     * A measurement's key, said in words.
     *
     * The `health` language file has carried a `measurements` group since the
     * screen was written and nothing read it: the page printed the array key
     * instead, so an
     * operator was told `latency_ms 1` and `unreachable 0`. Wording stored
     * and read by nothing is the same lie as a setting stored and read by
     * nothing - it looks configured and it does nothing.
     *
     * A key with no wording keeps **its own name** rather than becoming
     * `health.measurements.whatever`. Core's are all named and
     * `VocabularyTest` fails if one is not; a measurement a module's own
     * check emits is not core's to name, and its key is more use to the
     * operator reading it than a path into a language file they do not have.
     *
     * @param  array<string, string|int>  $measurements
     * @return array<string, string|int>
     */
    private static function worded(array $measurements): array
    {
        $worded = [];

        foreach ($measurements as $key => $value) {
            $path = 'health.measurements.'.$key;

            $worded[Lang::has($path) ? (string) __($path) : $key] = $value;
        }

        return $worded;
    }
}
