<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidents, their timeline and the figure frozen at the end (§15).
 *
 * **The timeline is rows, not a text field somebody edits.** An incident's
 * history is the thing a postmortem is written from and the thing a customer
 * reads on a status page, and a single body that was overwritten at each
 * update would lose exactly the part that matters: what was believed at half
 * past two, before anybody knew what it was. Append-only, like the ledger and
 * like the graph's edges.
 *
 * **The impact is frozen at resolution.** `ImpactSummary` reads the graph and
 * the graph moves — a server is decommissioned, a customer leaves, a service
 * is renamed — so an impact recomputed in March is not the impact anybody
 * acted on in January. The same reasoning that froze an issued invoice
 * (ADR 0023), applied to a figure somebody will quote in a credit
 * conversation.
 *
 * **`public` is per incident *and* per update**, because they are different
 * decisions. An incident can be public from the start and have an internal
 * note in the middle of it — "the failover did not work, trying X" is a
 * sentence an operator writes to the next operator, not to a customer.
 *
 * `reference` comes from `AllocateNumber` under the key `incident`, so it
 * belongs to the seller like every other document number (ADR 0025) and is
 * unique across the installation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('reference', 32)->unique();
            $table->string('title');
            $table->string('state', 24);
            $table->string('severity', 16);

            $table->foreignUlid('opened_by')->nullable()->constrained('staff_users')->nullOnDelete();

            /*
             * Three timestamps, and they are not the same question.
             *
             * `started_at` is when the customer's world broke, which is
             * usually earlier than anybody noticed and is what an SLA is
             * measured from. `detected_at` is when this platform or a person
             * found out, and the gap between the two is the number a
             * postmortem is actually about. `resolved_at` ends both.
             */
            $table->timestamp('started_at');
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->text('summary')->nullable();

            // Whether it appears on the status page at all. An incident is
            // public because somebody said so, never by default: "we are
            // investigating a database problem" is a sentence a company
            // chooses to publish.
            $table->boolean('is_public')->default(false);

            $table->longText('postmortem')->nullable();
            $table->timestamp('postmortem_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'state', 'started_at']);
        });

        Schema::create('incident_updates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();

            $table->foreignUlid('written_by')->nullable()->constrained('staff_users')->nullOnDelete();

            // The state as it was when this was written, copied rather than
            // read through the incident: the incident moves on, and an update
            // that said "investigating" must go on saying it.
            $table->string('state', 24);
            $table->text('body');
            $table->boolean('is_public')->default(false);

            $table->timestamps();

            $table->index(['incident_id', 'created_at']);
        });

        Schema::create('incident_impacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();

            $table->unsignedInteger('services')->default(0);
            $table->unsignedInteger('customers')->default(0);

            /*
             * Money by currency, as a list. There is no rate anywhere in this
             * product, so a total across currencies is a figure that means
             * nothing and is exactly the figure somebody would quote in a
             * credit conversation.
             */
            $table->json('recurring');

            $table->timestamp('frozen_at');
            $table->timestamps();

            $table->unique('incident_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_impacts');
        Schema::dropIfExists('incident_updates');
        Schema::dropIfExists('incidents');
    }
};
