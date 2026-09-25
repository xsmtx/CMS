<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The credential vault's default storage.
 *
 * One row per secret, encrypted at rest with the application key, owned by an
 * organization like everything else in this product. A reseller's Prometheus
 * token is not the provider's, and the boundary is what says so.
 *
 * `last_rotated_at` is a fact an operator asks for and a column nothing else
 * can answer: a secret's `updated_at` moves when anything about the row
 * changes, and "when was this credential last changed" is the question a
 * security review actually asks.
 *
 * There is no `value_preview`, no last-four, no hint column. The nearest this
 * table comes to revealing a value is its length, which is not stored either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secrets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // area/kind/owner, as `SecretReference` assembles it.
            $table->string('reference', 194);

            // Encrypted by the model's cast, never by the caller.
            $table->text('value');

            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'reference'], 'secrets_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secrets');
    }
};
