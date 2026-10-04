<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant's settings and what it has cost (ADR 0050).
 *
 * **Two tables and neither holds a completion.** A draft nobody sent is not a
 * record worth keeping, and one that was sent is already the reply — storing
 * both would be keeping a customer's correspondence twice, in a table nobody
 * thinks of as correspondence, retained under no policy and missed by every
 * erasure request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            /*
             * The seller's, through `ResolveSeller` — like billing terms and
             * tax rules. Whether a customer's words may be sent to a vendor
             * is a question with an owner, and on a reseller installation the
             * owner is the reseller rather than the provider.
             */
            $table->foreignUlid('organization_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * Which provider answers, by its adapter key. Null means the
             * assistant is off entirely, which is the shipped state: core
             * names no vendor and turns nothing on.
             */
            $table->string('provider_key')->nullable();

            // One row per feature, so an operator can draft summaries for
            // their own staff without drafting anything a customer reads.
            $table->json('enabled_features')->nullable();

            // The seller's own wording: answer formally, in Turkish, never
            // promise a refund. Core has no opinion about which.
            $table->text('instructions')->nullable();

            $table->timestamps();
        });

        Schema::create('ai_usages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            // Who asked. Nullable because a scheduled draft would have no
            // actor, and a record whose author was invented would be a record
            // that lied — `AccessGrants::revoke()`'s rule.
            $table->ulid('staff_user_id')->nullable();

            $table->string('feature', 32);
            $table->string('provider_key');

            // What actually answered, which is not always what was asked for:
            // a provider that silently served a smaller model is one whose
            // bill will not match this table.
            $table->string('model');

            /*
             * Null rather than zero where a provider reports nothing.
             * "Nobody said" and "it was free" are different, and a report
             * that summed the nulls as zeroes would understate the month in
             * the one direction a cost report must never be wrong in.
             */
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();

            // A refusal is recorded too: "the vendor would not answer" is
            // what somebody is looking for when they ask why the button has
            // stopped working.
            $table->string('outcome', 24);

            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
        Schema::dropIfExists('ai_settings');
    }
};
