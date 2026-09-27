<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abuse cases, their timeline, the evidence and what was done (§13).
 *
 * **A case is attributed at the moment of the report, never at the moment it
 * is read.** `subject_value` holds what the complaint named — an address, a
 * domain, an account — and `customer_id` holds who that resolved to *then*.
 * A complaint about an address last Tuesday belongs to whoever held it last
 * Tuesday: attributing it to the current holder is how an innocent customer
 * is suspended for somebody else's spam, which is the worst mistake this
 * family can make. `ip_assignments` is append-only for exactly this reason
 * (Phase C §5), and this is the second thing to read it.
 *
 * **A case with no customer is kept.** A report this platform cannot
 * attribute — an address in a range nobody recorded, a domain that is not
 * ours, a sender who is simply wrong — is still a report somebody has to
 * answer, and dropping it would drop the answer with it.
 *
 * **Evidence carries a deadline.** `retain_until` is the first column in this
 * product whose job is to make something be forgotten: a complaint holds a
 * third party's data, and keeping it for ever is a privacy decision nobody
 * made. Core stores a reference — an id, a URL, a hash, a bounded excerpt —
 * and never a mail body, a full log or a disk image.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abuse_cases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('reference', 32)->unique();
            $table->string('kind', 32);
            $table->string('state', 32);
            $table->string('severity', 16);

            /*
             * Who complained. A free string on purpose: it is a spam trap
             * operator, a bank's brand team, Spamhaus, or a person. Turning
             * that into a table would be modelling the internet.
             */
            $table->string('source', 160)->nullable();
            $table->string('external_reference', 160)->nullable();

            $table->string('summary');

            // What the complaint named, and what it resolved to at the time.
            $table->string('subject_type', 16)->nullable();
            $table->string('subject_value', 255)->nullable();

            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignUlid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUlid('domain_id')->nullable()->constrained('domains')->nullOnDelete();

            /*
             * When the complaint says it happened, which is not when it
             * arrived. Attribution is done against this, and a report about
             * last Tuesday that lands on Friday must not be attributed to
             * Friday's holder.
             */
            $table->timestamp('occurred_at');
            $table->timestamp('reported_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignUlid('closed_by')->nullable()->constrained('staff_users')->nullOnDelete();

            $table->foreignUlid('opened_by')->nullable()->constrained('staff_users')->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'state', 'reported_at']);
            $table->index(['customer_id', 'reported_at']);
            $table->index('subject_value');
        });

        Schema::create('abuse_case_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('abuse_case_id')->constrained('abuse_cases')->cascadeOnDelete();

            $table->foreignUlid('written_by')->nullable()->constrained('staff_users')->nullOnDelete();

            // The state it was at when this was written, copied on for the
            // reason an incident update copies it: the timeline has to read
            // correctly after the next three moves.
            $table->string('state', 32);
            $table->text('body');

            $table->timestamps();

            $table->index(['abuse_case_id', 'created_at']);
        });

        Schema::create('abuse_evidence', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('abuse_case_id')->constrained('abuse_cases')->cascadeOnDelete();

            $table->string('kind', 32);
            // Bounded on purpose. Anything that does not fit is a reference
            // to something that lives elsewhere, which is the rule.
            $table->text('reference');
            $table->foreignUlid('captured_by')->nullable()->constrained('staff_users')->nullOnDelete();

            $table->timestamp('captured_at');
            $table->timestamp('retain_until');

            $table->timestamps();

            // The sweep asks "what is past its deadline", which is a range
            // over one column.
            $table->index('retain_until');
            $table->index(['abuse_case_id', 'captured_at']);
        });

        Schema::create('abuse_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('abuse_case_id')->constrained('abuse_cases')->cascadeOnDelete();

            $table->string('action', 32);
            $table->string('state', 16);
            $table->text('reason');

            $table->foreignUlid('decided_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignUlid('service_id')->nullable()->constrained('services')->nullOnDelete();

            $table->text('result')->nullable();
            $table->timestamp('performed_at')->nullable();

            $table->timestamps();

            $table->index(['abuse_case_id', 'created_at']);
            // The desk's own list: what has been agreed and not yet done.
            $table->index(['organization_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abuse_actions');
        Schema::dropIfExists('abuse_evidence');
        Schema::dropIfExists('abuse_case_events');
        Schema::dropIfExists('abuse_cases');
    }
};
