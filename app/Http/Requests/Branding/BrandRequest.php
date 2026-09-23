<?php

declare(strict_types=1);

namespace App\Http\Requests\Branding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Branding, as an operator submits it.
 *
 * **Nothing here can be a secret**, and the validation is how that stays
 * true: every field is a name, an address, a colour, a URL or a line of
 * text. There is no field a credential could be put in without somebody
 * adding one on purpose, and adding one would have to get past this list.
 *
 * URLs are required to be HTTPS. A logo loaded over HTTP on a checkout page
 * is a mixed-content warning on the one page a customer is deciding whether
 * to trust, and a browser will block it anyway.
 *
 * Colours are hex only. Accepting arbitrary CSS would mean a value going
 * straight into a `<style>` block on every page — which is a stylesheet
 * injection with a colour picker in front of it.
 */
final class BrandRequest extends FormRequest
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
            'trading_name' => ['nullable', 'string', 'max:191'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:1000'],
            'country' => ['nullable', 'string', 'size:2'],

            'support_email' => ['nullable', 'email', 'max:191'],
            'support_phone' => ['nullable', 'string', 'max:64'],
            'website_url' => ['nullable', 'url:https', 'max:500'],

            'logo_url' => ['nullable', 'url:https', 'max:500'],
            'logo_dark_url' => ['nullable', 'url:https', 'max:500'],
            'favicon_url' => ['nullable', 'url:https', 'max:500'],

            // `#rgb`, `#rrggbb` or `#rrggbbaa`. Nothing that could close a
            // declaration and open another.
            'accent_color' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'],
            'accent_contrast' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'],
            // A font stack, not a URL: loading a webfont is a theme's job.
            'font_family' => ['nullable', 'string', 'max:96', 'regex:/^[a-zA-Z0-9 ,\'"\-]+$/'],

            'portal_name' => ['nullable', 'string', 'max:96'],

            'email_from_name' => ['nullable', 'string', 'max:96'],
            'email_from_address' => ['nullable', 'email', 'max:191'],
            'email_footer' => ['nullable', 'string', 'max:2000'],

            'invoice_footer' => ['nullable', 'string', 'max:2000'],

            'legal_links' => ['nullable', 'array', 'max:8'],
            'legal_links.*.label' => ['required', 'string', 'max:64'],
            'legal_links.*.url' => ['required', 'url:https', 'max:500'],

            'hide_vendor_mark' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Only what was actually submitted.
     *
     * Deliberately **not** called `attributes()`: that name is reserved by
     * `FormRequest` for the human labels used in validation messages, and
     * overriding it makes Laravel call this during validator construction —
     * before `validated()` can possibly work.
     *
     * A partial save must not blank every field the form did not send, and
     * an empty string is somebody deliberately clearing a field — which
     * means "inherit from my parent" and is stored as such.
     *
     * @return array<string, mixed>
     */
    public function brandAttributes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return array_map(
            static fn (mixed $value): mixed => $value === '' ? null : $value,
            $validated,
        );
    }
}
