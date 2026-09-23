<?php

declare(strict_types=1);

namespace App\Application\Branding;

use App\Domain\Branding\Brand;
use App\Infrastructure\Branding\Models\BrandSetting;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;

/**
 * The brand for one organization, with its holes filled in from above.
 *
 * **Inheritance walks the organization path**, which is the structure that
 * already exists: a reseller that has set a logo and nothing else shows
 * the provider's colours, footer and legal links rather than a half-branded
 * page. The alternative — requiring thirty fields before anything looks
 * right — is how a white-label feature goes unused.
 *
 * A null and an empty string mean the same thing here: *ask my parent*.
 * Treating them differently would make a field somebody deliberately
 * cleared look set, and leave them unable to undo a change.
 *
 * The walk is **nearest-first and stops at the first non-null per field**,
 * not per row. A reseller under a reseller inherits the nearer one's
 * colours and the provider's legal links if only the provider set them.
 *
 * Read past the organization boundary on purpose and narrowed to exactly
 * the ancestor ids: a brand is inherited from above, and an ancestor is
 * never inside its descendant's subtree. This is the same escape
 * `ResolveSeller` makes, for the same reason.
 *
 * Memoised per request. A storefront page asks for the brand in the layout,
 * the header, the footer and the invoice partial; four identical walks up a
 * path is three too many.
 */
final class ResolveBrand
{
    /** @var array<string, Brand> */
    private array $resolved = [];

    public function __construct(private readonly OrganizationContext $organizations) {}

    public function forOrganization(string $organizationId): Brand
    {
        return $this->resolved[$organizationId] ??= $this->walk($organizationId);
    }

    /**
     * Forget what has been resolved.
     *
     * Called when a brand is saved, so the screen that saved it renders the
     * new one rather than the memoised old one — the commonest way a cache
     * like this is wrong, and it is wrong in the one moment somebody is
     * watching.
     */
    public function forget(): void
    {
        $this->resolved = [];
    }

    private function walk(string $organizationId): Brand
    {
        /** @var array<string, mixed> $merged */
        $merged = [];

        $organizations = $this->lineage($organizationId);

        foreach ($organizations as $organization) {
            $setting = $this->settingFor($organization->id);

            if ($setting instanceof BrandSetting) {
                $merged = $this->fill($merged, $setting);
            }

            // The switch is not inherited: whether a reseller may remove
            // the vendor mark is that reseller's entitlement, not their
            // parent's choice.
            if ($organization->id === $organizationId && $setting instanceof BrandSetting) {
                $merged['hide_vendor_mark'] = $setting->hide_vendor_mark;
            }
        }

        $fallbackName = ($organizations[0] ?? null) instanceof Organization
            ? $organizations[0]->name
            : (string) config('app.name');

        return BrandSetting::toBrand($merged, $fallbackName);
    }

    /**
     * @param  array<string, mixed>  $merged
     * @return array<string, mixed>
     */
    private function fill(array $merged, BrandSetting $setting): array
    {
        foreach (BrandSetting::inheritable() as $field) {
            if (array_key_exists($field, $merged)) {
                // A nearer organization already answered this one.
                continue;
            }

            $value = $setting->getAttribute($field);

            if (in_array($value, [null, '', []], true)) {
                continue;
            }

            $merged[$field] = $value;
        }

        return $merged;
    }

    /**
     * The organization and its ancestors, nearest first.
     *
     * @return list<Organization>
     */
    private function lineage(string $organizationId): array
    {
        return $this->organizations->withoutBoundary(
            static function () use ($organizationId): array {
                $organization = Organization::query()
                    ->withoutGlobalScope('organization')
                    ->find($organizationId);

                if (! $organization instanceof Organization) {
                    return [];
                }

                $ancestorIds = array_values(array_filter(explode('/', $organization->path)));

                $ancestors = Organization::query()
                    ->withoutGlobalScope('organization')
                    ->whereIn('id', $ancestorIds)
                    // Longest path last, then reversed: nearest first.
                    ->orderByRaw('LENGTH(path) ASC')
                    ->get()
                    ->reverse()
                    ->values()
                    ->all();

                return array_values($ancestors);
            },
        );
    }

    private function settingFor(string $organizationId): ?BrandSetting
    {
        return $this->organizations->withoutBoundary(
            static fn (): ?BrandSetting => BrandSetting::query()
                ->where('organization_id', $organizationId)
                ->first(),
        );
    }
}
