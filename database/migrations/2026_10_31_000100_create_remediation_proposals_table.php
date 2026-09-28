<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What could be done about a difference, and who agreed to it (§22).
 *
 * **A proposal is a record before it is an action**, the rule `network_changes`
 * set: who asked, why, what exactly, who agreed, what happened. The platform
 * is the one that asks here, which is the only difference — and it is why
 * `proposed_by` is nullable rather than a staff user.
 *
 * **`finding_class` is copied onto the row**, and that is the point of the
 * whole table. A proposal is about a finding as it was when it was written;
 * a finding that has changed since is a proposal nobody agreed to, and
 * applying it would put yesterday's reading onto today's account. It is
 * `network_changes`' fingerprint check, for a comparison rather than a
 * device — and the second time this product has needed the idea, which is
 * what makes it a pattern rather than a precaution.
 *
 * **The outcome is stored, not derived.** A proposal that was applied and
 * then undone by hand must still read as applied: what the row says is what
 * happened, and what is true now is the finding's business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remediation_proposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->foreignUlid('reconciliation_finding_id')
                ->constrained('reconciliation_findings')
                ->cascadeOnDelete();

            $table->string('action', 32);
            $table->string('state', 16);

            // What the finding said when this was written. A proposal about
            // a finding that has moved is a proposal nobody agreed to.
            $table->string('finding_class', 16);

            // Null when the platform proposed it, which is the ordinary case.
            $table->foreignUlid('proposed_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('staff_users')->nullOnDelete();

            // Why somebody said yes or no. Read by whoever asks afterwards.
            $table->text('reason')->nullable();
            // What happened when it ran, sanitised. Never a translated
            // sentence: a message stored in the language of whichever run
            // wrote it is one the next operator cannot read.
            $table->text('outcome')->nullable();

            $table->timestamp('proposed_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            // One open proposal per finding. A second would be two people
            // approving different things about one account.
            $table->index(['organization_id', 'state']);
            $table->index(['reconciliation_finding_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remediation_proposals');
    }
};
