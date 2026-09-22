<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Client area landing page.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Client/Dashboard', [
            'services' => [],
            'invoices' => [],
        ]);
    }
}
