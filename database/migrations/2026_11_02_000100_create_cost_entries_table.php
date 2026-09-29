<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the provider pays somebody else (§21).
 *
 * **This is not the ledger and must never be mistaken for it.** The ledger
 * records what customers paid this provider and is append-only because a
 * financial history that can be rewritten is not a history (ADR 0024). A
 * cost entry is a *statement* — somebody typing in what a server costs — and
 * it is edited when the price changes, like any other setting.
 *
 * **Core ships no allocation.** The row carries the strategy an operator
 * chose, so the figure it produces can say which arithmetic made it. A
 * margin whose reasoning cannot be checked is a margin somebody will believe
 * when it is wrong.
 *
 * **`starts_on` and `ends_on` bound it in time**, because a server bought in
 * June did not cost anything in May and a contract that ended in September
 * must stop appearing in October. Without them the first month a provider
 * ran this report would charge every historical month with today's estate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // What an operator calls it: "Hetzner AX102 — web-7".
            $table->string('label', 191);
            $table->string('vendor', 191)->nullable();

            $table->string('scope', 16);
            // Which server, which product. Null is the installation itself,
            // which is the one scope with nothing to name.
            $table->string('subject_type', 191)->nullable();
            $table->ulid('subject_id')->nullable();

            $table->string('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->string('period', 16);

            $table->string('strategy', 16);
            // Only for the weighted strategy. A `MetricKind` value, because
            // the graph is where the weights come from.
            $table->string('metric', 64)->nullable();

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'scope']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_entries');
    }
};
