<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a service landed where it did.
 *
 * `advanced-operations-plan.md` §4 calls the persisted, explainable reason the
 * deliverable of smart placement rather than a nicety, and this is that row.
 * "Why is this customer on node seven" is asked weeks later, by somebody who was
 * not there, about a node that has since been rebuilt — and without a record the
 * only answer available is to re-run the scoring against today's readings, which
 * answers a different question.
 *
 * **Append-only, like the ledger.** A service that is moved gets a second row;
 * nothing is ever rewritten. The history of where a service has lived is the
 * useful part.
 *
 * **Numbers and slugs, never a sentence.** `factors` holds each factor's score,
 * weight and the measurement behind it. The wording lives in `lang/`, because a
 * sentence written here would be in the language of whichever queue worker ran
 * the placement and unreadable to the next operator — the same rule that keeps
 * `health_message` null for staleness.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_placements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();

            // The server is nullified rather than cascaded: a decommissioned
            // node must not erase the record of the services it once held.
            $table->foreignUlid('server_id')->nullable()->constrained('servers')->nullOnDelete();

            $table->string('strategy', 32);
            $table->string('server_name');

            // Zero to one, four decimal places. Not money - a score is a
            // measurement, and there is nothing here anybody is owed.
            $table->decimal('score', 6, 4)->nullable();

            $table->unsignedSmallInteger('candidates')->default(0);
            $table->json('factors')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            // The two reads: one service's history, and the placements onto one
            // node.
            $table->index(['service_id', 'decided_at']);
            $table->index(['organization_id', 'server_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_placements');
    }
};
