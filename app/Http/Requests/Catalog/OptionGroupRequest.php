<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Application\Catalog\OptionAttributes;
use App\Application\Catalog\OptionGroupAttributes;
use App\Application\Catalog\PriceMatrixEntry;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Catalog\OptionType;
use App\Domain\Shared\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OptionGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $group = $this->route('group');
        $product = $this->route('product');

        return [
            'name' => ['required', 'string', 'max:191'],
            // The key is what an order line and a provisioning call refer
            // to, so it is stable and machine-shaped.
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('option_groups', 'key')
                    ->where('product_id', $product instanceof Model ? $product->getKey() : null)
                    ->ignore($group instanceof Model ? $group->getKey() : null),
            ],
            'type' => ['required', Rule::enum(OptionType::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_required' => ['sometimes', 'boolean'],
            'min_quantity' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'max_quantity' => ['nullable', 'integer', 'min:0', 'max:65535', 'gte:min_quantity'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],

            'options' => ['present', 'array', 'max:100'],
            'options.*.id' => ['nullable', 'string', 'ulid'],
            'options.*.label' => ['required', 'string', 'max:191'],
            'options.*.value' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'options.*.is_default' => ['sometimes', 'boolean'],
            'options.*.position' => ['sometimes', 'integer', 'min:0', 'max:65535'],

            // Signed: "no control panel" is priced as a reduction.
            'options.*.prices' => ['sometimes', 'array', 'max:200'],
            'options.*.prices.*.billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'options.*.prices.*.currency_code' => ['required', 'string', 'size:3'],
            'options.*.prices.*.recurring_minor' => ['required', 'integer', 'min:-99999999999', 'max:99999999999'],
            'options.*.prices.*.setup_minor' => ['required', 'integer', 'min:-99999999999', 'max:99999999999'],
        ];
    }

    public function toAttributes(): OptionGroupAttributes
    {
        /** @var list<array<string, mixed>> $options */
        $options = $this->input('options', []);

        return new OptionGroupAttributes(
            name: $this->string('name')->toString(),
            key: $this->string('key')->toString(),
            type: OptionType::from($this->string('type')->toString()),
            description: $this->input('description'),
            isRequired: $this->boolean('is_required'),
            minQuantity: (int) $this->input('min_quantity', 0),
            maxQuantity: $this->input('max_quantity') === null ? null : (int) $this->input('max_quantity'),
            position: (int) $this->input('position', 0),
            options: array_values(array_map($this->option(...), $options)),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function option(array $row): OptionAttributes
    {
        /** @var list<array<string, mixed>> $prices */
        $prices = $row['prices'] ?? [];

        return new OptionAttributes(
            label: (string) $row['label'],
            value: (string) $row['value'],
            isDefault: (bool) ($row['is_default'] ?? false),
            position: (int) ($row['position'] ?? 0),
            id: isset($row['id']) && is_string($row['id']) ? $row['id'] : null,
            prices: array_values(array_map(
                static fn (array $price): PriceMatrixEntry => new PriceMatrixEntry(
                    BillingCycle::from((string) $price['billing_cycle']),
                    Money::ofMinor((int) $price['recurring_minor'], (string) $price['currency_code']),
                    Money::ofMinor((int) $price['setup_minor'], (string) $price['currency_code']),
                ),
                $prices,
            )),
        );
    }
}
