<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Catalog\DeleteProductGroup;
use App\Application\Catalog\ProductGroupAttributes;
use App\Application\Catalog\SaveProductGroup;
use App\Domain\Catalog\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductGroupRequest;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ProductGroupController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(): Response
    {
        $this->authorize('viewAny', ProductGroup::class);

        return Inertia::render('Admin/Catalog/Groups/Index', [
            'groups' => ProductGroup::query()
                ->withCount('products')
                ->orderBy('position')
                ->orderBy('name')
                ->get()
                ->map(fn (ProductGroup $group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'slug' => $group->slug,
                    'description' => $group->description,
                    'status' => $group->status->value,
                    'position' => $group->position,
                    'productCount' => $group->products_count,
                ])
                ->values()
                ->all(),
            'statuses' => self::statuses(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ProductGroup::class);

        return Inertia::render('Admin/Catalog/Groups/Form', [
            'group' => null,
            'statuses' => self::statuses(),
        ]);
    }

    public function store(ProductGroupRequest $request, SaveProductGroup $save): RedirectResponse
    {
        $this->authorize('create', ProductGroup::class);

        $save->handle(
            (string) $this->actor->organizationId(),
            $this->attributes($request),
            null,
            $this->actor->model(),
        );

        return to_route('admin.catalog.groups.index')->with('status', __('catalog.groups.saved'));
    }

    public function edit(ProductGroup $group): Response
    {
        $this->authorize('view', $group);

        return Inertia::render('Admin/Catalog/Groups/Form', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'description' => $group->description,
                'status' => $group->status->value,
                'position' => $group->position,
            ],
            'statuses' => self::statuses(),
        ]);
    }

    public function update(ProductGroupRequest $request, ProductGroup $group, SaveProductGroup $save): RedirectResponse
    {
        $this->authorize('update', $group);

        $save->handle($group->organization_id, $this->attributes($request), $group, $this->actor->model());

        return to_route('admin.catalog.groups.index')->with('status', __('catalog.groups.saved'));
    }

    public function destroy(ProductGroup $group, DeleteProductGroup $delete): RedirectResponse
    {
        $this->authorize('delete', $group);

        $delete->handle($group, $this->actor->model());

        return to_route('admin.catalog.groups.index')->with('status', __('catalog.groups.deleted'));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function statuses(): array
    {
        return array_map(
            fn (CatalogStatus $status): array => [
                'value' => $status->value,
                'label' => (string) __('catalog.status.'.$status->value),
            ],
            CatalogStatus::cases(),
        );
    }

    private function attributes(ProductGroupRequest $request): ProductGroupAttributes
    {
        return new ProductGroupAttributes(
            name: $request->string('name')->toString(),
            slug: $request->input('slug'),
            description: $request->input('description'),
            status: CatalogStatus::from($request->string('status')->toString()),
            position: (int) $request->input('position', 0),
        );
    }
}
