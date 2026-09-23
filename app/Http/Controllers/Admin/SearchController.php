<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Shared\SearchEverything;
use App\Http\Controllers\Controller;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One box, every kind of record.
 *
 * An operator on the telephone has one fact and no idea which screen it
 * belongs to. Asking them to pick a screen first is asking them to guess.
 *
 * Authorized on the ability to see customers, because everything reachable
 * from here hangs off one — and every destination re-checks its own gate on
 * arrival, as every destination in this panel does.
 */
final class SearchController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function __invoke(Request $request, SearchEverything $search): Response
    {
        if (! $this->actor->can('crm.customers.view')) {
            throw new ForbiddenException(__('crm.errors.not_permitted'));
        }

        $term = $request->string('q')->toString();

        return Inertia::render('Admin/Search/Index', [
            'term' => $term,
            'groups' => $search->handle($term),
        ]);
    }
}
