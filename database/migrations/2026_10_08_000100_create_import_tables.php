<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bringing a legacy system across.
 *
 * Three tables, and the middle one is the important one.
 *
 * `import_runs` — one row per attempt, dry or live. A run row exists **before**
 * the job reaches a worker (ADR 0032), so an import that never started is
 * visible rather than being the failure nobody sees.
 *
 * `import_mappings` — `(source, domain, external_id) → (target_type,
 * target_id)`, unique. This is what makes an import safe to run twice, and it
 * is a table rather than a convention on purpose: matching on something like an
 * email address would silently merge two customers who share one, which is the
 * commonest way a migration loses data without anybody noticing. It outlives
 * the run that created it, because "which of my WHMCS clients came across"
 * is a question asked months later.
 *
 * `import_items` — one row per legacy row per run, with its outcome and, when
 * it failed, why. Kept rather than summarised: **no silent data loss** means an
 * operator can read the four hundred rows that did not come across and decide
 * what to do about each, and a run that only stored totals would tell them the
 * number and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('source', 32);
            $table->string('mode', 16);
            $table->string('status', 24);

            /*
             * Which domains this run was asked for. A run is allowed to be
             * partial — an operator who has already brought the customers
             * across and is now doing the invoices is the normal case — so
             * what was asked for has to be recorded, or the report cannot say
             * whether an absent domain was skipped or failed.
             */
            $table->json('domains');

            // The counts the source reported at analyse time, so the report can
            // say "4,182 of 4,190" rather than only the number that worked.
            $table->json('expected')->nullable();
            $table->json('totals')->nullable();

            $table->foreignUlid('started_by')->nullable()
                ->constrained('staff_users')->nullOnDelete();

            $table->string('correlation_id', 64)->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at'], 'import_runs_recent_index');
        });

        Schema::create('import_mappings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('source', 32);
            $table->string('domain', 32);
            $table->string('external_id', 191);

            $table->string('target_type', 191);
            $table->ulid('target_id');

            $table->timestamps();

            // The whole point. Duplicate protection and resumability are this
            // one index.
            $table->unique(
                ['organization_id', 'source', 'domain', 'external_id'],
                'import_mappings_unique',
            );

            // "What did this record come from" — asked when a figure looks
            // wrong six months later.
            $table->index(['target_type', 'target_id'], 'import_mappings_target_index');
        });

        Schema::create('import_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('run_id')->constrained('import_runs')->cascadeOnDelete();

            $table->string('domain', 32);
            $table->string('external_id', 191);
            $table->string('outcome', 16);

            // What it became, when it became anything. Null on a dry run and on
            // a failure, which is two different reasons for the same absence —
            // the outcome column is what distinguishes them.
            $table->string('target_type', 191)->nullable();
            $table->ulid('target_id')->nullable();

            /*
             * A name an operator recognises. "Client 4182" is not somebody they
             * can telephone about, and a failure report they cannot act on is a
             * failure report they will not read.
             */
            $table->string('label', 191)->nullable();
            $table->text('message')->nullable();

            $table->timestamp('created_at')->nullable();

            // The report's own query: this run's failures first.
            $table->index(['run_id', 'outcome'], 'import_items_outcome_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_items');
        Schema::dropIfExists('import_mappings');
        Schema::dropIfExists('import_runs');
    }
};
