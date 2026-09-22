<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\View\StorefrontRenderer;
use Illuminate\Contracts\Support\Renderable;

/**
 * Public storefront entry point, rendered through the renderer abstraction
 * rather than a hard dependency on Blade or Inertia.
 */
final class StorefrontController extends Controller
{
    public function __invoke(StorefrontRenderer $renderer): Renderable
    {
        return $renderer->render('home', [
            'brand' => config('app.name'),
        ]);
    }
}
