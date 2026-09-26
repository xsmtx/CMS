<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rules an operator writes, and what they raised (§15).
 *
 * **Core ships no rules at all**, the same decision tax, dunning and placement
 * got. An installation that woke somebody at three in the morning because of a
 * threshold nobody chose is an installation whose alerts get turned off within
 * a fortnight — so the table starts empty and the screen says so.
 *
 * **One open alert per rule and subject.** A disk that crosses 90% forty times
 * in an hour is one row with a count, not forty: the guard against repeating is
 * state, as it is for dunning steps and renewal sweeps (ADR 0031).
 *
 * The index that enforces it needs `dedupe_token`, and that column exists
 * because **MariaDB treats nulls in a unique index as distinct**. A key of
 * `(rule, subject, cleared_at)` would allow two open rows, since both hold null
 * there and null never collides with null — the index would look like it was
 * doing the work and would not be. The token is an empty string while the alert
 * is open and the alert's own id once it has cleared, so two open rows collide
 * and any number of cleared ones coexist.
 *
 * `subject_key` is the thing the rule is about, as the evaluator names it: a
 * node key for a metric, a check's key, an adapter's key, a task's value. A
 * morph would be wrong here — half of these are not rows.
 *
 * `suppressed_by` points at the maintenance window that held the notification
 * back. The observation is still recorded and still visible, because an
 * operator asking "did anything happen during the maintenance" must get the
 * true answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('name');
            $table->string('subject', 32);

            // Which metric, which check, which adapter, which task. Null where
            // the subject is about everything of its kind.
            $table->string('target')->nullable();

            $table->string('comparison', 16)->nullable();
            // Parts per million of the unit, for the same reason a tax rate is:
            // a threshold of 0.9 on a ratio and one of 90 on a percentage are
            // the same rule, and a float column would hold 0.8999999999.
            $table->bigInteger('threshold_ppm')->nullable();

            /*
             * How long it has to stay true. Zero means "the moment it is", and
             * that is the right default for a health check going down — but a
             * CPU spike that lasts nine seconds is not an alert, and this is
             * how an operator says so.
             */
            $table->unsignedInteger('for_minutes')->default(0);

            $table->string('severity', 16);
            $table->boolean('enabled')->default(true);

            // Whether a raise reaches anybody. A rule an operator wants on the
            // screen and not in their evening is a normal thing to want.
            $table->boolean('notify')->default(true);

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'enabled']);
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('alert_rule_id')->constrained('alert_rules')->cascadeOnDelete();

            // What it is about, as the evaluator names it — a node key, a
            // check's key, an adapter's key. Not a morph: half of these are
            // not rows.
            $table->string('subject_key', 191);
            $table->string('subject_label');

            $table->string('state', 16);
            $table->string('severity', 16);

            // What it was when it was last seen, so the list can say "94%"
            // rather than "above the threshold".
            $table->string('observed')->nullable();

            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('cleared_at')->nullable();

            $table->foreignUlid('suppressed_by')->nullable();
            $table->foreignUlid('incident_id')->nullable();

            // Empty while open, the alert's own id once cleared. See above:
            // a null here would not collide with another null.
            $table->string('dedupe_token', 26)->default('');

            $table->timestamps();

            $table->unique(['alert_rule_id', 'subject_key', 'dedupe_token'], 'alerts_dedupe');

            $table->index(['organization_id', 'state', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_rules');
    }
};
