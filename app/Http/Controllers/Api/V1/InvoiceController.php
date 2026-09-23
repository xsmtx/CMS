<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\ApiResource;
use App\Http\Api\QueryOptions;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Billing\Models\InvoiceItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A customer's invoices, read-only.
 *
 * Paying one moves money and a token is a password nobody types, so there
 * is no write here in v1. That is a decision rather than an omission: when
 * it changes it gets its own scope, its own idempotency story and its own
 * argument about what a failed charge on an unattended script should do.
 *
 * Every amount is minor units and a currency. An accounting integration
 * that received `14.99` would have to guess whether it was euros and
 * whether a float had already lost a cent.
 */
final class InvoiceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            filters: ['status' => 'status', 'currency' => 'currency_code'],
            sorts: ['created_at', 'issued_on', 'due_on', 'total_minor'],
        );

        $invoices = $options
            ->applyTo($this->customer->owned(Invoice::query()), $request)
            ->paginate($options->perPage($request));

        return new JsonResponse(ApiResource::page(
            $invoices,
            array_values(array_map($this->row(...), $invoices->items())),
        ));
    }

    public function show(string $invoice): JsonResponse
    {
        /** @var Invoice $record */
        $record = $this->customer->find(Invoice::query()->whereKey($invoice));

        $record->load('items');

        return new JsonResponse(ApiResource::item([
            ...$this->row($record),
            'items' => $record->items
                ->map(static fn (InvoiceItem $item): array => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_amount' => ApiResource::money($item->unit_amount),
                    'line_amount' => ApiResource::money($item->line_amount),
                    'period_start' => $item->period_start?->toDateString(),
                    'period_end' => $item->period_end?->toDateString(),
                ])
                ->values()
                ->all(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'currency' => $invoice->currency_code,
            'subtotal' => ApiResource::money($invoice->subtotal),
            'tax' => ApiResource::money($invoice->tax),
            'total' => ApiResource::money($invoice->total),
            'paid' => ApiResource::money($invoice->paid),
            'balance' => ApiResource::money($invoice->balance()),
            'issued_on' => $invoice->issued_on?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ];
    }
}
