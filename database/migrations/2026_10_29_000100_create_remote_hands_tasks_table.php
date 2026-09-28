<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asking somebody to go and touch a machine (§11).
 *
 * **A record before it is a request**, which is the same shape
 * `network_changes` has and for the same reason: an audit that says a machine
 * was opened is worth more than a ticket that says somebody was asked to open
 * it. Who asked, why, which device, which part, what the serials were before
 * and after, and what the technician saw.
 *
 * **The technician is a name, not a staff user.** The person who walks to the
 * rack usually works for the datacenter and has no account here, and a
 * foreign key to `staff_users` would mean either inventing accounts for
 * contractors or leaving the field empty on every task that matters.
 *
 * **The evidence is a reference, never a file.** Phase E's abuse desk settled
 * that: core holds a pointer — a ticket in the datacenter's own system, a
 * photograph in somebody's storage — and not the photograph. A platform that
 * accepted uploads here would be a platform sized for them.
 *
 * `network_change_id` is §11's physical-access window: a task that needs
 * somebody inside the cage during a change is the same event twice, and the
 * link is what makes the two records one story.
 *
 * Nothing in this table is deleted. A task that should not have been asked
 * for is `cancelled`, because "we asked and then thought better of it" is
 * itself worth reading six weeks later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_hands_tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Where to go and what to touch. All nullable: a task can be
            // about a rack nobody has recorded a device in, and about a part
            // nobody has a row for until it arrives.
            $table->foreignUlid('rack_id')->nullable()->constrained('racks')->nullOnDelete();
            $table->foreignUlid('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->foreignUlid('hardware_part_id')->nullable()->constrained('hardware_parts')->nullOnDelete();

            $table->string('summary');
            // What to do, in the words somebody standing in the aisle needs.
            $table->text('instructions');
            $table->string('state', 16);

            $table->foreignUlid('requested_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // The person who walked to the rack. A name, because they work
            // for the datacenter and have no account here.
            $table->string('technician')->nullable();

            // What was taken out and what went in. Typed by whoever was
            // standing there, which is the only moment either is known.
            $table->string('old_serial', 191)->nullable();
            $table->string('new_serial', 191)->nullable();

            // What they saw. The sentence the whole record exists for.
            $table->text('outcome')->nullable();
            // A pointer, never a file.
            $table->string('evidence', 2048)->nullable();

            // §11's physical-access window.
            $table->foreignUlid('network_change_id')->nullable()->constrained('network_changes')->nullOnDelete();

            $table->timestamps();

            // The screen: what is still open, oldest first.
            $table->index(['organization_id', 'state', 'requested_at']);
            $table->index('rack_id');
            $table->index('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_hands_tasks');
    }
};
