<?php

declare(strict_types=1);

namespace App\Http\Requests\Ordering;

use App\Domain\Catalog\BillingCycle;
use App\Domain\Promotions\PromotionApplication;
use App\Domain\Promotions\PromotionScope;
use App\Domain\Promotions\PromotionType;
use App\Support\Identity\CurrentActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PromotionRequest extends FormRequest
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
        $promotion = $this->route('promotion');
        $promotionId = $promotion instanceof Model ? $promotion->getKey() : null;
        $organizationId = app(CurrentActor::class)->organizationId();

        $isFixed = $this->string('type')->toString() === PromotionType::Fixed->value;

        return [
            // Customers type these, so no spaces and no ambiguity between
            // upper and lower case.
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('promotions', 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($promotionId),
            ],
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],

            'type' => ['required', Rule::enum(PromotionType::class)],

            // A fixed amount is money and belongs to a currency; a
            // percentage is a number and does not.
            'amount_minor' => [Rule::requiredIf($isFixed), 'nullable', 'integer', 'min:1'],
            'currency_code' => [Rule::requiredIf($isFixed), 'nullable', 'string', 'size:3'],
            'percentage' => [
                Rule::requiredIf(! $isFixed), 'nullable', 'string',
                'regex:/^\d{1,3}(\.\d{1,2})?$/',
            ],

            'scope' => ['required', Rule::enum(PromotionScope::class)],
            'application' => ['required', Rule::enum(PromotionApplication::class)],

            'billing_cycles' => ['sometimes', 'array'],
            'billing_cycles.*' => [Rule::enum(BillingCycle::class)],

            'product_ids' => ['sometimes', 'array'],
            'product_ids.*' => ['string', 'ulid'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],

            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'minimum_subtotal_minor' => ['nullable', 'integer', 'min:0'],

            'new_customers_only' => ['sometimes', 'boolean'],
            'stackable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['percentage.regex' => __('ordering.promotions.percentage_format')];
    }
}
