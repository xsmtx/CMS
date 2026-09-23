<?php

declare(strict_types=1);

namespace App\Http\Requests\Domains;

use App\Domain\Domains\DomainOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asking for an operation on a domain.
 *
 * `register` and `transfer` are not accepted here: they spend money, they
 * have their own permission, and they have their own route.
 */
final class DomainActionRequest extends FormRequest
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
            'operation' => ['required', Rule::in([
                DomainOperation::Renew->value,
                DomainOperation::SetNameservers->value,
                DomainOperation::SetLock->value,
                DomainOperation::SetAutoRenew->value,
                DomainOperation::Sync->value,
            ])],
            'flag' => ['nullable', 'boolean'],
            'years' => ['nullable', 'integer', 'min:1', 'max:10'],
            'nameservers' => ['nullable', 'array', 'max:8'],
            // A hostname, not a domain name: `ns1.example.com` is four
            // labels and perfectly valid.
            'nameservers.*' => ['string', 'max:253', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i'],
        ];
    }
}
