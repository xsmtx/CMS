<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders.
 *
 * Every line copies what it was sold as — the product's name, the option
 * labels, the cycle, the currency and every amount — rather than reading
 * them back through the catalog. A referenced price means an operator
 * raising a price rewrites what past customers agreed to, a deleted option
 * makes an old order unreadable, and an invoice issued in March changes in
 * April. The catalog ids are kept alongside, for reporting only.
 *
 * Numbers come from a sequence table rather than a count, so two orders
 * placed in the same second cannot claim the same number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // 'order' now; 'invoice' and 'credit_note' reuse this in Phase 4.
            $table->string('key', 32);
            $table->string('prefix', 16)->default('');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);

            $table->timestamps();

            $table->unique(['organization_id', 'key'], 'number_sequences_unique');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('number', 32);

            // An order outlives the contact who placed it; it does not
            // outlive the customer it belongs to.
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->string('status', 24)->default('draft');
            $table->char('currency_code', 3);

            // Minor units throughout. `recurring_total` is what renews;
            // `total` is what is owed now, setup fees included.
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('setup_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('recurring_total_minor')->default(0);

            $table->foreignUlid('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->string('promotion_code', 64)->nullable();

            // Named components, so an invoice can list each tax separately.
            $table->json('tax_breakdown')->nullable();
            $table->string('tax_exemption_reason', 191)->nullable();

            $table->string('risk_decision', 16)->nullable();
            $table->unsignedSmallInteger('risk_score')->nullable();
            $table->json('risk_reasons')->nullable();
            $table->timestamp('risk_reviewed_at')->nullable();
            $table->string('risk_reviewed_by', 191)->nullable();

            // What the customer agreed to, and from where.
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 32)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'orders_number_unique');
            $table->index(['organization_id', 'status'], 'orders_status_index');
            $table->index(['customer_id', 'placed_at'], 'orders_customer_index');
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('order_items')->cascadeOnDelete();

            $table->string('kind', 16)->default('product');

            // Kept for reporting. Nulled rather than cascaded: a deleted
            // product must not take the record of its sales with it.
            $table->foreignUlid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignUlid('addon_id')->nullable()->constrained('addons')->nullOnDelete();

            // Copied at the moment of ordering.
            $table->string('name', 191);
            $table->string('group_name', 191)->nullable();
            $table->text('description')->nullable();
            $table->string('billing_cycle', 24)->nullable();
            $table->unsignedInteger('quantity')->default(1);

            $table->char('currency_code', 3);
            $table->bigInteger('unit_recurring_minor')->default(0);
            $table->bigInteger('unit_setup_minor')->default(0);
            $table->bigInteger('line_recurring_minor')->default(0);
            $table->bigInteger('line_setup_minor')->default(0);
            $table->bigInteger('line_discount_minor')->default(0);
            $table->bigInteger('line_total_minor')->default(0);

            $table->string('domain', 253)->nullable();
            $table->string('domain_tld', 63)->nullable();
            $table->unsignedTinyInteger('domain_years')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['order_id', 'position'], 'order_items_order_index');
        });

        Schema::create('order_item_options', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('order_item_id')->constrained('order_items')->cascadeOnDelete();

            $table->foreignUlid('option_group_id')->nullable()->constrained('option_groups')->nullOnDelete();
            $table->foreignUlid('option_id')->nullable()->constrained('options')->nullOnDelete();

            // Copied, so an option renamed or deleted next year does not
            // change what this order says was bought.
            $table->string('group_name', 191);
            $table->string('group_key', 64);
            $table->string('label', 191);
            $table->string('value', 191);
            $table->unsignedInteger('quantity')->default(1);

            $table->char('currency_code', 3);
            $table->bigInteger('recurring_minor')->default(0);
            $table->bigInteger('setup_minor')->default(0);

            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table): void {
            // Append-only: the account of how an order got where it is.
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('order_id')->constrained('orders')->cascadeOnDelete();

            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);

            $table->string('actor_type', 191)->nullable();
            $table->ulid('actor_id')->nullable();
            $table->string('actor_label', 191)->nullable();

            $table->string('reason', 512)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['order_id', 'occurred_at'], 'order_status_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('number_sequences');
    }
};
