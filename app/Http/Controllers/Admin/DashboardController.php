<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin shell landing page.
 *
 * Phase 0 deliberately renders structure only: the metrics it will show are
 * produced by contexts that do not exist yet.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('platform.health.view');

        return Inertia::render('Admin/Dashboard', [
            'environment' => app()->environment(),
            'version' => config('app.version'),
        ]);
    }
}
