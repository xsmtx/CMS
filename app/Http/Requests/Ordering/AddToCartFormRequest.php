<?php

declare(strict_types=1);

namespace App\Http\Requests\Ordering;

use App\Domain\Catalog\BillingCycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the configure screen submits.
 *
 * Shape only. Whether the option belongs to the product, whether the
 * required ones were answered and whether the plan is sold on that cycle
 * are decided against the catalog in the use case, because a form can be
 * edited and the catalog cannot.
 */
final class AddToCartFormRequest extends FormRequest
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
        return [
            'product_id' => ['required', 'string', 'ulid'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'domain' => ['nullable', 'string', 'max:253'],

            'options' => ['sometimes', 'array'],
            'options.*.option_id' => ['nullable', 'string', 'ulid'],
            'options.*.quantity' => ['sometimes', 'integer', 'min:0', 'max:1000'],

            'addons' => ['sometimes', 'array'],
            'addons.*' => ['string', 'ulid'],
        ];
    }

    /**
     * @return array<string, array{option_id?: string|null, quantity?: int}>
     */
    public function optionChoices(): array
    {
        /** @var array<string, array{option_id?: string|null, quantity?: int}> $options */
        $options = $this->input('options', []);

        return array_filter(
            $options,
            static fn (array $choice): bool => ($choice['option_id'] ?? null) !== null
                || ($choice['quantity'] ?? 0) > 0,
        );
    }

    /**
     * @return list<string>
     */
    public function addonIds(): array
    {
        /** @var list<string> $addons */
        $addons = $this->input('addons', []);

        return array_values(array_filter($addons, static fn (mixed $id): bool => is_string($id) && $id !== ''));
    }
}
