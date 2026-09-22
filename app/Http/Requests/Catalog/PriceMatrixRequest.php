<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Application\Catalog\PriceMatrixEntry;
use App\Domain\Catalog\BillingCycle;
use App\Domain\Shared\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PriceMatrixRequest extends FormRequest
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
            'prices' => ['present', 'array', 'max:200'],
            'prices.*.billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'prices.*.currency_code' => ['required', 'string', 'size:3'],

            // Minor units, as integers. The form sends what the operator
            // typed multiplied out by the currency's exponent, so no decimal
            // string is parsed on this side and nothing is rounded here.
            // Options may be negative; a product price may not.
            'prices.*.recurring_minor' => ['required', 'integer', 'min:-99999999999', 'max:99999999999'],
            'prices.*.setup_minor' => ['required', 'integer', 'min:-99999999999', 'max:99999999999'],
        ];
    }

    /**
     * @return list<PriceMatrixEntry>
     */
    public function entries(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->input('prices', []);

        return array_values(array_map(
            static fn (array $row): PriceMatrixEntry => new PriceMatrixEntry(
                BillingCycle::from((string) $row['billing_cycle']),
                Money::ofMinor((int) $row['recurring_minor'], (string) $row['currency_code']),
                Money::ofMinor((int) $row['setup_minor'], (string) $row['currency_code']),
            ),
            $rows,
        ));
    }
}
