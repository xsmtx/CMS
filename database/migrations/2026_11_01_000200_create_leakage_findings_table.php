<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money that quietly stopped arriving (§21).
 *
 * The fourth table in this product with the raise-and-clear shape, after
 * `alerts`, `zone_findings` and `reconciliation_findings`, and it needs
 * `cleared_token` for the same MariaDB reason: nulls in a unique index are
 * distinct, so a key ending in `cleared_at` would allow two open rows for one
 * service and look as though it were doing the work.
 *
 * **The amount is stored and it is one currency per row.** Every monetary
 * answer in this product is `MoneyByCurrency` (a list, never a number), and
 * the way a list is built is by keeping each row in the currency it happened
 * in. A total across currencies is a figure that means nothing and is exactly
 * the figure somebody would quote.
 *
 * **It is what is at stake, not what is owed.** Nobody has been invoiced, so
 * there is no debt; the figure is what would have been invoiced had anybody
 * asked. The distinction matters because this table must never be mistaken
 * for a ledger — the ledger is the truth about money that moved (ADR 0024),
 * and this is a list of money that did not.
 *
 * There is no separate dismissal table: `finding_dismissals` serves every
 * family of finding, keyed by `source`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leakage_findings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('kind', 32);

            // The service, domain, addon or payment it is about. A morph
            // because the four kinds point at four different tables, and a
            // column that must be null for three of them proves nothing.
            $table->string('subject_type', 191);
            $table->ulid('subject_id');
            $table->string('subject_label', 191);

            // Whose it is. The one question a commercial screen is opened to
            // answer, so it is a column rather than a walk at render.
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_label', 191)->nullable();

            // What is at stake, in the currency it happened in.
            $table->string('currency_code', 3);
            $table->bigInteger('amount_minor');

            // Everything else the join had in hand, for the row's second line.
            $table->json('detail')->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('cleared_at')->nullable();
            // Empty while open, the finding's own id once cleared.
            $table->string('cleared_token', 32)->default('');

            $table->timestamps();

            $table->unique(
                ['organization_id', 'kind', 'subject_id', 'cleared_token'],
                'leakage_findings_open_unique',
            );
            // The screen: what is open, largest first.
            $table->index(['organization_id', 'cleared_at', 'kind']);
            $table->index(['customer_id', 'cleared_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leakage_findings');
    }
};
