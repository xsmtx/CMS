<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three answers a customer is allowed to give about their own billing.
 *
 * WHMCS puts about eight switches on a client record. These are the three
 * this platform can actually honour, and each one is read by code that
 * exists: `send_overdue_notices` by the notify steps in
 * `RunDunningSequence`, `automatic_suspension` by its suspend and terminate
 * steps, `separate_invoices` by the grouping in `GenerateRenewalInvoices`.
 *
 * The rest of that list is deliberately absent. A late-fee switch with no
 * late-fee engine behind it, or a single sign-on switch with no single
 * sign-on, is a promise to an operator that nothing keeps — and the
 * operator only finds out when a customer asks why they were charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // Defaults chosen so that an existing row keeps behaving
            // exactly as it did yesterday: everyone is chased, everyone can
            // be suspended, nobody is invoiced line by line.
            $table->boolean('send_overdue_notices')->default(true)->after('marketing_opt_in');
            $table->boolean('automatic_suspension')->default(true)->after('send_overdue_notices');
            $table->boolean('separate_invoices')->default(false)->after('automatic_suspension');
        });

        // `information_required` is 20 characters and the column was 16.
        // A status that cannot be stored is a status that does not exist,
        // and MariaDB says so only at the moment somebody sets it.
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('status', 32)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['send_overdue_notices', 'automatic_suspension', 'separate_invoices']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('status', 16)->default('pending')->change();
        });
    }
};
