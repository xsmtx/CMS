<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions and the record of who used them.
 *
 * A fixed discount is an amount of money and therefore belongs to a
 * currency; a percentage is a number and does not. The table carries both
 * columns and the model enforces which one applies, because a nullable pair
 * with a type discriminator is honest about a thing that genuinely has two
 * shapes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('code', 64);
            $table->string('name', 191);
            $table->text('description')->nullable();

            $table->string('type', 16);

            // Fixed amounts: minor units plus the currency they are in.
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency_code', 3)->nullable();

            // Percentages: a decimal string, never a float. 5,2 covers
            // 0.01% to 100.00%.
            $table->decimal('percentage', 5, 2)->nullable();

            $table->string('scope', 16)->default('order');
            $table->string('application', 16)->default('first_payment');

            // Which billing cycles the code is good for. Null means all of
            // them; an empty list would mean none, which nobody wants.
            $table->json('billing_cycles')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedInteger('per_customer_limit')->nullable();

            $table->bigInteger('minimum_subtotal_minor')->nullable();

            $table->boolean('new_customers_only')->default(false);
            $table->boolean('stackable')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Codes are typed by customers, so they are unique per
            // organization and compared case-insensitively by the model.
            $table->unique(['organization_id', 'code'], 'promotions_code_unique');
            $table->index(['organization_id', 'is_active'], 'promotions_active_index');
        });

        Schema::create('promotion_products', function (Blueprint $table): void {
            $table->foreignUlid('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->cascadeOnDelete();

            $table->primary(['promotion_id', 'product_id']);
        });

        Schema::create('promotion_redemptions', function (Blueprint $table): void {
            // Append-only. "What did this promotion cost us" is a query over
            // this table, not a reconstruction from orders.
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('promotion_id')->constrained('promotions')->cascadeOnDelete();

            // The order is kept even if it is later cancelled: the code was
            // still spent at the time.
            $table->ulid('order_id')->nullable();
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->timestamp('redeemed_at');

            $table->index(['promotion_id', 'customer_id'], 'promotion_redemptions_customer_index');
            $table->index('order_id', 'promotion_redemptions_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotion_products');
        Schema::dropIfExists('promotions');
    }
};
