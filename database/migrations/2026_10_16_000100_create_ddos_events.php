<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attacks, and who was behind the address (§7).
 *
 * **The event, never the series.** §7 asks for external scalable flow storage
 * and §14 already put the series in the monitoring system; a NetFlow
 * collector inside a billing database would be a time-series store nobody
 * sized, for the second time in this product. What is kept here is what was
 * hit, when, how hard at its worst, what shape it was, and what was done —
 * plus the one thing no scrubbing vendor can supply, which is whose service
 * it was.
 *
 * **`customer_id` and `service_id` are resolved, not reported.** The vendor
 * knows an address; `ip_assignments` knows who held that address *at the time
 * the attack started*, which is exactly what §5 made that table append-only
 * for. An event whose address nobody held is kept with both null, because
 * "an attack on an address that is not ours" is a finding rather than a row
 * to throw away.
 *
 * **`reference` is the provider's own id and the unique key is on it.** The
 * sweep runs every few minutes and an attack lasting an hour is reported
 * again each time with a later end and a higher peak; the row is updated
 * rather than duplicated. Deduplicating on the address and a timestamp would
 * either merge two genuine attacks or write one forty times.
 *
 * Peaks are `decimal`, not float, and that is not the money rule bending —
 * they are measurements. Gigabits and megapackets are reported to one or two
 * places by every vendor, and a float column that answered 11.699999999 for a
 * reported 11.7 would be a figure an operator could not reconcile with the
 * invoice for the transit that carried it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ddos_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Which adapter said so, and what it called the event.
            $table->string('source', 64);
            $table->string('reference', 191);

            $table->string('target_address', 45);
            $table->foreignUlid('ip_address_id')->nullable()
                ->constrained('ip_addresses')->nullOnDelete();

            // Resolved through the assignment that covered the address when it
            // started. Null is a real answer: somebody attacked an address
            // nobody was holding.
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignUlid('service_id')->nullable()->constrained('services')->nullOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->decimal('peak_gbps', 12, 3)->nullable();
            $table->decimal('peak_mpps', 12, 3)->nullable();

            $table->json('vectors')->nullable();
            $table->string('mitigation')->nullable();

            // Phase D is where an incident exists. Until then this is a
            // nullable reference rather than a foreign key to a table that is
            // not there.
            $table->string('incident_reference')->nullable();

            $table->timestamps();

            // One row per event, whatever the sweep does.
            $table->unique(['source', 'reference']);

            // The two reads: what is happening now, and what has happened to
            // this customer.
            $table->index(['organization_id', 'ended_at', 'started_at']);
            $table->index(['customer_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ddos_events');
    }
};
