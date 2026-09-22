<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices and credit notes.
 *
 * An issued invoice is frozen: the bill-to party is copied onto it, every
 * line's wording and amount is fixed, and the totals never recompute. An
 * invoice is a tax document in most of the world, and a document whose
 * numbers move is not evidence of anything.
 *
 * That is why the bill-to columns are here rather than joined from the
 * customer: correcting a customer's address must not silently reissue every
 * invoice they were ever sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('number', 32);

            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->string('status', 24)->default('draft');
            $table->char('currency_code', 3);

            // The party, as it was when the document was issued.
            $table->string('bill_to_name', 191)->nullable();
            $table->string('bill_to_company', 191)->nullable();
            $table->string('bill_to_tax_id', 64)->nullable();
            $table->text('bill_to_address')->nullable();
            $table->char('bill_to_country', 2)->nullable();
            $table->string('bill_to_email', 191)->nullable();

            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);

            // A cached total of the ledger. The rows are the truth; this is
            // rebuildable from them at any time.
            $table->bigInteger('paid_minor')->default(0);

            $table->json('tax_breakdown')->nullable();
            $table->string('tax_exemption_reason', 191)->nullable();

            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Proforma documents are not tax documents and are numbered
            // separately; the flag decides which sequence was used.
            $table->boolean('is_proforma')->default(false);

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'invoices_number_unique');
            $table->index(['organization_id', 'status'], 'invoices_status_index');
            $table->index(['customer_id', 'issued_on'], 'invoices_customer_index');
            $table->index(['status', 'due_on'], 'invoices_due_index');
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained('invoices')->cascadeOnDelete();

            // Kept for reporting; nulled rather than cascaded, because
            // losing an order must not lose the invoice that billed it.
            $table->foreignUlid('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();

            $table->string('description', 512);
            $table->text('detail')->nullable();
            $table->unsignedInteger('quantity')->default(1);

            $table->char('currency_code', 3);
            $table->bigInteger('unit_amount_minor')->default(0);
            $table->bigInteger('line_amount_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);

            // The rate as a decimal string, for the document. Never a float.
            $table->decimal('tax_rate', 5, 2)->nullable();

            // What this line pays for, for the renewal work in Phase 9.
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['invoice_id', 'position'], 'invoice_items_order_index');
        });

        Schema::create('credit_notes', function (Blueprint $table): void {
            // A correction to an issued invoice. Its own numbered document,
            // because nothing issued is ever edited or deleted.
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained('invoices')->cascadeOnDelete();

            $table->string('number', 32);
            $table->char('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->string('reason', 512);

            $table->string('issued_by', 191)->nullable();
            $table->date('issued_on');
            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'credit_notes_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
