<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based access control.
 *
 * `permissions` mirrors the code-declared registry. `role_assignments` is
 * polymorphic so that staff users, customer contacts and API tokens can all
 * carry roles in later phases without another schema migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('scope', 16)->index();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug', 191)->unique();
            $table->string('group', 64)->index();
            $table->string('scope', 16)->index();
            $table->boolean('is_high_risk')->default(false);
            $table->string('module', 64)->nullable()->index();
            $table->timestamp('orphaned_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('subject_type', 191);
            $table->string('subject_id', 64);
            $table->timestamps();

            $table->unique(['role_id', 'subject_type', 'subject_id'], 'role_assignments_unique');
            $table->index(['subject_type', 'subject_id'], 'role_assignments_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
