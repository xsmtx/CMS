<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a bank statement says that the ledger did not yet record.
 *
 * Three columns, for the three things an operator reconciling a statement
 * has in front of them and could not previously write down:
 *
 * **Fees.** A gateway takes its cut before the money lands. The customer
 * paid the full amount and the invoice is settled in full, so the fee is
 * not a smaller payment — it is a cost of taking it, and a column of its
 * own is the only place it can live without making one of those two
 * numbers a lie.
 *
 * **Gateway and reference.** A payment row already carries both, but a
 * credit grant or an adjustment has no payment behind it, and the operator
 * who wrote it still knows which bank the transfer came through and what
 * the bank called it. Copied onto the transaction rather than joined,
 * because the ledger is read as a statement — a row that needs a join to
 * say how the money moved is a row that reads wrong in a list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            // Always positive, like every other amount here. A fee is
            // taken out; the column does not need a sign to say so.
            $table->bigInteger('fees_minor')->default(0)->after('credit_balance_minor');

            $table->string('gateway', 64)->nullable()->after('kind');

            // What the bank or the gateway calls this movement. Not
            // unique: a single provider reference can produce a payment
            // row and a credit row in the same breath.
            $table->string('reference', 191)->nullable()->after('gateway');

            $table->index(['organization_id', 'occurred_at'], 'transactions_period_index');
        });

        Schema::table('payments', function (Blueprint $table): void {
            // The same number on the attempt that produced it, so a
            // reconciliation can start from either end.
            $table->bigInteger('fees_minor')->default(0)->after('refunded_minor');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_period_index');
            $table->dropColumn(['fees_minor', 'gateway', 'reference']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('fees_minor');
        });
    }
};
