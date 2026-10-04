<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational licences bought in quantity, and which machine is using one
 * (§24).
 *
 * Every vendor portal can say how many seats were bought. **Only this
 * installation knows which of its machines are running them**, because it is
 * the one holding the server list — and the difference between those two
 * numbers is the whole reason the family is worth building. A list of
 * licences somebody already has a receipt for is a spreadsheet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licence_pools', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vendor_id')->constrained()->cascadeOnDelete();

            /*
             * The paper behind the seats, when there is any. Nullable for
             * ever: licences bought on a credit card with no contract are
             * ordinary, and demanding one would make this screen unusable
             * until somebody had typed a contract nobody signed.
             */
            $table->ulid('contract_id')->nullable();

            $table->string('name');

            /*
             * The provisioning module a machine running this licence would be
             * configured with, and **nullable on purpose**.
             *
             * It is what lets the screen answer "which servers look like they
             * need one and have none" — per pool, so a cPanel licence and an
             * Imunify licence can both be about cPanel machines and each have
             * its own gap. Where it is null, core makes no claim at all and
             * that pool simply has no gap: a licence this platform cannot
             * place is better left unplaced than guessed at.
             */
            $table->string('for_module', 64)->nullable();

            // How many were bought. Zero is a real answer while somebody is
            // still negotiating.
            $table->unsignedInteger('seats')->default(0);

            // What one costs. Money is integer minor units and an ISO code
            // (non-negotiable 4), in a supplier's currency rather than ours.
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('unit_amount_minor')->default(0);

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'vendor_id']);
            $table->index(['organization_id', 'for_module']);
            $table->index('contract_id');
        });

        Schema::create('licence_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('licence_pool_id')->constrained()->cascadeOnDelete();

            /*
             * Null when the machine has left the fleet, which is the second
             * of the three differences this screen exists for: a seat still
             * being paid for, attached to something that is no longer there.
             * Cascading the delete would make that money invisible at exactly
             * the moment it starts being wasted.
             */
            $table->foreignUlid('server_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Copied at allocation, like an order line copies the catalog
             * (ADR 0021). It is the only thing left to read once the server
             * row has gone, and "a seat on something called db3" is an
             * answer where a bare id is not.
             */
            $table->string('server_name');

            /*
             * The vendor's own line-item reference, and deliberately **not a
             * licence key**. A key is a credential for somebody's production
             * panel, this platform never stores a secret it has no need to
             * read (non-negotiable 7), and nothing here would ever use one.
             */
            $table->string('reference')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            /*
             * One seat of a pool per machine. Nulls are distinct in MariaDB,
             * which is right here rather than a trap: several allocations may
             * legitimately be orphaned at once.
             */
            $table->unique(['licence_pool_id', 'server_id']);
            $table->index(['organization_id', 'licence_pool_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_allocations');
        Schema::dropIfExists('licence_pools');
    }
};
