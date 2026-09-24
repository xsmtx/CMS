<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The financial answers that are not a tax rate.
 *
 * Three things an installation in one country needs to state differently from
 * one in another, and all three were constants in `config/platform.php` — which
 * means an operator could not change them without an environment file and a
 * deploy, and a reseller could not have their own at all:
 *
 * - **When an invoice falls due.** Net 14 is a habit, not a law. Net 30 is the
 *   norm for business selling in much of Europe; some jurisdictions require
 *   immediate payment on a consumer sale.
 * - **What a late payment costs.** A percentage of what is outstanding, because
 *   a fixed amount needs a currency and this platform never converts one
 *   (ADR 0014). Zero means no fee, the way every other threshold in this
 *   product reads zero.
 * - **What the document has to say.** Several countries mandate a sentence on an
 *   invoice — a registration number, a court of registry, a statement about
 *   retention of title. It is printed, so it is per seller and never a secret.
 *
 * Numbering gets two columns rather than a table of its own, because
 * `number_sequences` already is that table. **A sequence that restarts every
 * year is a legal requirement** in Turkey, Italy, Spain, Portugal and Poland
 * among others, and the platform could not express it: one unbroken run from
 * INV-000001 forever is not an acceptable invoice book in any of them.
 *
 * `period_key` holds the period the current `next_value` belongs to — `2026` or
 * `2026-10`. It is compared rather than computed from `updated_at`: a sequence
 * nobody used in January must still restart in January, and `updated_at` cannot
 * tell "not used since December" from "reset in December".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // How long a customer has. Zero is "on receipt", which is a real
            // answer rather than a missing one.
            $table->unsignedSmallInteger('due_days')->default(14);

            /*
             * Parts per million, like a tax rate and for the same reason: a fee
             * of 1.5% a month is 15 000, and nothing here has ever been a float.
             * Zero disables the fee entirely.
             */
            $table->unsignedInteger('late_fee_rate_ppm')->default(0);

            // What the fee line is called on the document it appears on. The
            // wording is the operator's, because it is the wording their own
            // customers will read and possibly dispute.
            $table->string('late_fee_label', 64)->nullable();

            // Printed on every document this seller issues. Never a secret: a
            // brand is printed by templates a theme author wrote (ADR 0036) and
            // this is the same kind of thing.
            $table->text('document_note')->nullable();

            $table->timestamps();

            // One row per seller. `firstOrNew` on this key is the whole
            // contract, and a second row would be two sets of terms with no
            // way to say which won.
            $table->unique('organization_id', 'billing_settings_unique');
        });

        Schema::table('number_sequences', function (Blueprint $table): void {
            $table->string('reset_period', 16)->default('never')->after('padding');
            $table->string('period_key', 8)->nullable()->after('reset_period');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            /*
             * A late fee is its own invoice (ADR 0046), and this is how the
             * dunning sweep knows not to chase it.
             *
             * Without the flag the sweep would find the fee invoice overdue the
             * day after it was raised and run the whole sequence against it:
             * a fee on the fee, nightly and compounding, and a suspend step
             * taking a customer's server down over three euros of interest. The
             * debt that matters is the invoice the fee was about, and that one
             * is still in the sweep.
             */
            $table->boolean('is_late_fee')->default(false)->after('is_proforma');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('is_late_fee');
        });

        Schema::table('number_sequences', function (Blueprint $table): void {
            $table->dropColumn(['reset_period', 'period_key']);
        });

        Schema::dropIfExists('billing_settings');
    }
};
