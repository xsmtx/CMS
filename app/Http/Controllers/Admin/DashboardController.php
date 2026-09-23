<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Platform\AdminOverview;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first screen of the day.
 *
 * Phase 0 rendered structure only, because the contexts that produce the
 * numbers did not exist. Twelve phases later they all do, and a dashboard
 * still showing the environment name was the one screen in the product that
 * had never been finished.
 *
 * The assembling is `AdminOverview`'s: a controller that counted overdue
 * invoices would be a controller that owns a rule, and this one has seven
 * such counts to not own.
 */
final class DashboardController extends Controller
{
    public function __invoke(AdminOverview $overview): Response
    {
        $this->authorize('platform.health.view');

        return Inertia::render('Admin/Dashboard', [
            ...$overview->handle(),
            'environment' => app()->environment(),
            'version' => config('app.version'),
        ]);
    }
}
