<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Domain\Ordering\LineKind;
use App\Domain\Provisioning\AutoSetup;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Ordering\Models\OrderItem;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a paid order into services.
 *
 * Every value is **copied** from the order line, which was itself copied
 * from the catalog ([ADR 0021](../../../docs/adr/0021-order-lines-copy-the-catalog.md)).
 * Nothing here reads a price back through a product: a product repriced
 * next March must not change what an existing service costs.
 *
 * Called whenever an order reaches `paid`, which can happen more than once
 * — a webhook replayed, an operator recording a transfer that a webhook
 * then confirms. The unique index on `order_item_id` is what makes that
 * safe: a second run finds the service that exists rather than creating a
 * duplicate account's worth of intent.
 *
 * Addon lines do not become services of their own. They are part of what
 * the parent service is, and splitting them would give a customer two rows
 * for one thing they bought.
 */
final readonly class CreateServicesForOrder
{
    /**
     * @return list<Service>
     */
    public function handle(Order $order, ?Model $actor = null): array
    {
        $order->loadMissing(['items.options', 'items.product', 'customer']);

        $created = [];

        foreach ($order->items as $item) {
            if (! $this->isProvisionable($item)) {
                continue;
            }

            $service = $this->serviceFor($order, $item, $actor);

            if ($service instanceof Service) {
                $created[] = $service;
            }
        }

        return $created;
    }

    /**
     * Whether an order should be set up without anybody pressing anything.
     */
    public static function shouldAutoProvision(?Product $product): bool
    {
        return $product?->auto_setup === AutoSetup::OnPayment
            || $product?->auto_setup === AutoSetup::OnOrder;
    }

    /**
     * Which lines become something a customer runs.
     *
     * A domain is a service in the commercial sense but not in this one:
     * it is Phase 7's, with a registrar behind it rather than a
     * provisioning module.
     */
    private function isProvisionable(OrderItem $item): bool
    {
        return $item->kind === LineKind::Product;
    }

    private function serviceFor(Order $order, OrderItem $item, ?Model $actor): ?Service
    {
        $existing = Service::query()->where('order_item_id', $item->id)->first();

        if ($existing instanceof Service) {
            // Seen before. The first run did the work.
            return null;
        }

        $product = $item->product;

        try {
            $service = DB::transaction(function () use ($order, $item, $product): Service {
                $service = Service::query()->create([
                    'organization_id' => $order->organization_id,
                    'customer_id' => $order->customer_id,
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'module' => $product?->provisioning_module,
                    'status' => ServiceStatus::Pending->value,

                    // The copy.
                    'name' => $item->name,
                    'package' => $product === null ? null : ($product->provisioning_package ?? $product->slug),
                    'billing_cycle' => $item->billing_cycle?->value,
                    'currency_code' => $order->currency_code,
                    'recurring_minor' => $item->line_recurring->minorUnits,
                    'setup_minor' => $item->line_setup->minorUnits,
                    'domain' => $item->domain,

                    'starts_on' => CarbonImmutable::now()->toDateString(),
                    'next_due_on' => $this->nextDueDate($item),
                    'configuration' => $this->configuration($item),
                ]);

                $this->writeOptions($service, $item);

                return $service;
            });
        } catch (UniqueConstraintViolationException) {
            // Two runs raced. The row that exists is the right answer.
            return null;
        }

        Audit::action('provisioning.service.created')
            ->by($actor)
            ->on($service)
            ->forOrganization($service->organization_id)
            ->withMetadata([
                'order' => $order->number,
                'product' => $item->name,
                'module' => $service->module,
            ])
            ->write();

        return $service;
    }

    /**
     * When the next invoice is due.
     *
     * One term from today. Phase 9's renewal run advances it; nothing here
     * pretends to know what happens after the first one.
     */
    private function nextDueDate(OrderItem $item): ?string
    {
        $cycle = $item->billing_cycle;

        if ($cycle === null || $cycle->months() === 0) {
            // A one-off purchase is never due again.
            return null;
        }

        return CarbonImmutable::now()->addMonths($cycle->months())->toDateString();
    }

    /**
     * The chosen options, flattened for an adapter to read.
     *
     * @return array<string, string>
     */
    private function configuration(OrderItem $item): array
    {
        $configuration = [];

        foreach ($item->options as $option) {
            $configuration[$option->group_name] = $option->label;
        }

        return $configuration;
    }

    private function writeOptions(Service $service, OrderItem $item): void
    {
        $position = 0;

        foreach ($item->options as $option) {
            $service->options()->create([
                'organization_id' => $service->organization_id,
                'group_name' => $option->group_name,
                'label' => $option->label,
                'value' => $option->value,
                'position' => $position++,
            ]);
        }
    }
}
