<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An addon a customer is paying for, on a service they are running.
 *
 * Until now an addon existed only as an order line: a record of what was
 * bought once, with no renewal date, no status and nothing to suspend. That
 * is enough for the first invoice and nothing after it — a customer with
 * ten gigabytes of extra backup space renews it every month, and an order
 * line cannot express that.
 *
 * The row is a **copy**, exactly as a service is
 * ([ADR 0021](../../docs/adr/0021-order-lines-copy-the-catalog.md)). The
 * name and every amount are written here when the order is paid, so an
 * addon repriced next March does not change what this customer pays.
 *
 * `order_item_id` is unique, which is what makes creation idempotent: an
 * order paid twice — a webhook replayed, an operator recording a transfer
 * a webhook then confirms — finds the row that exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_addons', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            // The service it hangs off. An addon without one is not a thing
            // anybody ordered.
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();

            $table->foreignUlid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUlid('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();

            // What it was in the catalog when it was bought. Nullable
            // because the catalog entry may be retired later, and a
            // retired product must not take a paying customer's row away.
            $table->foreignUlid('addon_id')->nullable()->constrained('addons')->nullOnDelete();

            $table->string('status', 32)->default('pending')->index();

            // The copy.
            $table->string('name', 191);
            $table->string('billing_cycle', 24)->nullable();
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('recurring_minor')->default(0);
            $table->unsignedBigInteger('setup_minor')->default(0);
            $table->unsignedInteger('quantity')->default(1);

            $table->date('starts_on')->nullable();
            $table->date('next_due_on')->nullable();
            $table->date('renewal_invoiced_through')->nullable();
            $table->date('ends_on')->nullable();

            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('terminated_at')->nullable();

            $table->timestamps();

            // One addon per order line, whatever happens to the queue.
            $table->unique('order_item_id', 'service_addons_order_item_unique');

            // The renewal sweep's question: which addons are due and not
            // yet invoiced through that date.
            $table->index(['status', 'next_due_on'], 'service_addons_due_index');
            $table->index(['customer_id', 'status'], 'service_addons_customer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_addons');
    }
};
