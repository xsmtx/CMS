<?php

declare(strict_types=1);

namespace App\Support\View;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use RuntimeException;

/**
 * Default storefront renderer: Blade templates resolved through the theme
 * precedence chain (installation override -> child theme -> parent theme ->
 * core fallback). Phase 9 registers the theme view paths; until then only the
 * core fallback namespace exists.
 */
final readonly class BladeStorefrontRenderer implements StorefrontRenderer
{
    public function __construct(private ViewFactory $views) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data = []): Renderable
    {
        if (! $this->has($view)) {
            throw new RuntimeException("No storefront template resolves [{$view}].");
        }

        return $this->views->make($this->qualify($view), $data);
    }

    public function has(string $view): bool
    {
        return $this->views->exists($this->qualify($view));
    }

    private function qualify(string $view): string
    {
        return str_contains($view, '::') ? $view : 'storefront::'.$view;
    }
}
