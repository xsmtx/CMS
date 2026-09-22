<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog: what is for sale, and what it costs.
 *
 * Every monetary column is a bigint of minor units paired with a currency
 * code (ADR 0014). There is no decimal and no float anywhere in this file,
 * and a price row exists per cycle per currency rather than being converted
 * at display time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('name', 191);
            $table->string('slug', 191);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            // Slugs are per organization, so a reseller may have a "Web
            // Hosting" group without colliding with the provider's.
            $table->unique(['organization_id', 'slug'], 'product_groups_slug_unique');
            $table->index(['organization_id', 'status', 'position'], 'product_groups_listing_index');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('product_group_id')->constrained('product_groups')->cascadeOnDelete();

            $table->string('name', 191);
            $table->string('slug', 191);
            $table->string('type', 32)->index();
            $table->text('tagline')->nullable();
            $table->text('description')->nullable();

            // Marketing bullet points, ordered. JSON because the list is
            // presentational and never queried.
            $table->json('features')->nullable();

            $table->string('status', 16)->default('active')->index();
            $table->unsignedSmallInteger('position')->default(0);

            // Null means unlimited. A zero would be indistinguishable from
            // "sold out", which is a different thing entirely.
            $table->unsignedInteger('stock')->nullable();

            $table->boolean('requires_domain')->default(false);

            $table->timestamps();

            $table->unique(['organization_id', 'slug'], 'products_slug_unique');
            $table->index(['product_group_id', 'status', 'position'], 'products_listing_index');
        });

        Schema::create('product_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();

            $table->string('billing_cycle', 24);
            $table->char('currency_code', 3);

            // Minor units, always. A product with no row in a currency is
            // simply not sellable in it.
            $table->bigInteger('recurring_minor')->default(0);
            $table->bigInteger('setup_minor')->default(0);

            $table->timestamps();

            $table->unique(
                ['product_id', 'billing_cycle', 'currency_code'],
                'product_prices_unique',
            );
        });

        Schema::create('option_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();

            $table->string('name', 191);
            $table->string('key', 64);
            $table->string('type', 16);
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);

            // Bounds for a quantity option. Ignored by the other types.
            $table->unsignedInteger('min_quantity')->default(0);
            $table->unsignedInteger('max_quantity')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'key'], 'option_groups_key_unique');
        });

        Schema::create('options', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('option_group_id')->constrained('option_groups')->cascadeOnDelete();

            $table->string('label', 191);
            $table->string('value', 191);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->unique(['option_group_id', 'value'], 'options_value_unique');
        });

        Schema::create('option_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('option_id')->constrained('options')->cascadeOnDelete();

            $table->string('billing_cycle', 24);
            $table->char('currency_code', 3);

            // Signed: an option may reduce the price as well as raise it.
            $table->bigInteger('recurring_minor')->default(0);
            $table->bigInteger('setup_minor')->default(0);

            $table->timestamps();

            $table->unique(['option_id', 'billing_cycle', 'currency_code'], 'option_prices_unique');
        });

        Schema::create('addons', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();

            $table->string('name', 191);
            $table->string('slug', 191);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['product_id', 'slug'], 'addons_slug_unique');
        });

        Schema::create('addon_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('addon_id')->constrained('addons')->cascadeOnDelete();

            $table->string('billing_cycle', 24);
            $table->char('currency_code', 3);

            $table->bigInteger('recurring_minor')->default(0);
            $table->bigInteger('setup_minor')->default(0);

            $table->timestamps();

            $table->unique(['addon_id', 'billing_cycle', 'currency_code'], 'addon_prices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_prices');
        Schema::dropIfExists('addons');
        Schema::dropIfExists('option_prices');
        Schema::dropIfExists('options');
        Schema::dropIfExists('option_groups');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_groups');
    }
};
