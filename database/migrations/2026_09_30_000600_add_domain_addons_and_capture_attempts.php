<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns two list screens were asked to show and could not.
 *
 * **Domain addons.** A registrar sells DNS management, email forwarding and
 * identity protection alongside the name itself, and an operator looking at
 * a domain needs to know which of them this customer is paying for. Until
 * now only `whois_privacy` existed, which is the *state at the registry*
 * rather than the thing that was sold — a domain can have privacy on
 * because the TLD includes it and nobody bought anything.
 *
 * `order_type` records how this row came to exist: registered, transferred
 * in, or renewed into existence by the sweep. It cannot be derived later —
 * the order line that said so may be gone, and a transfer looks exactly
 * like a registration once it has completed.
 *
 * **Last capture attempt.** An invoice screen that says "unpaid" without
 * saying when we last tried to take the money makes an operator guess
 * whether the gateway is broken or the card is. The attempt is recorded
 * whether or not it worked, which is the whole point: a successful one
 * makes the invoice paid and needs no column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            // What was sold, not what the registry happens to be doing.
            $table->boolean('dns_management')->default(false)->after('whois_privacy');
            $table->boolean('email_forwarding')->default(false)->after('dns_management');
            $table->boolean('id_protection')->default(false)->after('email_forwarding');

            // A premium name is priced by the registry rather than by the
            // TLD's own table, and an operator needs to know before they
            // quote a renewal.
            $table->boolean('is_premium')->default(false)->after('id_protection');

            // How this row came to exist. Not derivable after the fact.
            $table->string('order_type', 16)->default('register')->after('status');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('last_capture_at')->nullable()->after('paid_at');
            $table->string('last_capture_outcome', 32)->nullable()->after('last_capture_at');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn([
                'dns_management',
                'email_forwarding',
                'id_protection',
                'is_premium',
                'order_type',
            ]);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['last_capture_at', 'last_capture_outcome']);
        });
    }
};
