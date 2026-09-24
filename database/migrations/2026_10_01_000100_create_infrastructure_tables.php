<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Resource Graph, and the adapters that feed it.
 *
 * Four tables, and the reasoning is [ADR 0043](../../docs/adr/0043-the-resource-graph-is-edges-not-facts.md).
 * What a reviewer should check here:
 *
 * **`resource_nodes` stores identity, not facts.** `subject_type`/`subject_id`
 * point at the row this node *is*; everything anyone wants to know about a
 * service stays on the service. `label` is a display cache and is documented as
 * one, so a stale label is a cosmetic bug and a stale status is impossible.
 *
 * The unique key on `(organization_id, kind, node_key)` is what makes discovery
 * idempotent rather than careful: a projection that runs twice writes the same
 * rows, which is ADR 0031's rule applied to inventory.
 *
 * **`resource_edges` is append-only.** `ended_at` is the only column ever
 * updated, exactly like the ledger. Historical IP ownership — which §5 requires
 * as a first-class fact — is then `where ended_at is not null` rather than a
 * feature somebody has to build.
 *
 * An edge's `organization_id` is its **container's**. A server belongs to the
 * provider, the service on it belongs to the customer, and the edge belongs to
 * the provider — so a customer walking upward finds an edge the boundary does not
 * show them and learns nothing about the machine they share. Direction is a
 * privacy decision here, not only a modelling one.
 *
 * **`resource_metrics` holds the present.** One row per node and metric, upserted.
 * The time series lives in Prometheus or Zabbix, where a time series belongs
 * (§14); this table is what a screen renders and it is bounded by node count.
 * `value` is a double, which is not the money rule being bent: a CPU ratio is a
 * measurement and may be approximate, an amount may not, and no monetary value is
 * ever stored here.
 *
 * **`resource_adapters` holds the operator's decisions, never a credential.**
 * `writes_enabled` defaults to false: the same package should be a read-only
 * window on one installation and a control plane on another, by the operator's
 * choice rather than by which package they installed. Credentials wait for the
 * `SecretStore` in Phase B, because nothing in Phase A makes an outbound call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_nodes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // A kind is a validated string rather than an enum, because core
            // knows four kinds and every other one belongs to a module.
            $table->string('kind', 48);

            // Identity as the source knows it: a hostname, a serial, a device
            // id. Not this platform's ULID — an adapter has never heard of one.
            $table->string('node_key', 191);

            // A cache. The presenter loads the subject for anything that matters.
            $table->string('label', 191);

            // `core`, or the adapter key that discovered it. Which is also how a
            // projection knows which rows are its own to retire.
            $table->string('source', 48)->default('core');

            $table->string('subject_type', 96)->nullable();
            $table->ulid('subject_id')->nullable();

            // Written by the telemetry normalizer, never by hand. `unknown`
            // because a node with nothing watching it is a real state and the
            // Telemetry screen exists to show how many there are.
            $table->string('health', 16)->default('unknown');
            $table->string('health_message', 191)->nullable();

            $table->json('attributes')->nullable();

            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // Retired, never deleted: a terminated service is what an incident
            // review needs to see, and deleting it would shorten every
            // historical path that went through it.
            $table->timestamp('retired_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'node_key'], 'resource_nodes_identity_unique');
            $table->index(['organization_id', 'kind', 'retired_at'], 'resource_nodes_kind_index');
            $table->index(['subject_type', 'subject_id'], 'resource_nodes_subject_index');
            $table->index(['organization_id', 'health'], 'resource_nodes_health_index');
        });

        Schema::create('resource_edges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->foreignUlid('from_node_id')->constrained('resource_nodes')->cascadeOnDelete();
            $table->foreignUlid('to_node_id')->constrained('resource_nodes')->cascadeOnDelete();

            $table->string('relation', 48);
            $table->string('source', 48)->default('core');
            $table->json('attributes')->nullable();

            $table->timestamp('observed_at');
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            // Append-only: the same relationship observed again after it ended
            // is a new row, which is what makes the history readable.
            $table->unique(
                ['from_node_id', 'to_node_id', 'relation', 'observed_at'],
                'resource_edges_observation_unique',
            );

            // Both directions are traversed — downward for impact, upward for
            // "what does this sit on" — so both are indexed.
            $table->index(['from_node_id', 'relation', 'ended_at'], 'resource_edges_down_index');
            $table->index(['to_node_id', 'relation', 'ended_at'], 'resource_edges_up_index');
            $table->index(['organization_id', 'relation', 'ended_at'], 'resource_edges_relation_index');
        });

        Schema::create('resource_metrics', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('resource_node_id')->constrained('resource_nodes')->cascadeOnDelete();

            $table->string('metric', 64);
            $table->string('unit', 24);
            $table->double('value');

            $table->timestamp('sampled_at');
            $table->unsignedInteger('stale_after_seconds')->nullable();
            $table->string('source', 48);

            $table->timestamps();

            $table->unique(['resource_node_id', 'metric'], 'resource_metrics_present_unique');
            $table->index(['organization_id', 'metric'], 'resource_metrics_metric_index');
            $table->index(['source', 'sampled_at'], 'resource_metrics_freshness_index');
        });

        Schema::create('resource_adapters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('adapter_key', 96);
            $table->string('name', 96);
            $table->string('vendor', 96);
            $table->string('module', 48)->nullable();

            // What it declared the last time it was seen. Stored so the screen
            // works while the package is not loaded, for the same reason a
            // module's registration is stored on its row (ADR 0038).
            $table->json('capabilities')->nullable();

            $table->boolean('enabled')->default(true);

            // Off until an operator says otherwise, and turning it on is audited.
            $table->boolean('writes_enabled')->default(false);

            $table->string('health', 16)->default('unknown');
            $table->string('health_message', 191)->nullable();
            $table->string('remote_version', 48)->nullable();
            $table->boolean('supported')->default(true);
            $table->timestamp('health_checked_at')->nullable();
            $table->timestamp('last_collected_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'adapter_key'], 'resource_adapters_key_unique');
            $table->index(['organization_id', 'enabled'], 'resource_adapters_enabled_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_adapters');
        Schema::dropIfExists('resource_metrics');
        Schema::dropIfExists('resource_edges');
        Schema::dropIfExists('resource_nodes');
    }
};
