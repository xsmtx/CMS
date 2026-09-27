<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is wrong with a zone, for as long as it is wrong (§8).
 *
 * **The same shape as `alerts`, and deliberately.** A finding is raised when
 * a check first fails, its `last_seen_at` moves while it keeps failing, and
 * it is **cleared** rather than deleted when somebody fixes the zone. An
 * operator asking "when did we fix that" gets an answer, and a list that only
 * ever grew would be a list nobody reads.
 *
 * `cleared_token` exists for the reason `alerts.dedupe_token` does: MariaDB
 * treats nulls in a unique index as distinct, so a key ending in `cleared_at`
 * would allow two open findings for one check and look as though it did not.
 *
 * **A finding belongs to a domain, not to a zone name.** The zone is the
 * name; the domain is the row this installation holds, which is what carries
 * the customer. A zone an adapter is authoritative for but nobody here has
 * registered produces no findings at all — core has nothing to attribute them
 * to and nobody to tell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zone_findings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('domain_id')->constrained('domains')->cascadeOnDelete();

            $table->string('check', 32);
            $table->string('severity', 16);
            // Which adapter found it. Two providers holding one zone is a
            // real configuration, and "the old provider still says this" is
            // exactly the finding somebody wants during a migration.
            $table->string('source', 64);

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('cleared_at')->nullable();
            // Empty while open, the finding's own id once cleared.
            $table->string('cleared_token', 32)->default('');

            $table->timestamps();

            $table->unique(['domain_id', 'check', 'source', 'cleared_token'], 'zone_findings_open_unique');
            $table->index(['organization_id', 'cleared_at', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_findings');
    }
};
