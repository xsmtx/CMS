<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guarded change may be about something other than a device (§25).
 *
 * "Show plans/diffs and require approval to apply" is `network_changes`
 * described in nine words, so Terraform gets this column rather than a second
 * table: who asked, why, which ticket, the exact diff, who agreed and what the
 * thing said afterwards are identical questions, and two tables answering them
 * would be two queues an operator has to remember to look at.
 *
 * **`device` is the default and every existing row keeps it**, which is the
 * whole point of a default here: the column is being added to a table that
 * already holds somebody's change history, and a backfill that guessed would
 * be rewriting what happened.
 *
 * `workspace_ref` is the revision a plan was asked for — a branch, a tag or a
 * commit. It is nullable and the null is a real answer: "whatever the
 * workspace tracks" is the ordinary case, and inventing `main` would be this
 * platform planning code nobody named.
 *
 * `plan_reference` is the id of the plan the adapter produced and kept. The
 * plan itself is a binary artifact on somebody's runner and does not belong in
 * this database; holding the id is what makes "apply exactly the plan that was
 * approved" mean something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_changes', function (Blueprint $table): void {
            $table->string('change_target', 24)->default('device')->after('resource_node_id');
            $table->string('workspace_ref')->nullable()->after('intended');
            $table->string('plan_reference')->nullable()->after('workspace_ref');
        });
    }

    public function down(): void
    {
        Schema::table('network_changes', function (Blueprint $table): void {
            $table->dropColumn(['change_target', 'workspace_ref', 'plan_reference']);
        });
    }
};
