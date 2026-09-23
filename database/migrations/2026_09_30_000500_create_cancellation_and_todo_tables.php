<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things an operator's day is made of that the platform could not
 * record: a customer asking to stop, and a note to come back to something.
 *
 * **A cancellation is a request, not a status.** The service already has
 * `cancel_pending`, which says *that* it is going away. It cannot say who
 * asked, when, why, or whether they wanted it off today or at the end of
 * the term they have paid for — and those four facts are the entire
 * substance of a cancellation queue. A status column cannot be asked "show
 * me everybody cancelling because we were too expensive".
 *
 * The request and the status live alongside each other deliberately: the
 * request is the paperwork and the service's own status is the truth about
 * what is running, which is the same division as an order and the services
 * it created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            // The thing being cancelled. Cascades, because a request to
            // cancel something that no longer exists is not a queue item.
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();

            // Immediately, or at the end of the term they have paid for.
            // The default is the end of the term: a customer who did not
            // say otherwise has bought until then.
            $table->string('type', 24)->default('end_of_term');
            $table->string('status', 24)->default('pending')->index();

            // Why. Free text on purpose: a fixed list of reasons produces a
            // column of "Other" and teaches nobody anything.
            $table->text('reason')->nullable();

            // Who asked. A customer in the portal, or an operator on the
            // telephone on their behalf — both are real and the queue reads
            // differently depending which.
            $table->string('requested_by_type', 191)->nullable();
            $table->string('requested_by_id', 64)->nullable();
            $table->string('requested_by_label', 191)->nullable();

            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('completed_by', 191)->nullable();

            $table->timestamps();

            // The queue's own question: what is still waiting, oldest first.
            $table->index(['status', 'requested_at'], 'cancellation_requests_queue_index');
            $table->index(['service_id', 'status'], 'cancellation_requests_service_index');
        });

        Schema::create('todo_items', function (Blueprint $table): void {
            // A staff member's note to come back to something. Owned by an
            // organization like everything else, so a reseller's list is
            // theirs and a provider does not read it.
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('title', 191);
            $table->text('body')->nullable();

            $table->string('status', 24)->default('pending')->index();
            $table->date('due_on')->nullable();

            // Nullable: a list where everything must be assigned before it
            // can be written down is a list nobody writes anything down in.
            $table->foreignUlid('assigned_to')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('staff_users')->nullOnDelete();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_on'], 'todo_items_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_items');
        Schema::dropIfExists('cancellation_requests');
    }
};
