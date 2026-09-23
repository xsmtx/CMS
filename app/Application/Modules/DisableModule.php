<?php

declare(strict_types=1);

namespace App\Application\Modules;

use App\Domain\Modules\ModuleState;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Turn a module off without losing anything.
 *
 * Disabling is the reversible half of the lifecycle, and it is deliberately
 * cheap: a row changes, and on the next request the module's code is not
 * loaded at all. A disabled gateway disappears from checkout; a disabled
 * provisioning module leaves its services exactly where they are and
 * refuses operations with a sentence.
 *
 * What it registered is **kept**, not cleared. That record is what lets
 * uninstall refuse later without loading the package, and it is what the
 * screen shows an operator who is deciding whether removing this is safe.
 */
final readonly class DisableModule
{
    public function handle(ModuleRecord $record, ?Model $actor = null, ?string $reason = null): ModuleRecord
    {
        if ($record->state === ModuleState::Disabled) {
            return $record;
        }

        $record->forceFill([
            'state' => ModuleState::Disabled->value,
            'disabled_at' => CarbonImmutable::now(),
            // Cleared on purpose: a module an operator turned off is not a
            // module that failed, and leaving the old reason there would
            // say otherwise on the screen.
            'failure_reason' => null,
        ])->save();

        Audit::action('modules.disabled')
            ->by($actor)
            ->on($record)
            ->because($reason)
            ->write();

        return $record;
    }
}
