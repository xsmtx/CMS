<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use App\Domain\Security\EvidenceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Keeping a reference to something that proves the complaint.
 *
 * Bounded at 2000 characters on purpose. Core keeps a reference — an id, a
 * URL, a hash, a short excerpt — and never a mail body, a full log or a disk
 * image: a complaint holds a third party's data, and this table exists to
 * eventually forget it rather than to store it.
 */
final class AbuseEvidenceRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(EvidenceKind::class)],
            'reference' => ['required', 'string', 'max:2000'],
        ];
    }
}
