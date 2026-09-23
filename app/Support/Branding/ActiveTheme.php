<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Domain\Branding\Surface;
use App\Domain\Branding\ThemeManifest;
use App\Infrastructure\Branding\Models\ThemeSetting;
use App\Infrastructure\Branding\ThemeRegistry;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Which theme is showing, and where its templates come from.
 *
 * **The precedence chain is registered as view paths, in order.** Laravel
 * resolves a namespaced view by trying each registered path until one has
 * the file, which is exactly the semantics the handoff asks for:
 *
 * ```text
 * installation override -> child theme -> parent theme -> core fallback
 * ```
 *
 * Doing it this way means **no controller changes at all**. Every
 * storefront controller already renders `storefront::catalog` through
 * `StorefrontRenderer`; pointing that namespace at four directories instead
 * of one is the entire feature.
 *
 * The installation override sits outside the theme directory on purpose. An
 * operator who has to change one line of one template should not have to
 * fork a theme, and their change has to survive the theme being upgraded.
 *
 * Resolution is per request and memoised, because the storefront asks for
 * the active theme once per rendered view and a page renders several.
 */
final class ActiveTheme
{
    /** @var array<string, string> */
    private array $resolved = [];

    private ?string $registered = null;

    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly OrganizationContext $organizations,
        private readonly ViewFactory $views,
    ) {}

    /**
     * The theme slug in force for a surface.
     */
    public function slugFor(Surface $surface): string
    {
        $key = $surface->value;

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $organizationId = $this->organizations->id();

        if ($organizationId === null) {
            return $this->resolved[$key] = ThemeRegistry::FALLBACK;
        }

        $chosen = $this->organizations->withoutBoundary(
            static fn (): ?ThemeSetting => ThemeSetting::query()
                ->where('organization_id', $organizationId)
                ->where('surface', $surface->value)
                ->first(),
        );

        // A theme that was chosen and then deleted from disk falls back to
        // core rather than to a white page: the operator has a broken
        // choice, not a broken site.
        $slug = $chosen instanceof ThemeSetting ? $chosen->theme : ThemeRegistry::FALLBACK;

        return $this->resolved[$key] = $this->registry->find($surface, $slug) instanceof ThemeManifest
            ? $slug
            : ThemeRegistry::FALLBACK;
    }

    /**
     * Point the surface's view namespace at its chain.
     *
     * Called from middleware once the organization is known, because the
     * theme depends on whose storefront this is and that is not known at
     * boot.
     */
    public function register(Surface $surface): void
    {
        $namespace = $surface->viewNamespace();

        if ($namespace === null) {
            return;
        }

        $slug = $this->slugFor($surface);

        if ($this->registered === $slug) {
            return;
        }

        $this->registered = $slug;

        $this->views->replaceNamespace($namespace, $this->pathsFor($surface, $slug));
    }

    /**
     * @return list<string>
     */
    public function pathsFor(Surface $surface, string $slug): array
    {
        $paths = [];

        // 1. The installation's own override, outside any theme, so that
        //    one changed file survives a theme upgrade.
        $override = base_path('themes/overrides/'.$surface->value);

        if (is_dir($override)) {
            $paths[] = $override;
        }

        // 2. The theme, then its parents, nearest first.
        foreach ($this->registry->chain($surface, $slug) as $manifest) {
            $paths[] = $this->registry->pathFor($surface, $manifest->slug).'/views';
        }

        return $paths;
    }

    /**
     * The theme's own settings, merged down its chain.
     *
     * @return array<string, mixed>
     */
    public function settingsFor(Surface $surface): array
    {
        $organizationId = $this->organizations->id();
        $slug = $this->slugFor($surface);

        $chosen = $organizationId === null
            ? null
            : $this->organizations->withoutBoundary(
                static fn (): ?ThemeSetting => ThemeSetting::query()
                    ->where('organization_id', $organizationId)
                    ->where('surface', $surface->value)
                    ->first(),
            );

        /** @var array<string, mixed> $settings */
        $settings = $chosen instanceof ThemeSetting ? ($chosen->settings ?? []) : [];

        return $this->registry->settingsFor($surface, $slug, $settings);
    }

    /**
     * Forget the resolved theme, so a change made this request is seen.
     */
    public function forget(): void
    {
        $this->resolved = [];
        $this->registered = null;
    }
}
