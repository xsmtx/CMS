<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TLDs, their prices, and the names customers hold.
 *
 * Pricing is a matrix, the same decision as
 * [ADR 0019](../../docs/adr/0019-price-matrix.md) with different axes: one
 * row per (tld, action, years, currency), entered by hand, and **a missing
 * row means not sold**. A `.com` transfer that costs a year's renewal and a
 * redemption that costs forty times a registration are different numbers an
 * operator types, not one number with multipliers applied to it.
 *
 * The registrant is deliberately absent. The registry holds the
 * authoritative record of who owns a name; a second copy here would drift
 * the first time somebody corrected an address on one side only.
 *
 * So is the EPP transfer code. It is the credential that moves a domain
 * away, it is fetched when asked for, and a stored copy is a copy somebody
 * has to protect forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tlds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Stored without the leading dot: `com`, `co.uk`.
            $table->string('extension', 63);

            $table->string('registrar', 48)->nullable();

            $table->unsignedTinyInteger('min_years')->default(1);
            $table->unsignedTinyInteger('max_years')->default(10);

            $table->boolean('allows_transfer')->default(true);
            $table->boolean('allows_whois_privacy')->default(false);
            $table->boolean('requires_epp_code')->default(true);
            $table->boolean('supports_idn')->default(false);

            $table->string('status', 24)->default('active');
            $table->unsignedSmallInteger('position')->default(0);

            // Registry grace periods, for the dates Phase 9 will act on.
            $table->unsignedSmallInteger('grace_days')->default(30);
            $table->unsignedSmallInteger('redemption_days')->default(30);

            $table->timestamps();

            $table->unique(['organization_id', 'extension'], 'tlds_extension_unique');
            $table->index(['organization_id', 'status'], 'tlds_status_index');
        });

        Schema::create('tld_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('tld_id')->constrained('tlds')->cascadeOnDelete();

            $table->string('action', 24);
            $table->unsignedTinyInteger('years');
            $table->char('currency_code', 3);

            $table->bigInteger('amount_minor');

            // What the registrar charges, for margin reporting. Never shown
            // to a customer and never used to compute a sale price.
            $table->bigInteger('cost_minor')->nullable();

            $table->timestamps();

            $table->unique(
                ['tld_id', 'action', 'years', 'currency_code'],
                'tld_prices_unique',
            );
        });

        Schema::create('domains', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->foreignUlid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUlid('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignUlid('tld_id')->nullable()->constrained('tlds')->nullOnDelete();

            $table->string('registrar', 48)->nullable();
            $table->string('status', 24)->default('pending');

            // Split, and both copied: a TLD renamed or removed must not
            // change what this domain is called.
            $table->string('label', 63);
            $table->string('extension', 63);
            $table->string('name', 253);

            $table->unsignedTinyInteger('years')->default(1);
            $table->char('currency_code', 3);
            $table->bigInteger('renewal_minor')->default(0);

            $table->string('external_id', 191)->nullable();

            $table->date('registered_on')->nullable();
            $table->date('expires_on')->nullable();

            $table->boolean('auto_renew')->default(true);
            $table->boolean('registrar_lock')->default(true);
            $table->boolean('whois_privacy')->default(false);

            $table->json('nameservers')->nullable();

            $table->text('failure_reason')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status'], 'domains_status_index');
            $table->index(['customer_id', 'status'], 'domains_customer_index');
            $table->index(['status', 'expires_on'], 'domains_expiry_index');

            // One domain per order line, which is what stops a replayed
            // webhook from registering the same name twice.
            $table->unique('order_item_id', 'domains_order_item_unique');

            // A name can only be held once by this installation. A second
            // row for the same name is a bug, not a second domain.
            $table->unique(['organization_id', 'name'], 'domains_name_unique');
        });

        Schema::create('domain_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('domain_id')->constrained('domains')->cascadeOnDelete();

            $table->string('operation', 32);
            $table->string('outcome', 24);

            $table->string('actor_label', 191)->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->string('correlation_id', 64)->nullable();

            $table->timestamp('occurred_at');

            $table->index(['domain_id', 'occurred_at'], 'domain_events_domain_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tld_prices');
        Schema::dropIfExists('tlds');
    }
};
