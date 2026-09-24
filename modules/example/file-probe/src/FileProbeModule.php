<?php

declare(strict_types=1);

namespace Example\FileProbe;

use App\Domain\Infrastructure\Contracts\InfrastructureAdapter;
use App\Domain\Modules\BaseModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Domain\Modules\ModuleContext;

/**
 * The worked example for handoff #2: an infrastructure adapter written outside
 * core.
 *
 * `status-board` proves the rest of the SDK from where a third party stands; this
 * proves the adapter seam. Nothing in core references either, which is the point —
 * if the capability registry stops working for somebody else's package,
 * `tests/Feature/ExampleFileProbeTest.php` fails.
 *
 * **Why a file rather than Prometheus.** A real monitoring adapter calls a real
 * monitoring system, which a test cannot do and a reader cannot run. A file can be
 * both: plenty of shops already have a cron writing node statistics somewhere, and
 * reading one exercises every part of the seam that matters — a declared
 * capability, a declared batch size, a partial answer, and raw vendor metric names
 * and units going through core's normalizer rather than the module pretending to
 * know what this platform calls things.
 *
 * It needs a **path**, not a credential, which is why Phase A can ship an adapter
 * before the credential vault exists.
 *
 * The file looks like this:
 *
 * ```json
 * {
 *   "sampled_at": "2026-09-24T10:00:00Z",
 *   "resources": {
 *     "01JABCDEF...": {
 *       "cpu_percent": 42.5,
 *       "memory_used": { "value": 8, "unit": "gigabytes" },
 *       "load": 1.2
 *     }
 *   }
 * }
 * ```
 *
 * The keys under `resources` are node keys as the graph holds them. A bare number
 * is read in the metric's own canonical unit; an object may name any unit core
 * knows, and core does the arithmetic.
 */
final class FileProbeModule extends BaseModule
{
    private string $path = '';

    private int $staleAfter = 600;

    /**
     * @return list<ConfigField>
     */
    public function configSchema(): array
    {
        return [
            new ConfigField(
                key: 'path',
                label: 'Path to the measurements file',
                type: ConfigFieldType::Text,
                required: true,
                hint: 'A JSON file this server can read. Something else writes it.',
            ),
            new ConfigField(
                key: 'stale_after',
                label: 'Stale after (seconds)',
                type: ConfigFieldType::Number,
                required: false,
                default: 600,
                hint: 'How long a reading stays meaningful. Match whatever writes the file.',
            ),
        ];
    }

    /**
     * @return list<InfrastructureAdapter>
     */
    public function adapters(): array
    {
        // No path, no adapter. A module that registered an adapter it could not
        // use would put a row on the Adapters screen that can only ever be
        // `failing`, and an operator would reasonably read that as broken
        // software rather than as unfinished configuration.
        return $this->path === '' ? [] : [new FileProbe($this->path, $this->staleAfter)];
    }

    public function boot(ModuleContext $context): void
    {
        $this->path = $context->string('path');
        $this->staleAfter = max(0, $context->int('stale_after', 600));
    }
}
