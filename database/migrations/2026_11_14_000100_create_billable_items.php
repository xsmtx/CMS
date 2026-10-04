<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A one-off charge that the next invoice quotes (`whmcs-parity-plan.md` §2.2).
 *
 * An hour of migration work, a hardware part, an excess-bandwidth charge
 * somebody negotiated. This product had no way to put anything on a future
 * invoice at all.
 *
 * **It is quoted, never edited onto an issued document.** The shape is the one
 * `usage_snapshots` already has: a row exists, a renewal invoice picks it up,
 * and the row is stamped with the line that quoted it so nothing can charge it
 * twice. An invoice is frozen at issue (ADR 0023), so a charge that arrived
 * afterwards belongs on the next one rather than on that one.
 *
 * **`charge_on` is "not before", not "on".** Null means the next invoice
 * whenever it comes; a date means wait until then. It is a date rather than a
 * flag because the ordinary request — "bill this with their January renewal" —
 * has a date in it, and a flag would make somebody remember to come back.
 *
 * **No tax column**, and that is the same decision the renewal sweep made: a
 * renewal invoice in this product carries no tax, and one taxed line among
 * untaxed ones would be a document nobody can reconcile. When renewals learn
 * to tax, this follows them automatically because it is the same invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billable_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();

            /*
             * What it is about, when it is about anything. Nullable because
             * half of these are not: a migration somebody did by hand, or a
             * part that went into a machine the customer does not own a
             * service on.
             */
            $table->foreignUlid('service_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);

            // Money is integer minor units and an ISO code (non-negotiable 4).
            // Signed, because a negotiated reduction is a one-off charge of a
            // negative amount and a credit note would be the wrong document
            // for something that has not been invoiced yet.
            $table->char('currency_code', 3);
            $table->bigInteger('unit_amount_minor')->default(0);

            // "Not before". Null is the next invoice, whenever that is.
            $table->date('charge_on')->nullable();

            /*
             * Stamped when an invoice quotes it, which is what stops it being
             * charged twice — the rule `usage_snapshots` already lives under,
             * and the reason this is safe inside a run that may be retried.
             */
            $table->ulid('invoice_item_id')->nullable();
            $table->timestamp('charged_at')->nullable();

            $table->foreignUlid('created_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            // The sweep's own query: what is uncharged, for this customer, in
            // this currency.
            $table->index(['organization_id', 'customer_id', 'charged_at']);
            $table->index(['customer_id', 'currency_code', 'charged_at']);
            $table->index('invoice_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billable_items');
    }
};
