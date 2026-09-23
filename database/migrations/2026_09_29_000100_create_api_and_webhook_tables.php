<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public API's own records, and the webhooks it sends back out.
 *
 * **`api_requests` records the request, never the body.** Who asked, what
 * route, what came back, how long it took and under which correlation id.
 * A request body holds whatever the client sent — a ticket message about a
 * password, a billing address, a card's last four — and a log that keeps it
 * is a breach waiting for a backup to be copied somewhere.
 *
 * **`idempotency_keys` stores the answer, not just the key.** A client that
 * did not hear the response retries; storing only "this key was used" lets
 * the platform refuse the retry but never tell the client what happened the
 * first time. The stored status and body are replayed verbatim, which is
 * the only answer that is actually correct.
 *
 * The fingerprint is what makes the key honest: the same key with a
 * different payload is a bug in the client, and quietly returning the first
 * answer would hide it.
 *
 * **A webhook endpoint owns a secret, and every attempt is a row.** An
 * operator's system that was down when `invoice.paid` fired has lost the
 * event unless something kept it; `webhook_deliveries` is what makes
 * redelivery possible, and `event_id` is what lets the receiver deduplicate
 * exactly as this platform asks its own clients to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Nullable: an unauthenticated request that was refused is
            // exactly the one worth having a record of.
            $table->foreignUlid('organization_id')->nullable()
                ->constrained('organizations')->nullOnDelete();

            $table->ulid('token_id')->nullable()->index();
            $table->string('token_name', 96)->nullable();

            $table->string('method', 10);
            $table->string('path', 191);
            $table->string('route', 96)->nullable();

            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->default(0);

            $table->string('ip', 45)->nullable();
            $table->string('correlation_id', 64)->nullable();

            // The error code from the envelope, so "what is this client
            // getting wrong" is one query rather than a scan.
            $table->string('error_code', 64)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['created_at'], 'api_requests_created_index');
            $table->index(['status', 'created_at'], 'api_requests_status_index');
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->ulid('token_id')->nullable();

            $table->string('key', 191);

            // Method plus path plus a hash of the body. Two requests that
            // differ anywhere differ here.
            $table->char('fingerprint', 64);

            $table->unsignedSmallInteger('status')->nullable();
            $table->longText('response')->nullable();

            $table->timestamp('created_at');
            $table->timestamp('completed_at')->nullable();

            // Scoped to the token, not the installation: two integrations
            // generating the same key must not collide, and a key is only
            // ever a promise to the client that sent it.
            $table->unique(['token_id', 'key'], 'idempotency_keys_unique');
            $table->index('created_at', 'idempotency_keys_created_index');
        });

        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Who it belongs to. A customer manages their own from the
            // portal; an endpoint with no customer is the operator's.
            $table->foreignUlid('customer_id')->nullable()
                ->constrained('customers')->cascadeOnDelete();

            $table->string('url', 500);
            $table->string('description', 191)->nullable();

            // Encrypted at rest. It is the only thing standing between an
            // endpoint and anybody who can post to it.
            $table->text('secret');

            // Which events this endpoint wants. Empty means all of them,
            // which is a deliberate default for a first integration.
            $table->json('events')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamp('last_delivered_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'is_active'], 'webhook_endpoints_active_index');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();

            // Stable across every attempt and every redelivery, so a
            // receiver can deduplicate on it.
            $table->ulid('event_id')->index();
            $table->string('event', 64);

            $table->json('payload');

            $table->string('status', 24);
            $table->unsignedSmallInteger('attempt')->default(0);

            $table->unsignedSmallInteger('response_status')->nullable();
            // A slice, not the whole thing: an endpoint that answers with a
            // megabyte of HTML must not fill this table with it.
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['endpoint_id', 'created_at'], 'webhook_deliveries_endpoint_index');
            $table->index('next_attempt_at', 'webhook_deliveries_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('api_requests');
    }
};
