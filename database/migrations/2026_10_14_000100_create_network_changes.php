<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A change to a device's configuration, as a record before it is an action
 * (§6).
 *
 * **The row is the point.** §6 asks for requester, approver, reason, ticket,
 * the exact diff and the result to be persisted, and that is not paperwork:
 * the most consequential thing this platform can do is push a configuration to
 * a firewall, and the only thing that makes that acceptable is that somebody
 * can read afterwards who asked, who agreed, what changed and what the device
 * said. A workflow whose record were written after the fact would be a
 * workflow whose record was missing exactly when it mattered.
 *
 * **`fingerprint_before` is the safety.** The diff an operator read was built
 * against the device at request time; the apply recomputes it against the
 * device *now* and refuses when it has moved, because a diff applied an hour
 * later is a diff against a box somebody else has edited. Storing the
 * fingerprint is what makes that check cheap.
 *
 * **`backup` holds a configuration, which holds secrets.** SNMP communities,
 * RADIUS keys, pre-shared keys and hashed passwords all live in a device
 * configuration. The column is `longText` and nothing renders it: it exists so
 * a rollback has something to put back, and `SecretRedactor` is asked before
 * anything from these columns reaches a log.
 *
 * `operation_id` is nullable and filled when the job is dispatched, because
 * `WatchedDispatch` opens the operation *before* the job reaches a worker —
 * a change that never reached one is the failure nobody sees (ADR 0032).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // The device, as a graph node rather than as a row in a devices
            // table: discovery is what knows a device exists, and a second
            // table would be a second answer that eventually disagreed.
            $table->foreignUlid('resource_node_id')->constrained('resource_nodes')->cascadeOnDelete();

            $table->string('state', 24);

            $table->foreignUlid('requested_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('staff_users')->nullOnDelete();

            $table->string('summary');
            $table->text('reason');
            // Free text, not a foreign key: the ticket may be in this platform
            // or in whatever the operator's customer uses, and a column that
            // could only hold one of those is a column half of them cannot use.
            $table->string('ticket')->nullable();
            $table->text('decision_note')->nullable();

            $table->boolean('requires_approval')->default(true);

            // The whole intended configuration, and the one the device had
            // when it was requested. Both are needed to show a diff that is
            // the same diff the approver agreed to.
            $table->longText('intended');
            $table->longText('baseline')->nullable();
            $table->longText('diff')->nullable();
            $table->string('fingerprint_before', 64)->nullable();

            // What went back on if it had to.
            $table->longText('backup')->nullable();
            $table->timestamp('backed_up_at')->nullable();

            $table->text('result')->nullable();

            $table->foreignUlid('operation_id')->nullable()->constrained('operations')->nullOnDelete();

            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            // The queue an operator opens this screen with: what is waiting on
            // somebody, newest first.
            $table->index(['organization_id', 'state', 'created_at']);
            $table->index(['resource_node_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_changes');
    }
};
