<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail.
 *
 * There is no `updated_at`: a row is written once and never changed. Actor
 * and target are stored as denormalised type/id/label triples rather than
 * foreign keys, because an audit record must survive the deletion or
 * anonymisation of everything it refers to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Nullable: installation-level events (migrations, the installer,
            // licensing heartbeats) legitimately happen before or outside any
            // organization boundary.
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->string('action', 128);

            $table->string('actor_type', 191)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_label')->nullable();

            $table->string('target_type', 191)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('target_label')->nullable();

            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->text('reason')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('correlation_id', 64)->nullable();

            $table->timestamp('occurred_at');

            $table->index(['organization_id', 'occurred_at'], 'audit_logs_organization_index');
            $table->index(['target_type', 'target_id', 'occurred_at'], 'audit_logs_target_index');
            $table->index(['actor_type', 'actor_id', 'occurred_at'], 'audit_logs_actor_index');
            $table->index(['action', 'occurred_at'], 'audit_logs_action_index');
            $table->index('correlation_id', 'audit_logs_correlation_index');
            $table->index('occurred_at', 'audit_logs_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
