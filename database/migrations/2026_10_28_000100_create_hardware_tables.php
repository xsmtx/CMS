<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parts, and where each of them has been (§11).
 *
 * **This is about parts, not about machines.** A machine is a `servers` row.
 * A part — a disk, a DIMM, a PSU, an optic — has its own serial, its own
 * warranty, and a history of being moved from one machine to another. That is
 * the whole value: a disk outlives the machine it was first fitted to, and
 * "where has this serial been" is the question a warranty claim turns on.
 *
 * **A part is never deleted and a fitting is never edited.** Taking a part out
 * closes its fitting; putting it somewhere else opens a new one. `ip_assignments`
 * made the same choice for the same reason — the question is always historical,
 * and a row that could be rewritten is a history nobody can rely on.
 *
 * **`warranty_until` in the past is a fact, not an error.** A screen that
 * refused to record an out-of-warranty part would be refusing the parts an
 * operator most needs to track, since those are the ones about to fail.
 *
 * The serial is unique per organization rather than globally: two providers
 * on one installation may each hold a part whose vendor numbers from one, and
 * a collision across them would be this platform inventing a relationship.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hardware_parts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('kind', 32);
            $table->string('model')->nullable();
            // Nullable, because a blanking plate and a cable have none — and a
            // part with no serial is still worth recording where it is.
            $table->string('serial', 191)->nullable();
            // What the operator's own asset register calls it.
            $table->string('asset_tag', 64)->nullable();
            $table->string('vendor')->nullable();

            $table->date('purchased_on')->nullable();
            $table->date('warranty_until')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'serial']);
            $table->index(['organization_id', 'kind']);
            $table->index('warranty_until');
        });

        Schema::create('part_fittings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('hardware_part_id')->constrained('hardware_parts')->cascadeOnDelete();
            $table->foreignUlid('server_id')->constrained('servers')->cascadeOnDelete();

            $table->timestamp('fitted_at');
            // Open while the part is in that machine. Closed, never deleted.
            $table->timestamp('removed_at')->nullable();

            $table->foreignUlid('fitted_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignUlid('removed_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            // "Where is this part now" and "what has been in this machine".
            $table->index(['hardware_part_id', 'removed_at']);
            $table->index(['server_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_fittings');
        Schema::dropIfExists('hardware_parts');
    }
};
