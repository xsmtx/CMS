<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access that runs out (§17).
 *
 * **There is no state column, and that is the design.** A grant is active
 * when `revoked_at` is null and `expires_at` has not passed — a question
 * about two timestamps, asked at the moment somebody uses it. A column
 * saying `active` would be a column that has to be kept true by something
 * running on time, and ADR 0031 is exactly about not believing the clock:
 * a scheduler that was down for three hours must not leave an expired grant
 * standing.
 *
 * The sweep still exists, and it writes `revoked_at` with a reason, because
 * an operator reading the list wants to see that a grant ended rather than
 * infer it from a date. But nothing depends on the sweep having run — the
 * gate asks the timestamps.
 *
 * **A grant only ever adds.** `capability` is a member of a closed enum and
 * every member is something to permit; there is no shape here for taking a
 * permission away, deliberately.
 *
 * `network_change_id` is the change that opened a hole in a firewall for this
 * grant, and `removal_change_id` is the one that closed it. Both nullable and
 * both usually null: a grant that only hands somebody a permission has no
 * device behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Who it is for, and who gave it. Never the same person, and a
            // test says so.
            $table->foreignUlid('staff_user_id')->constrained('staff_users')->cascadeOnDelete();
            $table->foreignUlid('granted_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignUlid('revoked_by')->nullable()->constrained('staff_users')->nullOnDelete();

            $table->string('capability', 64);

            // What it is for, and optionally where. A grant with no node is a
            // permission for a window; one with a node is a permission for a
            // window on one machine.
            $table->foreignUlid('resource_node_id')->nullable()->constrained('resource_nodes')->nullOnDelete();

            $table->text('reason');
            $table->string('ticket')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreignUlid('network_change_id')->nullable()
                ->constrained('network_changes')->nullOnDelete();
            $table->foreignUlid('removal_change_id')->nullable()
                ->constrained('network_changes')->nullOnDelete();

            // The question the gate asks on every request that uses one:
            // has this person got a live grant for this capability.
            $table->index(['staff_user_id', 'capability', 'revoked_at', 'expires_at'], 'access_grants_live_index');

            // And the question the sweep asks: which are past theirs.
            $table->index(['revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
    }
};
