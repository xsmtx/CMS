<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carts.
 *
 * A record rather than a session array: a visitor who signs in halfway
 * through keeps what they chose, an operator can see what was abandoned,
 * and the pricing engine has one thing to read.
 *
 * Product lines carry no amounts. They reference the catalog and are priced
 * on every read, so a cart always shows what the plan costs now — the
 * copying that matters happens when the order is placed. Domain lines are
 * the exception: their price comes from outside the catalog, so the quote
 * lives on the line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // A cart belongs to a signed-in contact, or to a browser holding
            // the token, or to both once they sign in.
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->char('token', 40)->unique();

            // Fixed by the first item. Mixing currencies in one cart is
            // refused rather than converted.
            $table->char('currency_code', 3);

            $table->foreignUlid('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->string('promotion_code', 64)->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'contact_id'], 'carts_contact_index');
            $table->index('expires_at', 'carts_expiry_index');
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('cart_id')->constrained('carts')->cascadeOnDelete();

            // An addon line hangs off the product line it was bought with.
            $table->foreignUlid('parent_id')->nullable()->constrained('cart_items')->cascadeOnDelete();

            $table->string('kind', 16)->default('product');

            $table->foreignUlid('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignUlid('addon_id')->nullable()->constrained('addons')->cascadeOnDelete();
            $table->string('billing_cycle', 24)->nullable();
            $table->unsignedInteger('quantity')->default(1);

            // Domain lines: the name, and the quote that produced the price.
            $table->string('domain', 253)->nullable();
            $table->string('domain_tld', 63)->nullable();
            $table->unsignedTinyInteger('domain_years')->nullable();
            $table->bigInteger('domain_registration_minor')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['cart_id', 'position'], 'cart_items_order_index');
        });

        Schema::create('cart_item_options', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('cart_item_id')->constrained('cart_items')->cascadeOnDelete();

            $table->foreignUlid('option_group_id')->constrained('option_groups')->cascadeOnDelete();

            // Null for a quantity option, which has a number rather than a
            // chosen row.
            $table->foreignUlid('option_id')->nullable()->constrained('options')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);

            $table->timestamps();

            // One answer per question.
            $table->unique(['cart_item_id', 'option_group_id'], 'cart_item_options_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_item_options');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
