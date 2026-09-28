<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where everything physically is (§11).
 *
 * **The first thing in this product that no adapter can discover.** A rack is
 * not an API; somebody types it in, and the platform's job is to hold it
 * without an opinion. Core ships no rack sizes, no naming convention and no
 * assumption that a datacenter has rooms — a single-room provider makes one
 * room and stops thinking about it.
 *
 * **A device in a rack is a `servers` row or a label, never a second table of
 * machines.** A `devices` table would immediately be a second answer to "what
 * servers do we have", which is the mistake ADR 0043 was written about. What
 * a rack position adds is *where it is*, which is the fact no other table
 * holds — and a label covers the switch, the patch panel and the blanking
 * plate, none of which this platform sells.
 *
 * **Units are numbered from the bottom**, which is how every rack in the world
 * is labelled and how everybody reads one. A position is its lowest unit and
 * a height, so a 2U server at 10 occupies 10 and 11.
 *
 * There is no front/rear here, deliberately. Modelling both faces is only
 * worth anything beside a drawing, and §11 puts the drawing in a later phase;
 * a first cut that pretended to know which face a patch panel is on would be
 * data nobody could check.
 *
 * `rack_rows` rather than `rows`, because `rows` is a reserved word in enough
 * SQL dialects to be a trap somebody trips over during a migration to one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datacenters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('name');
            // What the operator's own paperwork calls it: `AMS1`, `DC2`.
            $table->string('code', 32)->nullable();
            $table->text('address')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('rooms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('datacenter_id')->constrained('datacenters')->cascadeOnDelete();

            $table->string('name');

            $table->timestamps();

            $table->unique(['datacenter_id', 'name']);
        });

        Schema::create('rack_rows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('room_id')->constrained('rooms')->cascadeOnDelete();

            $table->string('name');

            $table->timestamps();

            $table->unique(['room_id', 'name']);
        });

        Schema::create('racks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('room_id')->constrained('rooms')->cascadeOnDelete();
            // A rack in a room that has no rows is ordinary, so this is
            // nullable rather than a row called "the only row".
            $table->foreignUlid('rack_row_id')->nullable()->constrained('rack_rows')->nullOnDelete();

            $table->string('name');
            // Forty-two because that is what a rack usually is, and an
            // operator changes it. Core states no other number.
            $table->unsignedSmallInteger('units')->default(42);
            // What the feeds into this cabinet can carry. Nullable, because a
            // provider renting space is often not told — and a zero would
            // draw every rack as over capacity.
            $table->unsignedInteger('power_capacity_watts')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['room_id', 'name']);
            $table->index(['organization_id', 'room_id']);
        });

        Schema::create('rack_positions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('rack_id')->constrained('racks')->cascadeOnDelete();

            // The lowest unit it occupies, and how many. Units are numbered
            // from the bottom, which is how every rack is labelled.
            $table->unsignedSmallInteger('start_unit');
            $table->unsignedSmallInteger('unit_height')->default(1);

            // A machine this platform knows about, or a label for one it does
            // not: a switch, a patch panel, a blanking plate.
            $table->foreignUlid('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->string('label')->nullable();

            $table->timestamps();

            /*
             * Two things cannot start on the same unit. It does not stop a 2U
             * device overlapping the 1U above it — no index can express that
             * — so the overlap rule lives in `PlaceDevice`, and this is the
             * guard behind it for the case two requests arrive at once.
             */
            $table->unique(['rack_id', 'start_unit']);
            $table->index('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rack_positions');
        Schema::dropIfExists('racks');
        Schema::dropIfExists('rack_rows');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('datacenters');
    }
};
