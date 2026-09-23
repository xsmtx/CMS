<?php

declare(strict_types=1);

namespace App\Application\Modules;

use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ConfigFieldType;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * What an operator told a module.
 *
 * Validated against the schema the module declared, so a module never draws
 * its own form and core always knows what it stored. Anything not in the
 * schema is dropped: a request carrying an extra key is either a stale form
 * or somebody trying one, and neither is a reason to keep it.
 *
 * **A blank secret means "leave it alone".** The screen never receives a
 * secret it can echo back, so an operator editing the endpoint of a gateway
 * would otherwise clear its API key by saving the form — which is how a
 * working integration breaks at the moment nobody touched it.
 *
 * The audit record names the keys that changed and never their values. "Who
 * changed this module's settings" is answerable; "what was the old API key"
 * deliberately is not.
 */
final readonly class SaveModuleConfig
{
    /**
     * @param  list<ConfigField>  $schema
     * @param  array<string, mixed>  $input
     */
    public function handle(
        ModuleRecord $record,
        array $schema,
        array $input,
        ?Model $actor = null,
    ): ModuleRecord {
        $existing = $record->config ?? [];
        $config = [];
        $changed = [];

        foreach ($schema as $field) {
            $value = $input[$field->key] ?? null;

            if ($field->isSecret() && ($value === null || $value === '')) {
                // Untouched. Keep whatever is there, including nothing.
                if (array_key_exists($field->key, $existing)) {
                    $config[$field->key] = $existing[$field->key];
                }

                continue;
            }

            $config[$field->key] = $this->cast($field, $value);

            if (($existing[$field->key] ?? null) !== $config[$field->key]) {
                $changed[] = $field->key;
            }
        }

        $record->forceFill(['config' => $config])->save();

        Audit::action('modules.configured')
            ->by($actor)
            ->on($record)
            ->withMetadata([
                // Keys, never values. One of these is an API key.
                'changed' => $changed,
            ])
            ->write();

        return $record;
    }

    private function cast(ConfigField $field, mixed $value): mixed
    {
        return match ($field->type) {
            ConfigFieldType::Boolean => (bool) $value,
            ConfigFieldType::Number => is_numeric($value) ? (int) $value : ($field->default ?? 0),
            default => is_scalar($value) ? (string) $value : '',
        };
    }
}
