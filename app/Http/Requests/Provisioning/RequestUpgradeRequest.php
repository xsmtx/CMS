<?php

declare(strict_types=1);

namespace App\Http\Requests\Provisioning;

use App\Domain\Catalog\BillingCycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asking to move a service to another plan.
 *
 * `exists` names the **table**, which is `products` — the mistake this product
 * has found three times on `departments`, so the rule is written out with the
 * table beside it.
 *
 * The cycle is validated against the enum rather than against the plan's own
 * price rows, because `PriceUpgrade` refuses a cycle the plan is not sold in
 * and does it with a sentence naming the currency. Two places refusing the
 * same thing would be two sentences, and the worse one would win.
 */
final class RequestUpgradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'product' => ['required', 'string', 'exists:products,id'],
            'cycle' => ['required', 'string', Rule::enum(BillingCycle::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'product' => (string) __('provisioning.upgrades.plan'),
            'cycle' => (string) __('provisioning.upgrades.cycle'),
        ];
    }
}
