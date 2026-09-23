<?php

declare(strict_types=1);

namespace App\Domain\Branding;

/**
 * What one organization calls itself and what it looks like.
 *
 * A value object rather than the Eloquent row, because a brand is **read
 * everywhere and written almost nowhere**: every storefront page, every
 * portal page, every email, every invoice. Handing a model to all of those
 * would mean a template could lazily load a relation, or save.
 *
 * It is also already resolved by the time it exists here. The inheritance
 * walk up the organization path happens once, in `ResolveBrand`, and what
 * comes out is a brand with no holes in it — so nothing downstream has to
 * ask "and if this one is null".
 *
 * Nothing on it is a secret, and that is a property of the type rather than
 * a convention: there is no field a credential could be put in without
 * somebody adding one on purpose.
 */
final readonly class Brand
{
    /**
     * @param  list<array{label: string, url: string}>  $legalLinks
     */
    public function __construct(
        public string $name,
        public ?string $legalName = null,
        public ?string $taxId = null,
        public ?string $address = null,
        public ?string $country = null,
        public ?string $supportEmail = null,
        public ?string $supportPhone = null,
        public ?string $websiteUrl = null,
        public ?string $logoUrl = null,
        public ?string $logoDarkUrl = null,
        public ?string $faviconUrl = null,
        public ?string $accentColor = null,
        public ?string $accentContrast = null,
        public ?string $fontFamily = null,
        public ?string $portalName = null,
        public ?string $emailFromName = null,
        public ?string $emailFromAddress = null,
        public ?string $emailFooter = null,
        public ?string $invoiceFooter = null,
        public array $legalLinks = [],
        public bool $hideVendorMark = false,
    ) {}

    /**
     * The name a document is issued under.
     *
     * An invoice needs the legal name; a page header needs the trading one.
     * Falling back rather than requiring both means an installation that
     * only ever set one is still correct on both.
     */
    public function documentName(): string
    {
        return $this->legalName ?? $this->name;
    }

    public function portalTitle(): string
    {
        return $this->portalName ?? $this->name;
    }

    /**
     * The colours, as the custom properties the design system already uses.
     *
     * A brand overrides three tokens and everything built on them follows —
     * buttons, focus rings, badges, links. Regenerating a stylesheet per
     * brand would be the alternative, and it would mean a build step
     * between an operator picking a colour and seeing it.
     *
     * @return array<string, string>
     */
    public function cssVariables(): array
    {
        $accent = $this->accentColor;

        return array_filter([
            '--color-accent' => $accent,
            '--color-accent-content' => $this->accentContrast,
            // **Derived from the brand's own colour, not left at the
            // platform's.** A brand that set a pink accent and then hovered
            // to the platform blue was the shape of bug nobody reports and
            // everybody notices: the hover is a different token, and
            // overriding one without the other left half a rebrand.
            //
            // Mixed rather than asked for: an operator picking a colour has
            // not agreed to pick three, and "slightly darker on hover" is
            // arithmetic rather than a decision.
            '--color-accent-hover' => $accent === null || $accent === ''
                ? null
                : 'color-mix(in oklab, '.$accent.' 86%, var(--color-content))',
            '--color-accent-subtle' => $accent === null || $accent === ''
                ? null
                : 'color-mix(in oklab, '.$accent.' 16%, var(--color-surface-raised))',
            '--font-sans' => $this->fontFamily,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'legalName' => $this->legalName,
            'portalName' => $this->portalTitle(),
            'supportEmail' => $this->supportEmail,
            'supportPhone' => $this->supportPhone,
            'websiteUrl' => $this->websiteUrl,
            'logoUrl' => $this->logoUrl,
            'logoDarkUrl' => $this->logoDarkUrl,
            'faviconUrl' => $this->faviconUrl,
            'legalLinks' => $this->legalLinks,
            'hideVendorMark' => $this->hideVendorMark,
            'css' => $this->cssVariables(),
        ];
    }
}
