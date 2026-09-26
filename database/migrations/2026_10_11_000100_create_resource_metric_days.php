<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per resource, metric and day.
 *
 * Telemetry keeps the present and never the series (`advanced-operations-plan.md`
 * §14): Prometheus and Zabbix own the history, and a table that accepted every
 * sample would be a time-series database nobody sized. This is the exception the
 * plan names, and it is bounded by arithmetic rather than by hope — one row per
 * node per metric per day is 365 rows a year for a thing, which is small, and it
 * is the only shape a capacity answer can be built from.
 *
 * **Accumulated as the samples arrive, not rolled up at midnight.** A nightly job
 * reading `resource_metrics` would find one value — the last one written — and
 * call it a day's average. The counters here are updated by the collector, so
 * `avg` is an average of everything that was actually seen and `max` is the peak
 * rather than whatever happened to be true at 03:00.
 *
 * `sum` is stored rather than `avg` for the same reason a ledger stores rows:
 * an average cannot be updated without knowing how many readings it came from,
 * and a running average that loses its denominator is a number that drifts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_metric_days', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('resource_node_id')->constrained('resource_nodes')->cascadeOnDelete();

            $table->string('metric', 64);
            $table->string('unit', 24);
            $table->date('day');

            $table->unsignedInteger('samples')->default(0);
            $table->double('minimum');
            $table->double('maximum');
            $table->double('sum');
            $table->double('last');

            $table->timestamps();

            $table->unique(['resource_node_id', 'metric', 'day'], 'resource_metric_days_unique');
            $table->index(['organization_id', 'metric', 'day'], 'resource_metric_days_metric_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_metric_days');
    }
};
