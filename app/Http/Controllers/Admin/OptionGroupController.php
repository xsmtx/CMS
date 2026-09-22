<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Catalog\DeleteOptionGroup;
use App\Application\Catalog\SaveOptionGroup;
use App\Domain\Catalog\OptionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\OptionGroupRequest;
use App\Infrastructure\Catalog\Models\Option;
use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Infrastructure\Catalog\Models\OptionPrice;
use App\Infrastructure\Catalog\Models\Product;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurable options, edited under the product they belong to.
 *
 * Authorized against the product rather than against a policy of their own:
 * an option group has no meaning apart from its product, so "may edit this
 * product's pricing" is exactly the right question.
 */
final class OptionGroupController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(Product $product): Response
    {
        $this->authorize('view', $product);

        $product->load('optionGroups.options.prices');

        return Inertia::render('Admin/Catalog/Options/Index', [
            'product' => ['id' => $product->id, 'name' => $product->name],
            'groups' => $product->optionGroups
                ->map(fn (OptionGroup $group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'key' => $group->key,
                    'type' => $group->type->value,
                    'typeLabel' => (string) __('catalog.option_types.'.$group->type->value),
                    'isRequired' => $group->is_required,
                    'position' => $group->position,
                    'choices' => $group->options->count(),
                ])
                ->values()
                ->all(),
            'canManage' => $this->actor->can('price', $product),
        ]);
    }

    public function create(Product $product): Response
    {
        $this->authorize('price', $product);

        return Inertia::render('Admin/Catalog/Options/Form', [
            'product' => ['id' => $product->id, 'name' => $product->name],
            'group' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(OptionGroupRequest $request, Product $product, SaveOptionGroup $save): RedirectResponse
    {
        $this->authorize('price', $product);

        $save->handle($product, $request->toAttributes(), null, $this->actor->model());

        return to_route('admin.catalog.products.options.index', $product)
            ->with('status', __('catalog.options.saved'));
    }

    public function edit(Product $product, OptionGroup $group): Response
    {
        $this->authorize('view', $product);

        $group->load('options.prices');

        return Inertia::render('Admin/Catalog/Options/Form', [
            'product' => ['id' => $product->id, 'name' => $product->name],
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'key' => $group->key,
                'type' => $group->type->value,
                'description' => $group->description,
                'isRequired' => $group->is_required,
                'minQuantity' => $group->min_quantity,
                'maxQuantity' => $group->max_quantity,
                'position' => $group->position,
                'options' => $group->options
                    ->map(fn (Option $option): array => [
                        'id' => $option->id,
                        'label' => $option->label,
                        'value' => $option->value,
                        'isDefault' => $option->is_default,
                        'position' => $option->position,
                        'prices' => $option->prices
                            ->map(fn (OptionPrice $price): array => [
                                'billingCycle' => $price->billing_cycle->value,
                                'currencyCode' => $price->currency_code,
                                'recurringMinor' => $price->recurring->minorUnits,
                                'setupMinor' => $price->setup->minorUnits,
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(
        OptionGroupRequest $request,
        Product $product,
        OptionGroup $group,
        SaveOptionGroup $save,
    ): RedirectResponse {
        $this->authorize('price', $product);

        $save->handle($product, $request->toAttributes(), $group, $this->actor->model());

        return to_route('admin.catalog.products.options.index', $product)
            ->with('status', __('catalog.options.saved'));
    }

    public function destroy(Product $product, OptionGroup $group, DeleteOptionGroup $delete): RedirectResponse
    {
        $this->authorize('price', $product);

        $delete->handle($group, $this->actor->model());

        return to_route('admin.catalog.products.options.index', $product)
            ->with('status', __('catalog.options.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'types' => array_map(
                fn (OptionType $type): array => [
                    'value' => $type->value,
                    'label' => (string) __('catalog.option_types.'.$type->value),
                ],
                OptionType::cases(),
            ),
            'cycles' => ProductController::cycles(),
            'currencies' => ProductController::currencies(),
        ];
    }
}
