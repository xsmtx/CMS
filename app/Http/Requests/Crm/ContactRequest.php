<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Identity\AccountStatus;
use App\Infrastructure\Crm\Models\CustomFieldDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ContactRequest extends FormRequest
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
        $current = $this->route('contact');
        $ignore = $current instanceof Model ? $current->getKey() : $current;

        return [
            'first_name' => ['required', 'string', 'max:96'],
            'last_name' => ['required', 'string', 'max:96'],
            // Unique across the whole table: the address is the sign-in
            // identifier on the client guard, so it cannot repeat even
            // between customers.
            'email' => [
                'required',
                'string',
                'email:filter',
                'max:191',
                Rule::unique('contacts', 'email')->ignore($ignore),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'portal_access' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::enum(AccountStatus::class)],
            'notify_invoices' => ['sometimes', 'boolean'],
            'notify_support' => ['sometimes', 'boolean'],
            'notify_product' => ['sometimes', 'boolean'],
            'notify_marketing' => ['sometimes', 'boolean'],
            'custom_fields' => ['sometimes', 'array'],
            ...$this->customFieldRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customFieldRules(): array
    {
        $rules = [];

        foreach (CustomFieldDefinition::query()->where('entity_type', 'contact')->get() as $definition) {
            $rules['custom_fields.'.$definition->key] = $definition->validationRules();
        }

        return $rules;
    }
}
