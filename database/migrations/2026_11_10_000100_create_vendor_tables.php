<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who we buy from, and what we agreed (§24).
 *
 * Rows an operator types. No API tells this platform what a transit contract
 * costs or when the cPanel licences renew, which puts these in the same
 * family as the DCIM spine and cost entries — and makes them the part of
 * Phase J that can be finished without a provider ever answering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('kind', 32);

            // How to reach somebody there. A contract that ends on a Sunday
            // is a telephone number somebody needs at the weekend.
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 64)->nullable();

            // Our number with them, which is the first thing any support
            // conversation asks for.
            $table->string('account_reference')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'kind']);
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vendor_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('reference')->nullable();
            $table->string('term', 16);

            /*
             * Money is integer minor units and an ISO code (non-negotiable 4),
             * here as everywhere. A supplier's price is not this platform's
             * own currency and must not be assumed into it.
             */
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount_minor')->default(0);

            $table->date('starts_on')->nullable();

            /*
             * Nullable, and the null is a real answer: a rolling agreement
             * with no end date exists, and inventing one would put a date on
             * the expiry list that nobody agreed to. A contract with no end
             * simply never appears on it.
             */
            $table->date('ends_on')->nullable();

            /*
             * Whether it renews itself. This changes **what the alert means**
             * rather than whether it fires: a contract that renews itself is
             * not a crisis and is still the last moment to leave, and one
             * that does not is a service that stops.
             */
            $table->boolean('auto_renews')->default(false);

            // How much notice they want. The date that actually matters on an
            // auto-renewing contract is the last day to say no, not the end.
            $table->unsignedSmallInteger('notice_days')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'ends_on']);
            $table->index(['organization_id', 'vendor_id']);
        });

        Schema::table('cost_entries', function (Blueprint $table): void {
            /*
             * A pointer, never a copy. A contract is what was **agreed** and a
             * cost entry is what a month was **charged**; the same money in
             * both as two rows nobody reconciles is the figure somebody would
             * quote. Nullable for ever, because a cost can legitimately have
             * no contract behind it — the rent, or a one-off nobody papered.
             */
            $table->ulid('contract_id')->nullable()->after('vendor');
            $table->index('contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('cost_entries', function (Blueprint $table): void {
            $table->dropIndex(['contract_id']);
            $table->dropColumn('contract_id');
        });

        Schema::dropIfExists('contracts');
        Schema::dropIfExists('vendors');
    }
};
