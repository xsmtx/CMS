<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Application\Shared\SearchPattern;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\ProductType;
use App\Domain\Crm\CustomerStatus;
use App\Domain\Crm\CustomFieldEntity;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Billing\Models\PaymentMethod;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use App\Infrastructure\Provisioning\Models\Server;
use App\Infrastructure\Provisioning\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Finding a service, the way an operator actually looks for one.
 *
 * The same discipline as `SearchCustomers`, for the same reason: **every
 * criterion offered is a criterion that runs**. A filter that silently
 * matches nothing because the column does not exist is worse than no
 * filter — an operator concludes the service is not there and goes looking
 * somewhere else.
 *
 * So each one is answered from the row it genuinely lives in. The product
 * type comes from the product, the server from the server, the payment
 * method from the card on the customer's file, the client name from the
 * company or the person behind it. The two custom-field filters are the
 * **customer's** custom fields, because a service has none — which is the
 * honest answer to "find every service belonging to a customer in this
 * Vergi Dairesi".
 *
 * **Inactive clients are hidden by default**, which is the WHMCS default
 * and the right one: a closed account's services are a record the accounts
 * department keeps, and after a few years they are most of the table. The
 * toggle is about the *client*, not the service — a terminated service of
 * a trading customer is still their business, and the status filter is
 * where that question belongs.
 */
final readonly class SearchServices
{
    /**
     * Exact criteria: name → the relation and column that answers it.
     */
    private const array EXACT = [
        'product_type' => 'product:type',
        'server' => 'service:server_id',
        'product' => 'service:product_id',
        'gateway' => 'card:gateway',
        'billing_cycle' => 'service:billing_cycle',
        'status' => 'service:status',
    ];

    /**
     * @param  Builder<Service>  $query
     * @param  array<string, mixed>  $criteria
     * @return Builder<Service>
     */
    public function apply(Builder $query, array $criteria): Builder
    {
        foreach (self::EXACT as $name => $target) {
            $value = $this->text($criteria, $name);

            if ($value !== null) {
                $this->matchExact($query, $target, $value);
            }
        }

        $domain = $this->text($criteria, 'domain');

        if ($domain !== null) {
            $like = SearchPattern::like($domain);

            // The hostname as well: a service provisioned on a temporary
            // name is one an operator still has to find.
            $query->where(fn (Builder $inner) => $inner
                ->where('domain', 'like', $like)
                ->orWhere('hostname', 'like', $like));
        }

        $this->applyClient($query, $criteria);
        $this->applyCustomFields($query, $criteria);

        return $query;
    }

    /**
     * The list itself.
     *
     * Built here rather than in the controller because it is a query, and
     * a controller that assembles one is a controller that will eventually
     * own a rule — which the architecture tests enforce, and rightly.
     *
     * @param  array<string, mixed>  $criteria
     * @return LengthAwarePaginator<int, Service>
     */
    public function paginate(array $criteria, bool $includeInactiveClients = false): LengthAwarePaginator
    {
        $query = $this->apply(Service::query(), $criteria);

        if (! $includeInactiveClients) {
            $query->whereHas(
                'customer',
                fn (Builder $customer) => $customer->whereNot('status', CustomerStatus::Closed->value),
            );
        }

        return $query
            ->with([
                ...Customer::displayNameWith('customer'),
                // Everything the expanded row reads. A detail panel that
                // lazy-loads is the same bug as a list that does; it just
                // waits until somebody opens it.
                'customer.defaultPaymentMethod',
                'server',
                'product',
                'order',
            ])
            ->latest()
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * What the screen should offer, built from what this installation has.
     *
     * The servers, the products and the gateways are read from the rows
     * rather than listed: a select of things nobody sells is a select
     * nobody scrolls.
     *
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
                static fn (ServiceStatus $status): array => [
                    'value' => $status->value,
                    'label' => (string) __($status->labelKey()),
                ],
                ServiceStatus::cases(),
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
     * The types that actually have something running.
     *
     * This is the row of links across the top of the screen — the
     * drill-down a WHMCS operator reaches for. Built from the rows rather
     * than from the enum, so it never offers a type nobody sells.
     *
     * @return list<array<string, mixed>>
     */
    public function typeCounts(bool $includeInactiveClients = false): array
    {
        $query = Service::query()
            ->join('products', 'products.id', '=', 'services.product_id')
            ->select('products.type')
            ->selectRaw('count(*) as total')
            ->groupBy('products.type');

        if (! $includeInactiveClients) {
            $query->whereHas(
                'customer',
                fn (Builder $customer) => $customer->whereNot('status', CustomerStatus::Closed->value),
            );
        }

        return array_values($query->get()
            ->map(static function (Service $row): array {
                /** @var string $type */
                $type = $row->getAttribute('type');
                $case = ProductType::tryFrom($type);

                return [
                    'value' => $type,
                    'label' => $case instanceof ProductType ? (string) __($case->labelKey()) : $type,
                    'total' => (int) $row->getAttribute('total'),
                ];
            })
            ->all());
    }

    /**
     * @param  Builder<Service>  $query
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
     * A customer custom field, matched the way the customers screen
     * matches it — including the JSON-escaped form, because a value cast
     * to JSON stores "Kadıköy" as an escape sequence and matching only the
     * plain string means every Turkish search returns nothing.
     *
     * @param  Builder<Service>  $query
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
            // An undefined key is somebody's stale bookmark, not a search.
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
     * @param  Builder<Service>  $query
     */
    private function matchExact(Builder $query, string $target, string $value): void
    {
        [$relation, $column] = explode(':', $target, 2);

        match ($relation) {
            'service' => $query->where($column, $value),
            'product' => $query->whereHas('product', fn (Builder $q) => $q->where($column, $value)),
            'card' => $query->whereHas(
                'customer',
                fn (Builder $q) => $q->whereHas('paymentMethods', fn (Builder $cards) => $cards->where($column, $value)),
            ),
            default => null,
        };
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
