<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ownership boundary. Runs before everything else because every other
 * table references it.
 *
 * `path` stores the ancestor chain as `/<root>/<child>/`, indexed for prefix
 * matching: "every organization below X" becomes `path LIKE '<X path>%'`,
 * which is a range scan rather than a recursive query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('parent_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('type', 16)->index();
            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->string('path', 512);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index('path', 'organizations_path_index');
            $table->index(['type', 'is_active'], 'organizations_type_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
