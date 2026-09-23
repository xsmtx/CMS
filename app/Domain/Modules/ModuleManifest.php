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
