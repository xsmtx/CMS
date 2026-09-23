<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Crm\CustomerStatus;
use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CustomerRequest extends FormRequest
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
            // A customer is a company or a person; requiring both would make
            // sole traders impossible to enter.
            'company_name' => ['nullable', 'string', 'max:191', 'required_without:legal_name'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'tax_id_type' => ['nullable', 'string', 'max:32'],
            'status' => ['required', Rule::enum(CustomerStatus::class)],
            'currency_code' => ['required', 'string', 'size:3', 'uppercase'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'send_overdue_notices' => ['sometimes', 'boolean'],
            'automatic_suspension' => ['sometimes', 'boolean'],
            'separate_invoices' => ['sometimes', 'boolean'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['string', 'ulid'],
            'custom_fields' => ['sometimes', 'array'],
            ...$this->customFieldRules(),
        ];
    }

    /**
     * Rules derived from the operator's own field definitions, so a required
     * custom field is enforced rather than merely marked.
     *
     * @return array<string, mixed>
     */
    private function customFieldRules(): array
    {
        $rules = [];

        foreach (CustomFieldDefinition::query()->where('entity_type', 'customer')->get() as $definition) {
            $rules['custom_fields.'.$definition->key] = $definition->validationRules();
        }

        return $rules;
    }
}
