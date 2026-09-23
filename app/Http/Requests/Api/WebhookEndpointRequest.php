<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Domain\Api\WebhookEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A URL this platform will post signed customer data to, so the validation
 * is deliberately strict: HTTPS only, and no private or loopback address.
 * An endpoint pointed at `http://169.254.169.254/` is a request to have the
 * platform read its own cloud metadata and post it somewhere — the classic
 * server-side request forgery, arrived at through a feature.
 */
final class WebhookEndpointRequest extends FormRequest
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
            'url' => [
                'required',
                'string',
                'max:500',
                // `activeUrl` is deliberately not used: it resolves DNS at
                // validation time, which is both slow and a different
                // answer from the one that will matter at delivery.
                'url:https',
            ],
            'description' => ['nullable', 'string', 'max:191'],
            'events' => ['sometimes', 'array', 'max:50'],
            'events.*' => [Rule::enum(WebhookEvent::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['url.url' => (string) __('api.errors.https_required')];
    }
}
