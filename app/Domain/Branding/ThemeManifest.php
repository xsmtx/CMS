<?php

declare(strict_types=1);

namespace App\Domain\Branding;

use App\Domain\Branding\Exceptions\InvalidTheme;

/**
 * What a theme says about itself.
 *
 * **JSON, not PHP.** A manifest that is a PHP file returning an array is a
 * file the platform executes the moment it looks at a theme — before any
 * decision about whether to trust it. Reading JSON means a malformed or
 * hostile theme produces a parse error and a named file, not code running
 * as the web user.
 *
 * `compatibility` is a range against the platform version, checked at
 * install and again at boot. A theme built for a version whose template
 * variables have since changed renders a broken page, and an operator who
 * upgraded yesterday will blame the upgrade rather than the theme — which
 * is true, and still their problem to fix.
 */
final readonly class ThemeManifest
{
    /**
     * @param  list<Surface>  $surfaces
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $version,
        public array $surfaces,
        public ?string $parent = null,
        public ?string $author = null,
        public string $compatibility = '*',
        public array $settings = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path): self
    {
        foreach (['slug', 'name', 'version'] as $required) {
            if (! isset($data[$required]) || ! is_string($data[$required]) || $data[$required] === '') {
                throw InvalidTheme::missingField($path, $required);
            }
        }

        /** @var list<string> $surfaceValues */
        $surfaceValues = is_array($data['surfaces'] ?? null) ? array_values($data['surfaces']) : [];

        $surfaces = array_values(array_filter(array_map(
            static fn (mixed $value): ?Surface => is_string($value) ? Surface::tryFrom($value) : null,
            $surfaceValues,
        )));

        if ($surfaces === []) {
            throw InvalidTheme::missingField($path, 'surfaces');
        }

        /** @var array<string, mixed> $settings */
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];

        return new self(
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            version: (string) $data['version'],
            surfaces: $surfaces,
            parent: isset($data['parent']) && is_string($data['parent']) && $data['parent'] !== ''
                ? $data['parent']
                : null,
            author: isset($data['author']) && is_string($data['author']) ? $data['author'] : null,
            compatibility: isset($data['compatibility']) && is_string($data['compatibility'])
                ? $data['compatibility']
                : '*',
            settings: $settings,
        );
    }

    public function supports(Surface $surface): bool
    {
        return in_array($surface, $this->surfaces, strict: true);
    }

    /**
     * Whether this theme will run on the given platform version.
     *
     * Deliberately simple: `*`, or a `>=x.y` floor, or an exact match. A
     * full semver range parser is a dependency and a class of bug, and a
     * theme ecosystem that needs `^1.2 || ~2.0` does not exist yet.
     */
    public function isCompatibleWith(string $platformVersion): bool
    {
        $range = trim($this->compatibility);

        if ($range === '' || $range === '*') {
            return true;
        }

        if (str_starts_with($range, '>=')) {
            return version_compare($platformVersion, trim(substr($range, 2)), '>=');
        }

        return version_compare($platformVersion, $range, '==');
    }
}
