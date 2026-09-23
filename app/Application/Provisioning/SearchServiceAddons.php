<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Application\Shared\SearchPattern;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\ProductType;
use App\Domain\Crm\CustomerStatus;
use App\Domain\Crm\CustomFieldEntity;
use App\Domain\Provisioning\AddonStatus;
use App\Infrastructure\Billing\Models\PaymentMethod;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\ServiceAddon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Finding an addon somebody is paying for.
 *
 * The same panel as the products and services screen, because it is the
 * same question asked one level down — and an operator who has learnt one
 * of these two screens should not have to learn the other.
 *
 * Where a criterion belongs to the service rather than to the addon, it is
 * asked of the service: the product type, the server and the domain all
 * come through the parent. The billing cycle, the price and the status are
 * the addon's own, because that is the whole reason this row exists
 * ([ADR 0035](../../../docs/adr/0035-an-addon-is-not-a-service.md)).
 *
 * A customer **cannot** add one of these. An addon is sold with a product,
 * at checkout or by an operator; this screen is where the operator looks at
 * what was sold.
 */
final readonly class SearchServiceAddons
{
    /**
     * @param  Builder<ServiceAddon>  $query
     * @param  array<string, mixed>  $criteria
     * @return Builder<ServiceAddon>
     */
    public function apply(Builder $query, array $criteria): Builder
    {
        $status = $this->text($criteria, 'status');

        if ($status !== null) {
            $query->where('status', $status);
        }

        $cycle = $this->text($criteria, 'billing_cycle');

        if ($cycle !== null) {
            $query->where('billing_cycle', $cycle);
        }

        $this->applyThroughService($query, $criteria);
        $this->applyClient($query, $criteria);
        $this->applyCustomFields($query, $criteria);

        $gateway = $this->text($criteria, 'gateway');

        if ($gateway !== null) {
            $query->whereHas(
                'customer',
                fn (Builder $customer) => $customer->whereHas(
                    'paymentMethods',
                    fn (Builder $cards) => $cards->where('gateway', $gateway),
                ),
            );
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return LengthAwarePaginator<int, ServiceAddon>
     */
    public function paginate(array $criteria, bool $includeInactiveClients = false): LengthAwarePaginator
    {
        $query = $this->apply(ServiceAddon::query(), $criteria);

        if (! $includeInactiveClients) {
            $query->whereHas(
                'customer',
                fn (Builder $customer) => $customer->whereNot('status', CustomerStatus::Closed->value),
            );
        }

        return $query
            ->with([
                ...Customer::displayNameWith('customer'),
                'customer.defaultPaymentMethod',
                // Everything the row and its detail read. A detail panel
                // that lazy-loads is the same bug as a list that does.
                'service.server',
                'service.product',
                'order',
                'addon',
            ])
            ->latest()
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'productTypes' => array_values(array_map(
                static fn (ProductType $type): array => [
                    'value' => $type->value,
                    'label' => (string) __($type->labelKey()),
                ],
                ProductType::cases(),
            )),
            'billingCycles' => array_values(array_map(
                static fn (BillingCycle $cycle): array => [
                    'value' => $cycle->value,
                    'label' => (string) __($cycle->labelKey()),
                ],
                BillingCycle::cases(),
            )),
            'statuses' => array_values(array_map(
                static fn (AddonStatus $status): array => [
                    'value' => $status->value,
                    'label' => (string) __($status->labelKey()),
                ],
                AddonStatus::cases(),
            )),
            'servers' => array_values(Server::query()
                ->orderBy('name')
                ->get()
                ->map(static fn (Server $server): array => [
                    'value' => $server->id,
                    'label' => $server->name,
                ])
                ->all()),
            'products' => array_values(Product::query()
                ->orderBy('name')
                ->get()
                ->map(static fn (Product $product): array => [
                    'value' => $product->id,
                    'label' => $product->name,
                ])
                ->all()),
            'gateways' => array_values(PaymentMethod::query()
                ->select('gateway')
                ->distinct()
                ->orderBy('gateway')
                ->pluck('gateway')
                ->map(static fn (string $gateway): array => [
                    'value' => $gateway,
                    'label' => $gateway,
                ])
                ->all()),
            'customFields' => array_values($this->customFields()
                ->map(static fn (CustomFieldDefinition $field): array => [
                    'value' => $field->key,
                    'label' => $field->label,
                ])
                ->all()),
        ];
    }

    /**
     * The criteria that belong to the service this addon hangs off.
     *
     * @param  Builder<ServiceAddon>  $query
     * @param  array<string, mixed>  $criteria
     */
    private function applyThroughService(Builder $query, array $criteria): void
    {
        $type = $this->text($criteria, 'product_type');
        $server = $this->text($criteria, 'server');
        $product = $this->text($criteria, 'product');
        $domain = $this->text($criteria, 'domain');

        if ($type === null && $server === null && $product === null && $domain === null) {
            return;
        }

        $query->whereHas('service', function (Builder $service) use ($type, $server, $product, $domain): void {
            if ($server !== null) {
                $service->where('server_id', $server);
            }

            if ($product !== null) {
                $service->where('product_id', $product);
            }

            if ($type !== null) {
                $service->whereHas('product', fn (Builder $p) => $p->where('type', $type));
            }

            if ($domain !== null) {
                $like = SearchPattern::like($domain);

                $service->where(fn (Builder $inner) => $inner
                    ->where('domain', 'like', $like)
                    ->orWhere('hostname', 'like', $like));
            }
        });
    }

    /**
     * @param  Builder<ServiceAddon>  $query
     * @param  array<string, mixed>  $criteria
     */
    private function applyClient(Builder $query, array $criteria): void
    {
        $name = $this->text($criteria, 'client');

        if ($name === null) {
            return;
        }

        $like = SearchPattern::like($name);

        $query->whereHas('customer', function (Builder $customer) use ($like): void {
            $customer->where('company_name', 'like', $like)
                ->orWhere('legal_name', 'like', $like)
                ->orWhereHas('contacts', function (Builder $contacts) use ($like): void {
                    $contacts->where('email', 'like', $like);

                    SearchPattern::name($contacts, $like);
                });
        });
    }

    /**
     * @param  Builder<ServiceAddon>  $query
     * @param  array<string, mixed>  $criteria
     */
    private function applyCustomFields(Builder $query, array $criteria): void
    {
        $key = $this->text($criteria, 'custom_field');
        $value = $this->text($criteria, 'custom_value');

        if ($key === null || $value === null) {
            return;
        }

        $definition = $this->customFields()->firstWhere('key', $key);

        if (! $definition instanceof CustomFieldDefinition) {
            return;
        }

        $definitionId = $definition->id;
        $needles = $this->needles($value);

        $query->whereHas('customer', function (Builder $customer) use ($definitionId, $needles): void {
            $customer->whereHas('customFieldValues', function (Builder $values) use ($definitionId, $needles): void {
                $values->where('definition_id', $definitionId)
                    ->where(function (Builder $match) use ($needles): void {
                        foreach ($needles as $needle) {
                            $match->orWhere('value', 'like', $needle);
                        }
                    });
            });
        });
    }

    /**
     * @return list<string>
     */
    private function needles(string $term): array
    {
        $encoded = trim(json_encode($term, JSON_THROW_ON_ERROR), '"');

        return $encoded === $term
            ? [SearchPattern::like($term)]
            : [SearchPattern::like($term), SearchPattern::like($encoded)];
    }

    /**
     * @return Collection<int, CustomFieldDefinition>
     */
    private function customFields(): Collection
    {
        return CustomFieldDefinition::query()
            ->where('entity_type', CustomFieldEntity::Customer->value)
            ->orderBy('position')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function text(array $criteria, string $key): ?string
    {
        $value = $criteria[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
