<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The modules this installation has installed, and what state each is in.
 *
 * **Not organization-owned**, unlike almost every other table here. A module
 * is part of the installation the way a migration is: its code is on disk,
 * its migrations have run, and one reseller cannot have a different copy of
 * it than another. Per-organization *configuration* is Phase 13's question,
 * where resellers arrive; per-organization *code* is not a question anybody
 * should answer yes to.
 *
 * The row is what decides whether a module runs. Files on disk do nothing
 * on their own ([ADR 0038](../../docs/adr/0038-a-module-may-execute.md)):
 * discovery reads manifests, and only a row saying `enabled` causes the
 * package's PHP to be loaded at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The slug is the identity: it names the directory, the route
            // segment and the log channel, and it is what a dependency
            // refers to.
            $table->string('slug', 96)->unique();
            $table->string('name', 191);
            $table->string('type', 32)->index();

            // The version installed, which is what an upgrade compares
            // against what is on disk.
            $table->string('version', 32);
            $table->string('provider', 191)->nullable();

            // Where it was found, relative to the modules directory. Stored
            // so that a module can be located again without re-scanning,
            // and so that a row whose files have gone can say so.
            $table->string('path', 255);

            $table->string('state', 16)->default('installed')->index();

            // Why the platform turned it off. A module that failed silently
            // is a module an operator reinstalls three times.
            $table->text('failure_reason')->nullable();

            // What it registered when it was last enabled: the extension
            // points and the keys. Stored rather than asked, so that
            // uninstall can refuse a module with live services behind it
            // **without loading the module's code** — which is the one
            // thing uninstall must not have to do.
            $table->json('capabilities')->nullable();

            // The whole configuration as one encrypted blob rather than a
            // column per field: the schema belongs to the module, changes
            // with the module, and must never become a migration core has
            // to write. Secrets are inside it, so the blob is encrypted.
            $table->text('config')->nullable();

            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
