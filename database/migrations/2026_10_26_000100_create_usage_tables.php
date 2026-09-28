<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usage metering: what is measured, and what was measured (§25).
 *
 * **`usage_meters` is where the price lives**, and that is deliberate rather
 * than a shortcut around the catalog. A product price is a cycle and a
 * currency (ADR 0021's matrix); a usage rate is per service, per meter, with
 * an allowance — three dimensions that matrix cannot express. So the seller
 * states it here, once per service, and a metering source states only the
 * quantity: a module that returned money would be a module setting prices.
 *
 * **`usage_snapshots` is append-only and quoted by an invoice line**, which
 * is §25's own phrase. An invoice is frozen the moment it is issued (ADR
 * 0023), so the number on it must never be recomputed: the snapshot is
 * stamped with the invoice item that quoted it and can never be quoted again.
 * A meter that later revises history — they do — produces a *second* snapshot
 * for the same period and the correction is a credit note, never an edit.
 *
 * `quantity` is a decimal rather than an integer because it is a measurement,
 * and rather than a float because it is a measurement somebody will reconcile
 * against an invoice: 11.699999999 beside a figure a customer was charged is
 * a number an operator cannot explain. The money it becomes is integer minor
 * units like everything else.
 *
 * The unique key on `(usage_meter_id, period_start, period_end)` is what
 * makes the sweep idempotent — running it twice for one month writes one
 * snapshot, which is the rule every automation task is held to (ADR 0031).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_meters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();

            // Which source measures it, and what that source calls the meter.
            $table->string('source', 64);
            $table->string('meter_key', 64);
            // What the source calls this service. A panel's account name is
            // not a ULID, and the mapping has to live somewhere.
            $table->string('service_key', 191);

            $table->string('unit', 16);
            // What the customer gets before anything is charged. Stored on
            // the meter rather than derived from the product, because two
            // customers on one product routinely have different allowances.
            $table->decimal('included_quantity', 20, 4)->default(0);
            $table->unsignedBigInteger('rate_minor')->default(0);
            $table->char('currency_code', 3);

            $table->timestamps();

            $table->unique(['service_id', 'source', 'meter_key']);
            $table->index(['organization_id', 'source']);
        });

        Schema::create('usage_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('usage_meter_id')->constrained('usage_meters')->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();

            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->decimal('quantity', 20, 4);
            // Copied from the meter, not read through it: the meter's unit
            // may change and this snapshot is what somebody was charged for.
            // The same reason an order line copies the catalog (ADR 0021).
            $table->string('unit', 16);
            $table->string('source', 64);

            // Stamped when a line quotes it, and never cleared. A snapshot
            // with an invoice item is one that has been charged for.
            $table->foreignUlid('invoice_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();

            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['usage_meter_id', 'period_start', 'period_end']);
            // The query the invoice run makes: what has this service used
            // that nothing has charged for.
            $table->index(['service_id', 'invoice_item_id']);
            $table->index(['organization_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_snapshots');
        Schema::dropIfExists('usage_meters');
    }
};
