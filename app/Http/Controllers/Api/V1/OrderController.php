<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResource;
use App\Http\Api\QueryOptions;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A customer's orders, read-only.
 *
 * Placing an order through the API needs a cart, a price the customer
 * agreed to, a tax decision and a risk decision — the whole checkout, which
 * is a surface of its own rather than one endpoint. Reading orders is what
 * an integration actually asks for first: reconciling what was bought
 * against what its own system thinks.
 */
final class OrderController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            filters: ['status' => 'status', 'currency' => 'currency_code'],
            sorts: ['created_at', 'placed_at', 'total_minor'],
        );

        $orders = $options
            ->applyTo($this->customer->owned(Order::query()), $request)
            ->paginate($options->perPage($request));

        return new JsonResponse(ApiResource::page(
            $orders,
            array_values(array_map($this->row(...), $orders->items())),
        ));
    }

    public function show(string $order): JsonResponse
    {
        /** @var Order $record */
        $record = $this->customer->find(Order::query()->whereKey($order));

        $record->load(['items.options', 'items.children.options']);

        return new JsonResponse(ApiResource::item([
            ...$this->row($record),
            'items' => $record->items
                ->map(fn (OrderItem $item): array => $this->item($item))
                ->values()
                ->all(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function item(OrderItem $item, bool $withChildren = true): array
    {
        return [
            'name' => $item->name,
            'kind' => $item->kind->value,
            'quantity' => $item->quantity,
            'billing_cycle' => $item->billing_cycle?->value,
            'domain' => $item->domain,
            'line_total' => ApiResource::money($item->line_total),
            // One level, which is the shape an order has: a product with
            // its addons under it.
            'items' => $withChildren
                ? $item->children
                    ->map(fn (OrderItem $child): array => $this->item($child, withChildren: false))
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'currency' => $order->currency_code,
            'subtotal' => ApiResource::money($order->subtotal),
            'tax' => ApiResource::money($order->tax),
            'total' => ApiResource::money($order->total),
            'placed_at' => $order->placed_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
