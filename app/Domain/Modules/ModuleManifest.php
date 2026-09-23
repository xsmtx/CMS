<?php

declare(strict_types=1);

namespace App\Domain\Modules;

use App\Domain\Modules\Exceptions\InvalidModule;
use App\Domain\Shared\VersionRange;

/**
 * What a module says about itself.
 *
 * **JSON, not PHP**, for the reason
 * [ADR 0037](../../../docs/adr/0037-a-theme-is-a-package-and-may-not-execute.md)
 * gives for themes and which matters more here: this file is read *before*
 * anybody has decided whether to trust the package. A manifest that is a
 * PHP file returning an array runs the package's code at the moment the
 * platform first looks at it, which is precisely the decision the install
 * screen exists to put in front of an operator.
 *
 * Two ranges, checked separately because they fail for different reasons
 * and an operator needs to know which. `platform` is "this was built for a
 * different version of the product"; `sdk` is "this was built against a
 * different set of contracts". The second is the one that lets core change
 * an interface later and have every module refuse loudly at install time
 * rather than break quietly at runtime.
 */
final readonly class ModuleManifest
{
    /**
     * @param  list<string>  $dependencies  Slugs of other modules that must be enabled first.
     * @param  list<ConfigField>  $config  What the module needs to be told, declared here so it can be asked before anything runs.
     */
    public function __construct(
        public string $slug,
        public string $name,
        public ModuleType $type,
        public string $version,
        public string $namespace,
        public string $entrypoint,
        public VersionRange $sdk,
        public VersionRange $platform,
        public ?string $provider = null,
        public ?string $description = null,
        public array $dependencies = [],
        public bool $hasMigrations = false,
        public bool $hasTranslations = false,
        public array $config = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path): self
    {
        foreach (['slug', 'name', 'type', 'version', 'namespace', 'entrypoint'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || $data[$field] === '') {
                throw InvalidModule::missingField($path, $field);
            }
        }

        $type = ModuleType::tryFrom((string) $data['type']);

        if (! $type instanceof ModuleType) {
            throw InvalidModule::unknownType($path, (string) $data['type']);
        }

        $slug = (string) $data['slug'];

        // A slug reaches a directory name, a route segment and a log
        // channel. Anything that is not a plain word can leave all three.
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            throw InvalidModule::badSlug($path, $slug);
        }

        /** @var list<string> $dependencies */
        $dependencies = array_values(array_filter(
            is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [],
            is_string(...),
        ));

        return new self(
            slug: $slug,
            name: (string) $data['name'],
            type: $type,
            version: (string) $data['version'],
            namespace: rtrim((string) $data['namespace'], '\\').'\\',
            entrypoint: (string) $data['entrypoint'],
            sdk: new VersionRange(self::stringOr($data, 'sdk', '*')),
            platform: new VersionRange(self::stringOr($data, 'platform', '*')),
            provider: self::nullableString($data, 'provider'),
            description: self::nullableString($data, 'description'),
            dependencies: $dependencies,
            hasMigrations: (bool) ($data['migrations'] ?? false),
            hasTranslations: (bool) ($data['translations'] ?? false),
            config: self::fields($data['config'] ?? null, $path),
        );
    }

    /**
     * The fully-qualified entrypoint class.
     *
     * Composed from the declared namespace rather than taken whole, so a
     * manifest cannot name a class inside core and have the platform
     * construct it.
     */
    public function entrypointClass(): string
    {
        return $this->namespace.ltrim($this->entrypoint, '\\');
    }

    /**
     * The settings form, declared in JSON rather than in code.
     *
     * A module with a required field could otherwise never be enabled: the
     * schema on the interface belongs to a *running* module, and a module
     * that has not been configured cannot be started. Reading it here
     * closes that loop without running anything — asking "what do I need to
     * tell this package" is exactly a question from before the package is
     * trusted (ADR 0038).
     *
     * A module still declares `configSchema()` if it wants options worked
     * out at runtime. Once it is running, that one wins.
     *
     * A malformed field is skipped rather than fatal, like everything else
     * read from a directory somebody dropped in.
     *
     * @return list<ConfigField>
     */
    private static function fields(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            return [];
        }

        $fields = [];

        foreach ($value as $entry) {
            if (! is_array($entry) || ! isset($entry['key']) || ! is_string($entry['key'])) {
                continue;
            }

            $type = ConfigFieldType::tryFrom((string) ($entry['type'] ?? 'text'));

            if (! $type instanceof ConfigFieldType) {
                throw InvalidModule::unknownType($path, (string) ($entry['type'] ?? ''));
            }

            $options = [];

            foreach (is_array($entry['options'] ?? null) ? $entry['options'] : [] as $option) {
                if (is_array($option) && isset($option['value'], $option['label'])) {
                    $options[] = [
                        'value' => (string) $option['value'],
                        'label' => (string) $option['label'],
                    ];
                }
            }

            $default = $entry['default'] ?? null;

            $fields[] = new ConfigField(
                key: $entry['key'],
                label: is_string($entry['label'] ?? null) ? $entry['label'] : $entry['key'],
                type: $type,
                required: (bool) ($entry['required'] ?? false),
                hint: is_string($entry['hint'] ?? null) ? $entry['hint'] : null,
                default: is_scalar($default) ? $default : null,
                options: $options,
            );
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function stringOr(array $data, string $key, string $fallback): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
