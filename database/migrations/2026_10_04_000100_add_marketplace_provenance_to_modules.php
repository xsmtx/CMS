<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a module came from.
 *
 * `modules/` never had to answer this: every module was a directory somebody put
 * there, which is a deliberate act on a machine they control. A marketplace adds
 * the other kind, and the difference has to be recorded rather than inferred —
 * otherwise a catalogue entry could offer the slug of a hand-installed package
 * and replace it (ADR 0047).
 *
 * `disk` is the default, and it is the right default for every row that already
 * exists: those are all directories somebody placed.
 *
 * `origin_digest` is what was proven at fetch time. It is kept so that "which
 * bytes is this installation actually running" has an answer that does not
 * depend on the vendor still being reachable, and so an upgrade can say what it
 * replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->string('source', 16)->default('disk')->after('path');
            $table->string('origin_digest', 64)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->dropColumn(['source', 'origin_digest']);
        });
    }
};
