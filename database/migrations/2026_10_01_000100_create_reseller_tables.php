<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a reseller may sell, and for how much.
 *
 * **Two tables, because they answer two questions that fail differently.**
 * "May this reseller offer this product at all" is a decision the provider
 * makes once; "what does this reseller's customer pay for it" is a number
 * the reseller changes on a Tuesday. One table with a nullable price would
 * make removing a price and removing the product the same edit.
 *
 * `reseller_products` — availability. **Absence is a refusal**: a reseller
 * with no rows sells nothing. The alternative, absence meaning "the whole
 * catalogue", is a reseller created on Friday exposing a product the
 * provider had not meant to expose, with no way to notice.
 *
 * `reseller_prices` — an exact number for one product, cycle and currency.
 * It beats any margin, because an operator who typed a number meant that
 * number.
 *
 * The margin itself lives on `reseller_products` rather than in a third
 * table: a markup is a property of "you may sell this", it is the common
 * case, and a separate table would mean a join to answer the question every
 * catalogue read asks.
 *
 * `organization_id` is the **reseller**, and the row is owned by them like
 * everything else here — which is what makes "a reseller cannot see another
 * reseller's margins" true by the same mechanism as everything else, rather
 * than by a clause somebody has to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();

            // A decimal string, never a float. Null means "no markup" and is
            // not the same as '0.00' — one is "the provider's price", the
            // other is somebody having typed zero, and an operator looking
            // at the screen deserves to see which.
            $table->decimal('margin_percent', 8, 4)->nullable();

            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'product_id'], 'reseller_products_unique');
            $table->index(['organization_id', 'is_enabled'], 'reseller_products_enabled_index');
        });

        Schema::create('reseller_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();

            $table->string('billing_cycle', 24);
            $table->char('currency_code', 3);

            // Integer minor units, like every amount in this platform.
            $table->bigInteger('recurring_minor');
            $table->bigInteger('setup_minor')->default(0);

            $table->timestamps();

            $table->unique(
                ['organization_id', 'product_id', 'billing_cycle', 'currency_code'],
                'reseller_prices_unique',
            );
        });

        Schema::create('reseller_ledger_entries', function (Blueprint $table): void {
            // What the reseller owes the provider, and what they hold.
            //
            // A ledger rather than a column on the organization row, for the
            // reason every financial history here is append-only: a balance
            // that was one number is a number somebody can edit, and then
            // nobody can say what it used to be or why it moved.
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('kind', 24);
            $table->char('currency_code', 3);

            // Always positive. The kind decides direction (ADR 0024).
            $table->bigInteger('amount_minor');

            // The running balance after this row, so a balance is readable
            // without summing the table.
            $table->bigInteger('balance_minor')->default(0);

            $table->foreignUlid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUlid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('description', 512)->nullable();
            $table->string('recorded_by', 191)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['organization_id', 'occurred_at'], 'reseller_ledger_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_ledger_entries');
        Schema::dropIfExists('reseller_prices');
        Schema::dropIfExists('reseller_products');
    }
};
