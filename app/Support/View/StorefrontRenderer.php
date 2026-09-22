<?php

declare(strict_types=1);

namespace App\Support\View;

use Illuminate\Contracts\Support\Renderable;

/**
 * Renders a public storefront page.
 *
 * The admin and client areas are Inertia applications and will stay that way.
 * The public storefront is different: it must be themeable with plain
 * templates, indexable, and replaceable by a headless front end. Routing it
 * through this contract keeps that option open — swapping the binding is the
 * whole migration.
 */
interface StorefrontRenderer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data = []): Renderable;

    /**
     * Whether the active theme can render the given view.
     */
    public function has(string $view): bool;
}
