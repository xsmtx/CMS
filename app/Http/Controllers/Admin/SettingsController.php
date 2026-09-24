<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Branding\InspectTheme;
use App\Application\Branding\ResolveBrand;
use App\Domain\Branding\Surface;
use App\Domain\Branding\ThemeManifest;
use App\Domain\Licensing\Contracts\Entitlements;
use App\Domain\Licensing\Feature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branding\BrandRequest;
use App\Http\Requests\Branding\ThemeRequest;
use App\Infrastructure\Branding\Models\BrandSetting;
use App\Infrastructure\Branding\Models\ThemeSetting;
use App\Infrastructure\Branding\ThemeRegistry;
use App\Support\Audit\Facades\Audit;
use App\Support\Branding\ActiveTheme;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What this installation calls itself, and what it looks like.
 *
 * The navigation has pointed at `/admin/settings` since Phase 0 and nothing
 * has ever answered it. A menu that advertises a screen which does not
 * exist is a menu that lies, and this one lied for eleven phases.
 *
 * Everything on it is audited. "Who changed the company's legal name on its
 * invoices" is a question that gets asked exactly once, in circumstances
 * nobody enjoys, and an answer has to exist by then.
 *
 * The brand is saved for the **staff member's own organization**, which is
 * what makes this work for a reseller without a second screen: a reseller
 * admin brands their own storefront, and cannot reach the provider's.
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly OrganizationContext $organizations,
        private readonly ThemeRegistry $themes,
        private readonly InspectTheme $inspector,
        private readonly ResolveBrand $brands,
        private readonly ActiveTheme $active,
        private readonly Entitlements $entitlements,
    ) {}

    public function index(): Response
    {
        $this->authorizeFor('settings.view');

        $organizationId = $this->organizationId();
        $setting = $this->settingFor($organizationId);

        return Inertia::render('Admin/Settings/Index', [
            /*
             * Not `brand`. `HandleInertiaRequests` shares a prop of that name
             * and the shell reads it — a page prop of the same name shadows it
             * for this screen only, which left the footer printing a copyright
             * line with no company in it and `useBranding()` handing the
             * sidebar an object full of nulls.
             */
            'brandFields' => [
                'tradingName' => $setting?->trading_name,
                'legalName' => $setting?->legal_name,
                'taxId' => $setting?->tax_id,
                'address' => $setting?->address,
                'country' => $setting?->country,
                'supportEmail' => $setting?->support_email,
                'supportPhone' => $setting?->support_phone,
                'websiteUrl' => $setting?->website_url,
                'logoUrl' => $setting?->logo_url,
                'logoDarkUrl' => $setting?->logo_dark_url,
                'faviconUrl' => $setting?->favicon_url,
                'accentColor' => $setting?->accent_color,
                'accentContrast' => $setting?->accent_contrast,
                'fontFamily' => $setting?->font_family,
                'portalName' => $setting?->portal_name,
                'emailFromName' => $setting?->email_from_name,
                'emailFromAddress' => $setting?->email_from_address,
                'emailFooter' => $setting?->email_footer,
                'invoiceFooter' => $setting?->invoice_footer,
                'legalLinks' => $setting instanceof BrandSetting ? ($setting->legal_links ?? []) : [],
                'hideVendorMark' => $setting instanceof BrandSetting && $setting->hide_vendor_mark,
            ],
            // What is actually showing, holes filled from the parent. An
            // operator who has set nothing should be able to see what their
            // customers see rather than a form full of blanks.
            'effective' => $this->brands->forOrganization($organizationId)->toArray(),
            'surfaces' => $this->surfaces($organizationId),
            'vendorMark' => (string) config('platform.branding.vendor_mark'),
            'can' => [
                'manage' => $this->actor->can('settings.manage'),
                'removeVendorMark' => $this->entitlements->allows(Feature::RemoveVendorMark->value),
            ],
        ]);
    }

    public function updateBrand(BrandRequest $request): RedirectResponse
    {
        $this->authorizeFor('settings.manage');

        $organizationId = $this->organizationId();
        $attributes = $request->brandAttributes();

        // The switch is stored either way, so that turning a licence back
        // on restores the choice the operator already made — but it is only
        // allowed to change while the entitlement holds.
        if (! $this->entitlements->allows(Feature::RemoveVendorMark->value)) {
            unset($attributes['hide_vendor_mark']);
        }

        $setting = $this->organizations->withoutBoundary(
            static fn (): BrandSetting => BrandSetting::query()->updateOrCreate(
                ['organization_id' => $organizationId],
                $attributes,
            ),
        );

        Audit::action('branding.updated')
            ->by($this->actor->model())
            ->on($setting)
            ->forOrganization($organizationId)
            ->withMetadata(['fields' => implode(', ', array_keys($attributes))])
            ->write();

        // Memoised for the request that is about to render the new page.
        $this->brands->forget();

        return back()->with('status', __('branding.saved'));
    }

    public function updateTheme(ThemeRequest $request): RedirectResponse
    {
        $this->authorizeFor('settings.manage');

        $organizationId = $this->organizationId();
        $surface = Surface::from($request->string('surface')->toString());
        $slug = $request->string('theme')->toString();

        $problems = $this->inspector->problems($surface, $slug);

        if ($problems !== []) {
            // Named, all of them at once. An operator fixing a theme wants
            // to see the four bad files, not to find them one save at a
            // time.
            return back()->withErrors(['theme' => $problems[0]]);
        }

        $setting = $this->organizations->withoutBoundary(
            static fn (): ThemeSetting => ThemeSetting::query()->updateOrCreate(
                ['organization_id' => $organizationId, 'surface' => $surface->value],
                ['theme' => $slug],
            ),
        );

        Audit::action('branding.theme_changed')
            ->by($this->actor->model())
            ->on($setting)
            ->forOrganization($organizationId)
            ->withMetadata(['surface' => $surface->value, 'theme' => $slug])
            ->write();

        $this->active->forget();

        return back()->with('status', __('branding.theme_saved'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function surfaces(string $organizationId): array
    {
        $chosen = $this->organizations->withoutBoundary(
            static fn (): array => ThemeSetting::query()
                ->where('organization_id', $organizationId)
                ->get()
                ->keyBy(static fn (ThemeSetting $setting): string => $setting->surface->value)
                ->all(),
        );

        return array_map(
            function (Surface $surface) use ($chosen): array {
                $themes = $this->themes->all($surface);

                return [
                    'value' => $surface->value,
                    'label' => (string) __($surface->labelKey()),
                    'current' => isset($chosen[$surface->value])
                        ? $chosen[$surface->value]->theme
                        : ThemeRegistry::FALLBACK,
                    'themes' => array_values(array_map(
                        fn (ThemeManifest $manifest): array => [
                            'value' => $manifest->slug,
                            'label' => $manifest->name.' '.$manifest->version,
                            'parent' => $manifest->parent,
                            'author' => $manifest->author,
                            // Shown rather than hidden: an operator whose
                            // theme was refused needs to know why.
                            'problems' => $this->inspector->problems($surface, $manifest->slug),
                        ],
                        $themes,
                    )),
                ];
            },
            Surface::cases(),
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

    /**
     * The staff member's own organization — never a chosen one.
     *
     * A settings screen that took an organization from the request would be
     * a way for a reseller admin to rebrand the provider.
     */
    private function organizationId(): string
    {
        $organizationId = $this->actor->organizationId();

        if ($organizationId === null) {
            throw new ForbiddenException(__('branding.errors.no_organization'));
        }

        return $organizationId;
    }

    private function authorizeFor(string $permission): void
    {
        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('branding.errors.not_permitted'));
        }
    }
}
