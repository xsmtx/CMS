<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devices, and the refresh family each one holds (ADR 0049).
 *
 * **Nothing is written to an existing row.** Every `personal_access_tokens`
 * row in an installation today has a null `expires_at` and belongs to a
 * contact; the column added here is nullable and stays null for all of them,
 * so an integration that has run for a year is untouched. That is the rule
 * the adapter-to-module migration was written under, arriving again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            // A morph, because a device belongs to a person and this product
            // has two kinds: a contact on the portal and a staff user.
            $table->string('owner_type');
            $table->ulid('owner_id');

            $table->string('name');
            $table->string('platform', 32);
            $table->timestamp('last_seen_at')->nullable();

            /*
             * A revoked device is kept, not deleted. "This phone was revoked
             * on the 14th, and why" is the record somebody wants afterwards,
             * and `SessionRegistry` learned what a vanished row costs when an
             * operator's own device went missing from their own list.
             */
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();

            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'revoked_at']);
            $table->index(['organization_id', 'revoked_at']);
        });

        Schema::create('api_refresh_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('api_device_id')->constrained('api_devices')->cascadeOnDelete();

            // Hashed, like an access token. The table keeps the family, never
            // the value — and the chain is what makes reuse detectable.
            $table->string('token_hash', 64)->unique();
            $table->ulid('replaces_id')->nullable();

            /*
             * Set when the token is exchanged. A token presented with this
             * already filled in is a reuse: either the thief or the owner is
             * holding a stale copy and there is no way to tell which, so the
             * whole family goes (ADR 0049).
             */
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['api_device_id', 'used_at']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            // Nullable for ever: a token issued to a server in a rack has no
            // device, and giving it an invented one would be a lie in a table
            // somebody reads after an incident.
            $table->ulid('api_device_id')->nullable()->after('id');
            $table->index('api_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['api_device_id']);
            $table->dropColumn('api_device_id');
        });

        Schema::dropIfExists('api_refresh_tokens');
        Schema::dropIfExists('api_devices');
    }
};
