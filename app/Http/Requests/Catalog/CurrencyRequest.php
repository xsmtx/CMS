<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Support\Identity\CurrentActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CurrencyRequest extends FormRequest
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
        $currency = $this->route('currency');
        $currencyId = $currency instanceof Model ? $currency->getKey() : null;
        $organizationId = app(CurrentActor::class)->organizationId();

        return [
            'code' => [
                'required', 'string', 'size:3', 'alpha',
                Rule::unique('currencies', 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($currencyId),
            ],
            'name' => ['required', 'string', 'max:64'],
            'symbol' => ['nullable', 'string', 'max:8'],
            // A decimal string, validated as one: parsing it as a float here
            // would lose the precision the column keeps.
            'rate' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,8})?$/'],
            'is_base' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['rate.regex' => __('catalog.currencies.rate_format')];
    }
}
