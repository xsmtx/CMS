<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work somebody planned, announced, and will be blamed for anyway (§16).
 *
 * **There is no state column, and that is the design.** Whether a window is
 * running is a question about its own two timestamps, asked at the moment
 * somebody asks it — so a scheduler that was down for three hours does not
 * leave a window "scheduled" while it is plainly happening, and does not
 * leave one "active" three days after it ended. `access_grants` made the same
 * call in Phase C for the same reason, and `phase-d-plan.md` §3's sketch of a
 * `state` column is corrected here rather than followed.
 *
 * What is *not* derivable from a clock is somebody calling it off, so
 * `cancelled_at` is a column. A cancelled window suppresses nothing and is
 * still a record that it was planned, because "why did nobody warn us" and
 * "we warned you and then called it off" are different conversations.
 *
 * **`node_keys` may be empty, and empty means everything.** A window for the
 * whole installation is the ordinary case — a network maintenance, a
 * datacentre power test — and forcing an operator to enumerate four hundred
 * machines to express it is how a feature goes unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('title');
            $table->text('body')->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('cancelled_at')->nullable();

            /*
             * Whether customers are told. Off by default, like an incident:
             * a window is public because somebody decided to publish it, and
             * "we are replacing a failed disk in rack 4" is not a sentence
             * every seller wants on their status page.
             */
            $table->boolean('is_public')->default(false);

            // Node keys, as the graph knows them. Empty means the whole
            // installation.
            $table->json('node_keys');

            $table->foreignUlid('created_by')->nullable()->constrained('staff_users')->nullOnDelete();

            $table->timestamps();

            // The sweep asks "which windows cover now", which is a range over
            // both ends.
            $table->index(['organization_id', 'starts_at', 'ends_at']);
        });

        /*
         * `alerts.suppressed_by` already exists, nullable and deliberately
         * unconstrained: it is a record of which window held a notification,
         * and it must survive the window being tidied away years later. The
         * alerting migration wrote it in Phase D's first increment and this
         * is the table it finally points at.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
    }
};
