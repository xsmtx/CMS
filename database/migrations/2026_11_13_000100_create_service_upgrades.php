<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A request to move a service between plans.
 *
 * The WHMCS screen with no answer in this product until now:
 * `ProvisioningModule::changePackage()` has existed since Phase 6 and nothing
 * ever called it on a service, so an account could be created and terminated
 * and never moved.
 *
 * **A record before it is an action**, like a network change and a remote-hands
 * task: who asked, from what to what, what it cost, which invoice it raised
 * and what the provider said. An upgrade that happened with no record of the
 * arithmetic is one nobody can answer a billing question about six weeks
 * later.
 *
 * **The amounts are frozen on the row.** They were computed against the term
 * the service was in at the moment of asking, and that term moves — so
 * recomputing them at apply time would charge a figure nobody agreed to. It is
 * the same rule an issued invoice follows (ADR 0023), one step earlier.
 *
 * **Not an order.** An order creates services and this changes one; forcing it
 * through `CreateServicesForOrder` would mean teaching the fulfilment listener
 * to recognise a line that must not become a service. The invoice is raised
 * directly, which is what the renewal sweep and the late-fee step already do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_upgrades', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained()->cascadeOnDelete();

            $table->string('state', 24)->default('awaiting_payment');

            /*
             * What it was. Copied rather than left to the service, which is
             * about to stop saying it — the order-line rule (ADR 0021) applied
             * to the thing being changed rather than to the thing being sold.
             */
            $table->ulid('from_product_id')->nullable();
            $table->string('from_product_name');
            $table->string('from_cycle', 24);
            $table->unsignedBigInteger('from_recurring_minor')->default(0);

            $table->foreignUlid('to_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('to_product_name');
            $table->string('to_cycle', 24);
            $table->unsignedBigInteger('to_recurring_minor')->default(0);

            $table->char('currency_code', 3);

            /*
             * The arithmetic, frozen. `difference_minor` is signed because a
             * downgrade is negative and clamping it would silently keep money
             * the customer had already paid for a plan they no longer have.
             */
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->unsignedBigInteger('charge_minor')->default(0);
            $table->bigInteger('difference_minor')->default(0);
            $table->unsignedSmallInteger('days_remaining')->default(0);
            $table->unsignedSmallInteger('term_days')->default(0);

            // Whether a new term began, which the numbers alone do not say.
            $table->boolean('restarts_term')->default(false);

            // The invoice the customer pays, or the credit note a downgrade
            // writes. Nullable because a move that costs nothing raises
            // neither, which is a real answer rather than a missing one.
            $table->ulid('invoice_id')->nullable();
            $table->ulid('credit_note_id')->nullable();

            $table->foreignUlid('requested_by_staff')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignUlid('requested_by_contact')->nullable()->constrained('contacts')->nullOnDelete();

            $table->text('note')->nullable();
            $table->text('result')->nullable();

            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            // The queue an operator opens this with: what is still open,
            // newest first.
            $table->index(['organization_id', 'state', 'created_at']);
            $table->index(['service_id', 'created_at']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_upgrades');
    }
};
