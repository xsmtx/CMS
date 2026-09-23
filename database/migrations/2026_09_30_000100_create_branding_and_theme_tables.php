<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an installation calls itself, and what it looks like.
 *
 * **A brand belongs to an organization, not to the installation.** A
 * reseller sells under its own name on the same database as the provider,
 * so this cannot be configuration: it changes without a deploy, it differs
 * per row, and it appears on documents a customer keeps for seven years.
 *
 * Every column is **nullable on purpose**. A brand inherits from its
 * parent organization, so a reseller that has set a logo and nothing else
 * gets the provider's colours, footer and legal links. Requiring thirty
 * fields before anything looks right is how a white-label feature goes
 * unused.
 *
 * **Nothing here is a secret.** A name, an address, colours, URLs, and the
 * from-name and from-address a message goes out under. The credentials
 * that actually send it stay in configuration with the rest of this
 * platform's secrets, so that a screen an operator can reach — and a
 * database an operator can back up to a laptop — never holds one.
 *
 * `theme_settings` is separate because a theme choice is not branding: an
 * operator changes a logo often and a theme twice. Keeping them apart also
 * means a theme's own settings can be replaced wholesale when a theme is
 * changed, without touching the company's legal name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // One brand per organization. The unique index is the whole
            // reason to look this up by organization rather than by id.
            $table->foreignUlid('organization_id')->unique()
                ->constrained('organizations')->cascadeOnDelete();

            // What customers call it, and what a court calls it. The two
            // differ often enough that an invoice needs both.
            $table->string('trading_name', 191)->nullable();
            $table->string('legal_name', 191)->nullable();
            $table->string('tax_id', 64)->nullable();

            $table->text('address')->nullable();
            $table->string('country', 2)->nullable();

            $table->string('support_email', 191)->nullable();
            $table->string('support_phone', 64)->nullable();
            $table->string('website_url', 500)->nullable();

            $table->string('logo_url', 500)->nullable();
            $table->string('logo_dark_url', 500)->nullable();
            $table->string('favicon_url', 500)->nullable();

            // Reaches the browser as CSS custom properties, overriding the
            // design tokens everything else is already built on.
            $table->string('accent_color', 9)->nullable();
            $table->string('accent_contrast', 9)->nullable();
            $table->string('font_family', 96)->nullable();

            $table->string('portal_name', 96)->nullable();

            // The identity a message goes out under. Not the transport.
            $table->string('email_from_name', 96)->nullable();
            $table->string('email_from_address', 191)->nullable();
            $table->text('email_footer')->nullable();

            $table->text('invoice_footer')->nullable();

            // [{label, url}]. A list rather than columns, because every
            // jurisdiction wants a different set of them.
            $table->json('legal_links')->nullable();

            // Only meaningful when the entitlement allows it. Stored either
            // way, so that turning a licence back on restores the choice
            // the operator already made.
            $table->boolean('hide_vendor_mark')->default(false);

            $table->timestamps();
        });

        Schema::create('theme_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // storefront | client | admin
            $table->string('surface', 24);
            $table->string('theme', 96);

            // Whatever the theme's own manifest declares. Merged over the
            // parent theme's defaults at read time rather than copied in,
            // so a theme upgrade that adds a setting does not need a
            // migration of everybody's rows.
            $table->json('settings')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'surface'], 'theme_settings_surface_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_settings');
        Schema::dropIfExists('brand_settings');
    }
};
