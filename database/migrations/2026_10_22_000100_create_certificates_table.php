<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The certificate fleet (§8).
 *
 * **Discovered, never issued here.** Core does not run ACME: issuing is a
 * provisioning module's job, and reading what is actually deployed is a
 * different and much smaller promise. What this table answers is the question
 * an operator has at nine on a Monday — what expires in the next fortnight,
 * what is served under the wrong name, whose customer is affected — and none
 * of that needs the private key.
 *
 * **The fingerprint is the identity, not the common name.** One name is
 * served by four certificates over a year and two names are served by one; a
 * row keyed on the name would collapse the renewals into each other and lose
 * the very history somebody is looking for when a renewal silently failed.
 * `(source, fingerprint)` is unique, so a sweep that reports the same
 * certificate every five minutes updates one row.
 *
 * **`not_after` is a date this platform does not own.** It comes from the
 * certificate and is never computed, adjusted or defaulted — an expiry this
 * installation guessed would be worse than no expiry at all, because somebody
 * would act on it.
 *
 * The three links are all nullable and that is the ordinary case: a
 * certificate found on a load balancer belongs to no single service, one for
 * a customer's own domain has no node, and a wildcard covers things this
 * installation has never heard of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            /*
             * Where this was found. An adapter key, and part of the identity:
             * the same certificate deployed on two machines is two rows,
             * because "it is renewed on web-1 and stale on web-2" is exactly
             * the fact this table exists to surface.
             */
            $table->string('source', 64);
            $table->string('fingerprint', 128);

            $table->string('common_name');
            // The names it actually covers. A wildcard is one entry here and
            // the matching is done when somebody asks, not stored expanded.
            $table->json('subject_alternative_names');

            $table->string('issuer');
            $table->string('serial', 128)->nullable();

            $table->timestamp('not_before');
            $table->timestamp('not_after');

            /*
             * Whether the chain served alongside it was complete.
             *
             * Nullable, and the null means "nobody looked" rather than "it is
             * fine" — an adapter that reads a certificate off disk cannot say
             * what a client would be served, and pretending otherwise would
             * be the platform asserting something it does not know.
             */
            $table->boolean('chain_ok')->nullable();

            $table->foreignUlid('resource_node_id')->nullable()->constrained('resource_nodes')->nullOnDelete();
            $table->foreignUlid('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->foreignUlid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->timestamp('discovered_at');
            // Closed rather than deleted when a sweep stops seeing it, like a
            // retired graph node: "this certificate was on that machine in
            // March" is a question somebody asks after an outage.
            $table->timestamp('retired_at')->nullable();

            $table->timestamps();

            $table->unique(['source', 'fingerprint']);
            // The question the screen and the alert rule both ask.
            $table->index(['organization_id', 'retired_at', 'not_after']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
