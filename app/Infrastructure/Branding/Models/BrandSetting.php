<?php

declare(strict_types=1);

namespace App\Infrastructure\Branding\Models;

use App\Domain\Branding\Brand;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\BrandSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One organization's branding, as stored.
 *
 * Every column is nullable, and that is the inheritance mechanism: a null
 * here means "ask my parent", which is resolved once in `ResolveBrand`
 * rather than being asked again at every read.
 *
 * @property string $organization_id
 * @property string|null $trading_name
 * @property string|null $legal_name
 * @property list<array{label: string, url: string}>|null $legal_links
 * @property bool $hide_vendor_mark
 * @property CarbonImmutable|null $updated_at
 */
final class BrandSetting extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<BrandSettingFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'brand_settings';

    protected $fillable = [
        'organization_id',
        'trading_name',
        'legal_name',
        'tax_id',
        'address',
        'country',
        'support_email',
        'support_phone',
        'website_url',
        'logo_url',
        'logo_dark_url',
        'favicon_url',
        'accent_color',
        'accent_contrast',
        'font_family',
        'portal_name',
        'email_from_name',
        'email_from_address',
        'email_footer',
        'invoice_footer',
        'legal_links',
        'hide_vendor_mark',
    ];

    /** @var array<string, bool> */
    protected $attributes = ['hide_vendor_mark' => false];

    /**
     * The fields that participate in inheritance, in the order the value
     * object takes them.
     *
     * Listed once so that adding a brand field is one edit rather than
     * three: the column, this list, and the value object's constructor
     * argument of the same name.
     *
     * @return list<string>
     */
    public static function inheritable(): array
    {
        return [
            'trading_name',
            'legal_name',
            'tax_id',
            'address',
            'country',
            'support_email',
            'support_phone',
            'website_url',
            'logo_url',
            'logo_dark_url',
            'favicon_url',
            'accent_color',
            'accent_contrast',
            'font_family',
            'portal_name',
            'email_from_name',
            'email_from_address',
            'email_footer',
            'invoice_footer',
            'legal_links',
        ];
    }

    public function auditLabel(): string
    {
        return $this->trading_name ?? $this->organization_id;
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    public static function toBrand(array $resolved, string $fallbackName): Brand
    {
        /** @var list<array{label: string, url: string}> $links */
        $links = is_array($resolved['legal_links'] ?? null) ? $resolved['legal_links'] : [];

        return new Brand(
            name: self::text($resolved, 'trading_name') ?? $fallbackName,
            legalName: self::text($resolved, 'legal_name'),
            taxId: self::text($resolved, 'tax_id'),
            address: self::text($resolved, 'address'),
            country: self::text($resolved, 'country'),
            supportEmail: self::text($resolved, 'support_email'),
            supportPhone: self::text($resolved, 'support_phone'),
            websiteUrl: self::text($resolved, 'website_url'),
            logoUrl: self::text($resolved, 'logo_url'),
            logoDarkUrl: self::text($resolved, 'logo_dark_url'),
            faviconUrl: self::text($resolved, 'favicon_url'),
            accentColor: self::text($resolved, 'accent_color'),
            accentContrast: self::text($resolved, 'accent_contrast'),
            fontFamily: self::text($resolved, 'font_family'),
            portalName: self::text($resolved, 'portal_name'),
            emailFromName: self::text($resolved, 'email_from_name'),
            emailFromAddress: self::text($resolved, 'email_from_address'),
            emailFooter: self::text($resolved, 'email_footer'),
            invoiceFooter: self::text($resolved, 'invoice_footer'),
            legalLinks: $links,
            hideVendorMark: (bool) ($resolved['hide_vendor_mark'] ?? false),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legal_links' => 'array',
            'hide_vendor_mark' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private static function text(array $resolved, string $key): ?string
    {
        $value = $resolved[$key] ?? null;

        // An empty string is somebody clearing a field, which means "ask my
        // parent" exactly as a null does. Treating them differently would
        // make a cleared field look set.
        return is_string($value) && $value !== '' ? $value : null;
    }
}
