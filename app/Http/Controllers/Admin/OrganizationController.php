<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization tree: the provider, its resellers, and their customers.
 *
 * The navigation has offered this since Phase 0 and no route ever answered
 * it — a menu row that has been lying for twelve phases, found by the test
 * that opens every destination the menu offers.
 *
 * Read-only, and that is not a placeholder. An organization is created by
 * the thing that needs one: a customer when a client is added, a reseller
 * when Phase 13 arrives. A screen that let somebody conjure one would
 * produce a node with nothing hanging off it and no way to tell what it was
 * for.
 *
 * Ordered by `path`, which is the materialised ancestry: sorting by it puts
 * every child directly under its parent without a recursive query, and the
 * depth is how many segments it has.
 */
final class OrganizationController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(): Response
    {
        if (! $this->actor->can('organizations.view')) {
            throw new ForbiddenException(__('organizations.errors.not_permitted'));
        }

        $organizations = Organization::query()
            ->withCount('children')
            ->orderBy('path')
            ->get();

        return Inertia::render('Admin/Organizations/Index', [
            'organizations' => array_values($organizations
                ->map(static fn (Organization $organization): array => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'type' => $organization->type->value,
                    'typeLabel' => (string) __($organization->type->labelKey()),
                    'isActive' => $organization->is_active,
                    // Two segments of path means a child of the root, and
                    // so on. The tree is drawn from this rather than from a
                    // recursive query nobody would be able to page.
                    'depth' => max(0, substr_count(trim($organization->path, '/'), '/')),
                    'children' => (int) $organization->getAttribute('children_count'),
                    'createdAt' => $organization->created_at->toIso8601String(),
                ])
                ->all()),
        ]);
    }
}
