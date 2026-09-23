<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an operator changed about a line, and what a domain line is for.
 *
 * **The override.** A price agreed on the phone is a real thing, and the
 * alternative — an operator editing the catalog for one customer and
 * forgetting to put it back — is how a price list stops meaning anything.
 * Carried on the cart line and copied onto the order line, because an
 * order line copies the catalog rather than referencing it (ADR 0021):
 * the agreed number has to survive a later price change like every other
 * amount on the document.
 *
 * Nullable, and null is not zero. No row means "whatever the catalog
 * says"; a row of zero means somebody agreed to give it away.
 *
 * **The domain columns.** Whether the line registers a name or takes over
 * one somebody already owns, and which of the three per-domain services
 * were bought with it. A domain is not a service (ADR 0028), so these are
 * facts about the order line rather than a product in the catalogue.
 *
 * There is no column here for an EPP code, on purpose. A transfer code is
 * fetched, shown once and never written down; the transfer asks for it at
 * the moment it is submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cart_items', 'order_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->bigInteger('price_override_minor')->nullable()->after('quantity');
                $blueprint->string('domain_action', 16)->nullable()->after('domain_years');
                $blueprint->json('domain_addons')->nullable()->after('domain_action');
            });
        }
    }

    public function down(): void
    {
        foreach (['cart_items', 'order_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['price_override_minor', 'domain_action', 'domain_addons']);
            });
        }
    }
};
