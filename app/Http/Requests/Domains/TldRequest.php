<?php

declare(strict_types=1);

namespace App\Http\Requests\Domains;

use App\Domain\Catalog\CatalogStatus;
use App\Domain\Domains\DomainAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An extension and its whole price matrix, saved together.
 *
 * The prices arrive as a list of cells. A cell that is absent means the
 * term is not sold in that currency; a cell with zero means it is free.
 * The two are different statements and the form keeps them apart
 * ([ADR 0019](../../../../docs/adr/0019-price-matrix.md)).
 */
final class TldRequest extends FormRequest
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
            // Letters, digits, hyphens and dots: `com`, `co.uk`, `xn--p1ai`.
            'extension' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i'],
            'registrar' => ['nullable', 'string', 'max:48'],
            'min_years' => ['required', 'integer', 'min:1', 'max:10'],
            'max_years' => ['required', 'integer', 'min:1', 'max:10', 'gte:min_years'],
            'allows_transfer' => ['boolean'],
            'allows_whois_privacy' => ['boolean'],
            'requires_epp_code' => ['boolean'],
            'supports_idn' => ['boolean'],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:365'],
            'redemption_days' => ['required', 'integer', 'min:0', 'max:365'],

            'prices' => ['array'],
            'prices.*.action' => ['required', Rule::enum(DomainAction::class)],
            'prices.*.years' => ['required', 'integer', 'min:1', 'max:10'],
            'prices.*.currency_code' => ['required', 'string', 'size:3'],
            'prices.*.amount_minor' => ['required', 'integer', 'min:0'],
            'prices.*.cost_minor' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
