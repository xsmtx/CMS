<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of this installation's sending addresses are on a blocklist (§13).
 *
 * **The same raise-and-clear shape as `alerts` and `zone_findings`**, and for
 * the same reason: a listing is true for a while and then stops being true.
 * Deleting the row when it lifts would throw away the only record that it
 * ever happened — and "how long were we listed" is the question somebody asks
 * afterwards, usually while writing to a customer.
 *
 * `cleared_token` exists because MariaDB treats nulls in a unique index as
 * distinct, so a key ending in `cleared_at` would allow two open listings for
 * one address on one list and look as though it were doing the work.
 *
 * **The address is bytes, and the text is derived from them.** `2001:db8::1`
 * and its expanded spelling are one address, and a blocklist writes whichever
 * it likes. Matching on text would list one address twice and attribute
 * neither.
 *
 * **Attribution is taken when the listing is first seen, not when it is
 * read.** It is the same walk `AttributeReport` does for an abuse report and
 * for a DDoS event, so a listing that appeared on Tuesday belongs to whoever
 * held the address on Tuesday — even if the address has since moved.
 *
 * There is no `state` column and no delisting workflow. Asking a blocklist to
 * lift a listing is a form with a human on the other end; a column here that
 * said `requested` would be a claim this platform cannot support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reputation_listings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // The canonical text, for reading and searching. The bytes are
            // what anything compares.
            $table->string('address', 45);
            $table->binary('address_bytes', 16, true);

            // The list's own name, as it calls itself: `zen.spamhaus.org`.
            $table->string('list', 128);
            // The blocklist's own words. Never paraphrased — a delisting
            // request that quotes something the list did not say is a
            // delisting request that gets refused.
            $table->text('reason')->nullable();
            $table->string('delist_url', 2048)->nullable();

            // Whoever held the address when the listing was first seen. Null
            // is a real answer: an address of ours that no service holds.
            $table->foreignUlid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignUlid('service_id')->nullable()->constrained('services')->nullOnDelete();

            // Which adapter reported it. Two reputation sources watching one
            // address is a real configuration, and they disagree often.
            $table->string('source', 64);

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('cleared_at')->nullable();
            // Empty while open, the listing's own id once cleared.
            $table->string('cleared_token', 32)->default('');

            $table->timestamps();

            $table->unique(
                ['organization_id', 'address_bytes', 'list', 'source', 'cleared_token'],
                'reputation_listings_open_unique',
            );
            // The screen: what is listed now, worst-affected customer first.
            $table->index(['organization_id', 'cleared_at']);
            $table->index(['customer_id', 'cleared_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reputation_listings');
    }
};
