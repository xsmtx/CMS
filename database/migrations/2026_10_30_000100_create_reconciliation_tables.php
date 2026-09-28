<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this platform believes, against what a provider reports (§22).
 *
 * **The same raise-and-clear shape as `alerts`, `zone_findings` and
 * `reputation_listings`**, and for the same reason: a difference is true for
 * a while and then somebody fixes it. Deleting the row would throw away the
 * only record that it happened, and "how long was that account suspended
 * without us knowing" is a question somebody asks afterwards.
 *
 * `cleared_token` exists because MariaDB treats nulls in a unique index as
 * distinct, so a key ending in `cleared_at` would allow two open findings for
 * one resource and look as though it were doing the work. It is the fourth
 * table in this product to need it, which is why it is a habit now.
 *
 * **`expected` and `found` are stored as text, not as enums.** A provider's
 * own word is the evidence — "the panel said `suspended`" is a different
 * sentence from "we mapped it to suspended" — and a column of this
 * platform's vocabulary would quietly throw away the only thing that
 * explains a finding six weeks later.
 *
 * **A dismissal is a row, never a flag on the finding.** "This one is
 * deliberate" has an author, a reason and usually a date it stops being
 * true; a boolean would record none of those, and an operator inheriting the
 * queue would find a hundred dismissed findings and nobody to ask.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_findings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            /*
             * What it is about. A morph rather than four nullable foreign
             * keys, because an orphan has no row here at all — that is what
             * makes it an orphan — and a key that must be null for one whole
             * class is a key that proves nothing.
             */
            $table->string('subject_type', 191)->nullable();
            $table->ulid('subject_id')->nullable();
            // What an operator reads before they click: `web-7 / acme-hosting`.
            $table->string('subject_label', 191);

            // The provider's own identifier, which is what makes an orphan
            // addressable at all.
            $table->string('remote_key', 191)->nullable();

            $table->string('resource', 64);
            $table->string('class', 16);
            $table->string('field', 64)->nullable();

            // The provider's own words on one side and ours on the other.
            $table->string('expected', 191)->nullable();
            $table->string('found', 191)->nullable();
            // Everything else the comparison had in hand, for the drawer.
            $table->json('detail')->nullable();

            // Which adapter said so. Two adapters can see one machine, and
            // they disagree more often than anybody expects.
            $table->string('source', 64);

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('cleared_at')->nullable();
            // Empty while open, the finding's own id once cleared.
            $table->string('cleared_token', 32)->default('');

            $table->timestamps();

            $table->unique(
                ['organization_id', 'source', 'resource', 'subject_id', 'remote_key', 'field', 'cleared_token'],
                'reconciliation_findings_open_unique',
            );
            // The queue: what is open, worst first.
            $table->index(['organization_id', 'cleared_at', 'class']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('reconciliation_dismissals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            /*
             * Keyed the way a finding is keyed rather than by the finding's
             * own id, because the point of a dismissal is to survive the
             * finding being cleared and raised again by the next sweep.
             */
            $table->string('source', 64);
            $table->string('resource', 64);
            $table->ulid('subject_id')->nullable();
            $table->string('remote_key', 191)->nullable();
            $table->string('field', 64)->nullable();

            // Who said so, and why. Both required in practice: a dismissal
            // with no reason is one nobody inheriting the queue can judge.
            $table->foreignUlid('dismissed_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->text('reason');

            $table->timestamp('dismissed_at');
            // When it stops applying. Null is "until somebody undoes it",
            // which is a real answer for a machine built by hand on purpose.
            $table->timestamp('until')->nullable();

            $table->timestamps();

            $table->unique(
                ['organization_id', 'source', 'resource', 'subject_id', 'remote_key', 'field'],
                'reconciliation_dismissals_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_dismissals');
        Schema::dropIfExists('reconciliation_findings');
    }
};
