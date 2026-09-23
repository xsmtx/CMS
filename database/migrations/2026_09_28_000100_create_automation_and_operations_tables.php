<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What runs on its own, and what happened when it did.
 *
 * Three ideas shape these tables.
 *
 * **A run is a record.** `automation_runs` exists so an operator can answer
 * "why was this customer suspended on Tuesday" and, harder, "why was this
 * customer *not* suspended". A counted run that changed nothing is still
 * written, because "examined 60, changed 0" is the answer to the second
 * question and a log file that rotated is not.
 *
 * `automation_run_items` holds only the rows a run **changed or failed
 * on**. Writing a line for every row examined would turn a nightly sweep
 * of ten thousand services into ten thousand rows nobody reads; the counts
 * on the run cover the rest.
 *
 * Neither table carries an organization. A run sweeps the whole
 * installation and touches rows in many of them, so stamping it with one
 * would be a lie — the same reason `failed_jobs` has none.
 *
 * **An operation is visible before it finishes.** `operations` is the
 * handoff's Background Operations Center: the row is written before the job
 * is dispatched, so an operation that never reaches a worker is still on
 * the screen. `needs_intervention` is the honest end state for something no
 * amount of retrying will fix.
 *
 * **Dunning is a sequence an operator edits.** `dunning_steps` is rows
 * rather than constants, because the days are the easy part and the shape
 * is what differs between businesses. A sequence with no suspend step is a
 * valid configuration. `invoice_dunning_steps` records which step has
 * already run against which invoice, which is what makes the whole sequence
 * safe to re-run — the question becomes "which owed invoices have not had
 * step 3" rather than "which invoices are exactly seven days old".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('task', 64);
            $table->string('status', 24);

            // Who asked. Null for the scheduler, which is most of the time.
            $table->foreignUlid('triggered_by')->nullable()
                ->constrained('staff_users')->nullOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->unsignedInteger('examined')->default(0);
            $table->unsignedInteger('changed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);

            $table->string('correlation_id', 64)->nullable();

            // Sanitised before it is written. A provider error is the most
            // likely place a credential ends up in a database column.
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['task', 'started_at'], 'automation_runs_task_index');
            $table->index('status', 'automation_runs_status_index');
        });

        Schema::create('automation_run_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('automation_runs')->cascadeOnDelete();

            // Morph rather than a column per context: a run item points at a
            // service, a domain or an invoice, and the set grows.
            $table->string('subject_type', 96)->nullable();
            $table->ulid('subject_id')->nullable();
            $table->string('subject_label', 191)->nullable();

            $table->string('outcome', 24);
            $table->text('message')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['run_id', 'outcome'], 'automation_run_items_run_index');
            $table->index(['subject_type', 'subject_id'], 'automation_run_items_subject_index');
        });

        Schema::create('operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('type', 64);
            $table->string('state', 24);

            $table->string('subject_type', 96)->nullable();
            $table->ulid('subject_id')->nullable();
            $table->string('subject_label', 191)->nullable();

            $table->string('actor_type', 96)->nullable();
            $table->ulid('actor_id')->nullable();

            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->unsignedTinyInteger('progress')->default(0);

            $table->string('correlation_id', 64)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();

            $table->text('error')->nullable();

            $table->boolean('needs_intervention')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUlid('resolved_by')->nullable()
                ->constrained('staff_users')->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'state'], 'operations_state_index');
            $table->index(['subject_type', 'subject_id'], 'operations_subject_index');
            // The retry sweep's question, asked every few minutes.
            $table->index('next_attempt_at', 'operations_next_attempt_index');
        });

        Schema::create('dunning_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Signed on purpose: negative is before the due date, which is
            // where the reminder that actually gets paid lives.
            $table->smallInteger('offset_days');

            $table->string('action', 24);

            // Which of `NotificationEvent`'s members a notify step raises.
            $table->string('event', 64)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['organization_id', 'position'], 'dunning_steps_order_index');
        });

        Schema::create('invoice_dunning_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->foreignUlid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUlid('step_id')->constrained('dunning_steps')->cascadeOnDelete();

            $table->timestamp('ran_at');

            // The whole point of the table: a step runs once per invoice,
            // and the database says so rather than the code remembering to.
            $table->unique(['invoice_id', 'step_id'], 'invoice_dunning_steps_unique');
        });

        Schema::create('platform_state', function (Blueprint $table): void {
            // Small pieces of installation state that have to outlive a
            // cache flush: the scheduler heartbeat, and whether maintenance
            // mode is on. Not a settings store — those arrive with the
            // settings screen and belong per organization.
            $table->string('key', 96)->primary();
            $table->json('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // What a renewal invoice is for. Without it, a paid renewal has no
        // way back to the service whose date it is supposed to advance, and
        // the description text is prose that was already a copy.
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->string('subject_type', 96)->nullable()->after('order_item_id');
            $table->ulid('subject_id')->nullable()->after('subject_type');

            $table->index(['subject_type', 'subject_id'], 'invoice_items_subject_index');
        });

        // "We have invoiced this service up to here." The renewal sweep's
        // guard against invoicing the same term twice, and the reason it is
        // safe to run the task again five minutes later.
        Schema::table('services', function (Blueprint $table): void {
            $table->date('renewal_invoiced_through')->nullable()->after('next_due_on');
        });

        Schema::table('domains', function (Blueprint $table): void {
            $table->date('renewal_invoiced_through')->nullable()->after('expires_on');

            // The expiry notice already sent, and the expiry date it was
            // about. Two columns rather than one so that a domain whose
            // expiry moves gets a fresh set of notices without anything
            // having to remember to reset a flag.
            $table->unsignedSmallInteger('expiry_notified_days')->nullable();
            $table->date('expiry_notified_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn(['renewal_invoiced_through', 'expiry_notified_days', 'expiry_notified_for']);
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('renewal_invoiced_through');
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropIndex('invoice_items_subject_index');
            $table->dropColumn(['subject_type', 'subject_id']);
        });

        Schema::dropIfExists('platform_state');
        Schema::dropIfExists('invoice_dunning_steps');
        Schema::dropIfExists('dunning_steps');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('automation_run_items');
        Schema::dropIfExists('automation_runs');
    }
};
