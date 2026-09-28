<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What every backup source says it is protecting (§12).
 *
 * **Discovered, never run from here.** Core does not take backups — a PHP
 * process cannot take a consistent snapshot, and one that produced an
 * inconsistent snapshot would be worse than none. What this table is for is
 * the question no backup vendor can answer: Veeam knows what it backs up,
 * and only this installation knows what exists.
 *
 * **There is no state column, deliberately.** Whether a protection is stale
 * is a question about `last_good_at` and the clock, asked when somebody looks
 * — and how old is too old is the operator's threshold on an alert rule,
 * because a nightly job and a weekly one do not agree. A stored state would
 * be a state some scheduler run decided, drifting from the moment it was
 * written. Phase C's `access_grants` made the same choice for the same
 * reason.
 *
 * **`last_good_at` is not `last_run_at`.** A job that ran at 02:00 and failed
 * has a recent run and an old last-good copy, and it is the second that says
 * what a customer would get back. A screen sorted on the first would put the
 * most broken thing on the estate at the top of the list looking fine.
 *
 * **The service link is nullable and both nulls mean something.** A resource
 * no service matches is either a backup job for a customer who left — worth
 * knowing, and worth money — or a name this platform spells differently. Both
 * are findings; neither is a reason to drop the row.
 *
 * `(source, resource_key)` is the identity. The same account protected by two
 * sources is two rows on purpose: "JetBackup has it and Veeam stopped" is the
 * fact somebody needs during a migration between them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_protections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('source', 64);
            // The source's own identifier, stable across runs.
            $table->string('resource_key', 191);
            // What the source calls it, verbatim. A normalised copy would
            // disagree with the vendor's own console, which is the screen
            // somebody opens next.
            $table->string('resource_name');
            // The source's own word for what it is: `vm`, `account`,
            // `database`. Free text, because core has no business telling a
            // vendor what a protected object is.
            $table->string('resource_type', 64)->nullable();
            $table->string('repository')->nullable();

            $table->string('last_outcome', 16);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_good_at')->nullable();
            // Null is "the source did not say", never zero: storing a zero
            // would be this platform asserting a customer has nothing to
            // restore from.
            $table->unsignedInteger('restore_points')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->foreignUlid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            // Closed rather than deleted when a source stops naming it: the
            // date something left the backup job is the answer to the only
            // question anybody asks afterwards.
            $table->timestamp('retired_at')->nullable();

            $table->timestamps();

            $table->unique(['source', 'resource_key']);
            // The coverage screen and the alert rule ask the same question.
            $table->index(['organization_id', 'retired_at', 'last_good_at']);
            $table->index(['service_id', 'retired_at']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_protections');
    }
};
