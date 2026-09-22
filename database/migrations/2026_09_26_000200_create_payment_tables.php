<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, the ledger, gateway events and stored payment methods.
 *
 * The ledger is append-only and is the truth. An invoice's `paid_minor` is
 * a cached total of these rows; if the two ever disagree, the rows win.
 *
 * No card data is stored anywhere here. A payment method row holds a token
 * belonging to the gateway, the last four digits and a brand — what a
 * customer needs to recognise a card, and nothing a thief can use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('gateway', 64);
            $table->string('status', 24)->default('pending');

            $table->char('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('refunded_minor')->default(0);

            // What the provider calls this payment. Unique per gateway, so
            // a webhook can find it and a retry cannot create a second.
            $table->string('reference', 191)->nullable();

            // Sent with the outbound call so a retry — ours or theirs —
            // cannot charge twice.
            $table->string('idempotency_key', 64)->nullable();

            $table->string('failure_reason', 512)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Recorded by hand rather than by a gateway: who said so.
            $table->string('recorded_by', 191)->nullable();
            $table->string('note', 512)->nullable();

            $table->timestamps();

            $table->unique(['gateway', 'reference'], 'payments_reference_unique');
            $table->unique('idempotency_key', 'payments_idempotency_unique');
            $table->index(['organization_id', 'status'], 'payments_status_index');
            $table->index(['invoice_id', 'received_at'], 'payments_invoice_index');
        });

        Schema::create('transactions', function (Blueprint $table): void {
            // Append-only. Every movement of money, in order.
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->foreignUlid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUlid('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->string('kind', 24);
            $table->char('currency_code', 3);

            // Always positive. The kind decides which way it moves; a
            // refund recorded as a negative number is a ledger that lies
            // twice.
            $table->bigInteger('amount_minor');

            // The customer's account credit after this row, so a balance is
            // readable without summing the table.
            $table->bigInteger('credit_balance_minor')->default(0);

            $table->string('description', 512)->nullable();
            $table->string('recorded_by', 191)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['customer_id', 'occurred_at'], 'transactions_customer_index');
            $table->index(['invoice_id', 'occurred_at'], 'transactions_invoice_index');
        });

        Schema::create('gateway_events', function (Blueprint $table): void {
            // Deduplication. A gateway that delivers the same event five
            // times has to produce one payment, and the unique index is
            // what guarantees it rather than a check-then-write.
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();

            $table->string('gateway', 64);
            $table->string('event_id', 191);
            $table->string('type', 64);

            $table->string('payment_reference', 191)->nullable();
            $table->string('outcome', 32)->default('received');
            $table->string('error', 512)->nullable();

            // Kept for support. Redacted on the way in by the platform's
            // own redactor; a gateway payload is not a place to keep a pan.
            $table->json('payload')->nullable();

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->unique(['gateway', 'event_id'], 'gateway_events_unique');
            $table->index(['gateway', 'received_at'], 'gateway_events_recent_index');
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('gateway', 64);

            // The gateway's token. This platform never sees a card number.
            $table->string('token', 191);

            $table->string('brand', 32)->nullable();
            $table->char('last_four', 4)->nullable();
            $table->unsignedTinyInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->string('label', 191)->nullable();

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['gateway', 'token'], 'payment_methods_token_unique');
            $table->index(['customer_id', 'is_default'], 'payment_methods_default_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('gateway_events');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payments');
    }
};
