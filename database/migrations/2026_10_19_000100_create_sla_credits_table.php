<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an outage cost the seller, per customer (§15).
 *
 * **This table is a link, not a second ledger.** The money moves exactly once,
 * through `IssueCreditNote` — the credit note is the numbered document, the
 * transaction is the ledger row, and both already existed (ADR 0023, ADR
 * 0024). A row here says *why* that credit note exists, which is the one fact
 * neither of those records can hold: a credit note's reason is a sentence, and
 * "which outage was this for" is a question somebody asks with a report rather
 * than by reading sentences.
 *
 * The amounts are copied onto the row all the same. That is not a cache to
 * rebuild: a credit note may be raised for an amount an operator chose, and a
 * report of "what incidents cost us last quarter" must not change because
 * somebody later credited the same invoice again for something else.
 *
 * **One credit per incident and invoice**, by unique index. Two people looking
 * at the same outage on the same morning is the ordinary case, and the second
 * one must be refused by the database rather than by a screen that was
 * rendered before the first one pressed the button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_credits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained('invoices')->cascadeOnDelete();

            /*
             * The credit note this raised. Not nullable and not
             * `nullOnDelete`: a row here without one would be a claim that
             * money moved with nothing to show for it, and a credit note is
             * a numbered document that is never deleted anyway.
             */
            $table->foreignUlid('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();

            // Integer minor units and an ISO code, like every other monetary
            // value in this product. No float touches this path.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);

            $table->string('reason', 500);
            $table->foreignUlid('issued_by')->nullable()->constrained('staff_users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['incident_id', 'invoice_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_credits');
    }
};
