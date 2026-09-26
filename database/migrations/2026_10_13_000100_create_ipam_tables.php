<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addressing: pools, prefixes, the addresses somebody has done something with,
 * and who has held each one (§5).
 *
 * **An address row exists because somebody acted on it.** A /64 holds eighteen
 * quintillion addresses and a /16 holds sixty-five thousand; a platform that
 * materialised a row per address would be unusable on the first and wasteful on
 * the second. So a row appears when an address is assigned, reserved,
 * quarantined or given a reverse-DNS name, and "what is free in this prefix" is
 * the prefix's own range minus the rows in it — a range query on the binary
 * column, which is the reason that column is binary.
 *
 * **Every address is sixteen bytes**, IPv4 mapped into the IPv6 space, so one
 * column and one index sort and compare both families. The text alongside it is
 * derived from those bytes on write and never parsed on read: two spellings of
 * one IPv6 address must not become two rows.
 *
 * **`ip_assignments` is append-only**, like the ledger and like the graph's
 * edges. A release closes a row rather than deleting it, because §5 makes
 * historical ownership mandatory — "who had 192.0.2.7 in March" is the question
 * an abuse report arrives as.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vlans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->unsignedSmallInteger('tag');
            $table->string('name');
            $table->string('site')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // A tag is unique within a site, not globally: VLAN 10 in Istanbul
            // and VLAN 10 in Frankfurt are different networks, and an operator
            // with two datacentres would otherwise be refused the second.
            $table->unique(['organization_id', 'site', 'tag']);
        });

        Schema::create('ip_pools', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('name');
            $table->string('family', 2);
            $table->string('purpose', 16);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'purpose']);
        });

        Schema::create('ip_prefixes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('ip_pool_id')->constrained('ip_pools')->cascadeOnDelete();

            // A supernet keeps its subnets when it is removed from the tree
            // rather than taking them with it: an operator re-parenting a /16
            // must not lose the /24s somebody is using.
            $table->foreignUlid('parent_id')->nullable()->constrained('ip_prefixes')->nullOnDelete();

            $table->foreignUlid('vlan_id')->nullable()->constrained('vlans')->nullOnDelete();

            $table->string('cidr', 45);
            $table->binary('network_bytes', 16, true);
            $table->binary('broadcast_bytes', 16, true);
            $table->unsignedTinyInteger('prefix_length');
            $table->string('family', 2);
            $table->string('gateway', 45)->nullable();
            $table->string('site')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // One prefix, once. Two rows for 192.0.2.0/24 would each show half
            // its addresses and neither would be wrong on its own.
            $table->unique(['organization_id', 'network_bytes', 'prefix_length']);

            // "Which prefix is this address in" and "what is inside this
            // supernet" are both range reads on these two columns.
            $table->index(['organization_id', 'network_bytes', 'broadcast_bytes']);
        });

        Schema::create('ip_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('ip_prefix_id')->constrained('ip_prefixes')->cascadeOnDelete();

            $table->string('address', 45);
            $table->binary('address_bytes', 16, true);
            $table->string('family', 2);
            $table->string('state', 16)->default('available');
            $table->string('reverse_dns')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['ip_prefix_id', 'address_bytes']);

            // The free-address walk reads this range in order.
            $table->index(['organization_id', 'address_bytes']);
            $table->index(['ip_prefix_id', 'state']);
        });

        Schema::create('ip_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The seller's, not the holder's. An address belongs to whoever owns
            // the range; the customer holds it.
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('ip_address_id')->constrained('ip_addresses')->cascadeOnDelete();

            // What is holding it: a service, a server, or something a later
            // phase discovers. A morph rather than two nullable columns.
            $table->string('holder_type');
            $table->ulid('holder_id');
            $table->string('holder_label');

            $table->string('actor_label')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            // The two reads: this address's history, and everything a service
            // currently holds.
            $table->index(['ip_address_id', 'released_at']);
            $table->index(['holder_type', 'holder_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_assignments');
        Schema::dropIfExists('ip_addresses');
        Schema::dropIfExists('ip_prefixes');
        Schema::dropIfExists('ip_pools');
        Schema::dropIfExists('vlans');
    }
};
