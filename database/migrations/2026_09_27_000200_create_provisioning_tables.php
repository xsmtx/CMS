<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servers, services and the record of what was attempted on them.
 *
 * A service is a **copy**, not a view. The product name, the billing cycle,
 * the price and the chosen options are written onto it when it is created —
 * the same rule as an order line, one step further along. A product
 * repriced next March does not change what an existing service costs, and a
 * renamed option does not rewrite the disk quota somebody is running on.
 *
 * `external_id` is the most important column here. It is what the provider
 * calls the account, and it is the difference between an operation that can
 * safely be repeated and one that creates a second account every time a
 * worker is restarted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('name', 96);
            $table->string('slug', 96);

            // Placement belongs to the group, so an operator changes it
            // once rather than on every product.
            $table->string('placement_strategy', 32)->default('least_accounts');
            $table->string('region', 32)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'slug'], 'server_groups_slug_unique');
        });

        Schema::create('servers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('server_group_id')->nullable()->constrained('server_groups')->nullOnDelete();

            $table->string('name', 96);
            $table->string('module', 48);

            $table->string('hostname', 191);
            $table->string('ip_address', 45)->nullable();
            $table->unsignedSmallInteger('port')->default(2087);
            $table->boolean('secure')->default(true);
            $table->string('username', 96)->default('root');

            // Encrypted at rest by the model cast, hidden from array
            // conversion, and never logged. Three locks, because one of
            // them will eventually be bypassed by a new code path.
            $table->text('secret')->nullable();

            $table->string('status', 24)->default('active');
            $table->string('region', 32)->nullable();

            // Zero means no limit, which is a real answer for a node an
            // operator is sizing by hand.
            $table->unsignedInteger('max_services')->default(0);
            $table->unsignedSmallInteger('weight')->default(1);

            $table->string('nameservers', 191)->nullable();

            $table->string('health', 24)->default('unknown');
            $table->string('health_message', 191)->nullable();
            $table->timestamp('health_checked_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status'], 'servers_status_index');
            $table->index(['server_group_id', 'status'], 'servers_group_index');
        });

        Schema::create('services', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            // The line that bought it. Nulled rather than cascaded: losing
            // an order must not lose the record of what is running.
            $table->foreignUlid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUlid('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->foreignUlid('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->string('module', 48)->nullable();

            $table->string('status', 24)->default('pending');

            // The copy. Nothing below is read back through the catalog.
            $table->string('name', 191);
            $table->string('package', 96)->nullable();
            $table->string('billing_cycle', 24)->nullable();
            $table->char('currency_code', 3);
            $table->bigInteger('recurring_minor')->default(0);
            $table->bigInteger('setup_minor')->default(0);

            $table->string('domain', 253)->nullable();
            $table->string('hostname', 253)->nullable();

            $table->string('external_id', 191)->nullable();
            $table->string('username', 96)->nullable();
            $table->text('password')->nullable();

            $table->json('configuration')->nullable();

            $table->date('starts_on')->nullable();
            $table->date('next_due_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->string('suspension_reason', 191)->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status'], 'services_status_index');
            $table->index(['customer_id', 'status'], 'services_customer_index');
            $table->index(['server_id', 'status'], 'services_server_index');
            $table->index(['status', 'next_due_on'], 'services_due_index');

            // One service per order line. The safeguard that stops a
            // replayed webhook from provisioning the same purchase twice.
            $table->unique('order_item_id', 'services_order_item_unique');
        });

        Schema::create('service_options', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();

            // Copied, like everything else on a service.
            $table->string('group_name', 96);
            $table->string('label', 191);
            $table->string('value', 191)->nullable();

            $table->unsignedSmallInteger('position')->default(0);

            $table->index('service_id', 'service_options_service_index');
        });

        Schema::create('service_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('service_id')->constrained('services')->cascadeOnDelete();

            $table->string('operation', 32);
            $table->string('outcome', 24);

            // Who asked. Null means the platform did, which is most of
            // them: a paid order provisions without anybody pressing
            // anything.
            $table->string('actor_label', 191)->nullable();

            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->string('correlation_id', 64)->nullable();

            $table->timestamp('occurred_at');

            $table->index(['service_id', 'occurred_at'], 'service_events_service_index');
        });

        Schema::table('products', function (Blueprint $table): void {
            // A product with no module is set up by hand, which is a real
            // answer rather than a missing one.
            $table->string('provisioning_module', 48)->nullable()->after('requires_domain');
            $table->foreignUlid('server_group_id')->nullable()->after('provisioning_module')
                ->constrained('server_groups')->nullOnDelete();
            $table->string('provisioning_package', 96)->nullable()->after('server_group_id');
            $table->string('auto_setup', 24)->default('on_payment')->after('provisioning_package');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('server_group_id');
            $table->dropColumn(['provisioning_module', 'provisioning_package', 'auto_setup']);
        });

        Schema::dropIfExists('service_events');
        Schema::dropIfExists('service_options');
        Schema::dropIfExists('services');
        Schema::dropIfExists('servers');
        Schema::dropIfExists('server_groups');
    }
};
