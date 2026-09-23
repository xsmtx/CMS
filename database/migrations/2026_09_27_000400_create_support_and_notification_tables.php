<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets, content, and everything this platform sends.
 *
 * Two ideas shape these tables.
 *
 * **A ticket is a clock.** `first_response_due_at` and `resolution_due_at`
 * are written when the ticket is opened, from the department's SLA scaled
 * by priority, and `first_responded_at` is stamped by the first *public*
 * staff reply. An internal note is not an answer to the customer and must
 * not stop their clock, which is why `is_internal` sits on the reply rather
 * than being inferred from who wrote it.
 *
 * **A template is data.** Wording lives in rows an operator can edit,
 * preview and reset — a platform whose customer-facing sentences are in PHP
 * cannot be white-labelled, which is the product. And every send is
 * recorded, because "did they get the suspension warning" is the first
 * question of every dispute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_departments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('name', 96);
            $table->string('slug', 96);
            $table->string('email', 191)->nullable();
            $table->text('description')->nullable();

            // Stated for normal priority and scaled from there, so an
            // operator sets two numbers rather than eight.
            $table->unsignedSmallInteger('first_response_minutes')->nullable();
            $table->unsignedSmallInteger('resolution_minutes')->nullable();

            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['organization_id', 'slug'], 'support_departments_slug_unique');
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('number', 32);

            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()
                ->constrained('support_departments')->nullOnDelete();
            $table->foreignUlid('assigned_to')->nullable()->constrained('staff_users')->nullOnDelete();

            // What the ticket is about. Nulled rather than cascaded: losing
            // a service must not lose the conversation about it.
            $table->foreignUlid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUlid('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->foreignUlid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('subject', 191);
            $table->string('status', 24)->default('open');
            $table->string('priority', 16)->default('normal');

            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->string('last_reply_by', 16)->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'tickets_number_unique');
            $table->index(['organization_id', 'status'], 'tickets_status_index');
            $table->index(['customer_id', 'status'], 'tickets_customer_index');
            $table->index(['assigned_to', 'status'], 'tickets_assignee_index');
            // The query the queue screen runs: what is late, soonest first.
            $table->index(['status', 'first_response_due_at'], 'tickets_sla_index');
        });

        Schema::create('ticket_replies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('ticket_id')->constrained('tickets')->cascadeOnDelete();

            // Who wrote it, on whichever side. Copied labels, because a
            // deleted staff account must not blank out the history.
            $table->string('author_type', 16);
            $table->ulid('author_id')->nullable();
            $table->string('author_name', 191);

            $table->longText('body');

            // The single most important column in this table. An internal
            // note is invisible to the customer and does not stop their
            // first-response clock.
            $table->boolean('is_internal')->default(false);

            $table->timestamp('created_at');

            $table->index(['ticket_id', 'created_at'], 'ticket_replies_ticket_index');
        });

        Schema::create('ticket_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignUlid('reply_id')->nullable()->constrained('ticket_replies')->cascadeOnDelete();

            // The name the uploader used, kept for display only. The stored
            // path is generated; a filename from a browser is never a path.
            $table->string('original_name', 191);
            $table->string('path', 512);
            $table->string('mime_type', 128);
            $table->unsignedInteger('size_bytes');

            $table->timestamp('created_at');

            $table->index('ticket_id', 'ticket_attachments_ticket_index');
        });

        Schema::create('canned_responses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('department_id')->nullable()
                ->constrained('support_departments')->nullOnDelete();

            $table->string('name', 96);
            $table->longText('body');
            $table->unsignedInteger('used_count')->default(0);

            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('title', 191);
            $table->string('slug', 191);
            $table->longText('body');

            $table->string('visibility', 16)->default('public');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_pinned')->default(false);

            $table->timestamps();

            $table->unique(['organization_id', 'slug'], 'announcements_slug_unique');
            $table->index(['organization_id', 'published_at'], 'announcements_published_index');
        });

        Schema::create('kb_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('name', 96);
            $table->string('slug', 96);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'slug'], 'kb_categories_slug_unique');
        });

        Schema::create('kb_articles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('kb_categories')->nullOnDelete();

            $table->string('title', 191);
            $table->string('slug', 191);
            $table->text('excerpt')->nullable();
            $table->longText('body');

            $table->string('visibility', 16)->default('public');
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            // A number, not a conversation. "Was this helpful" that opens a
            // text box collects complaints nobody reads.
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('unhelpful_count')->default(0);

            $table->timestamps();

            $table->unique(['organization_id', 'slug'], 'kb_articles_slug_unique');
            $table->index(['organization_id', 'visibility'], 'kb_articles_visibility_index');
            $table->fullText(['title', 'body'], 'kb_articles_search_index');
        });

        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('event', 64);
            $table->string('locale', 8);

            $table->string('subject', 191);
            $table->longText('body');
            $table->string('action_label', 96)->nullable();

            // An operator who has edited a template can reset it; the
            // shipped wording is the fallback, not a lost original.
            $table->boolean('is_customised')->default(true);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'event', 'locale'], 'notification_templates_unique');
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()
                ->constrained('organizations')->nullOnDelete();

            $table->string('event', 64);
            $table->string('channel', 16);
            $table->string('status', 16)->default('pending');

            // Copied, not joined: the log has to stay readable after a
            // contact is deleted or an address is corrected.
            $table->string('recipient_name', 191)->nullable();
            $table->string('recipient_address', 191)->nullable();
            $table->string('subject_type', 191)->nullable();
            $table->ulid('subject_id')->nullable();

            $table->string('locale', 8)->nullable();
            $table->string('rendered_subject', 191)->nullable();

            $table->string('reference', 191)->nullable();
            $table->text('error')->nullable();
            $table->string('correlation_id', 64)->nullable();

            $table->timestamp('created_at');
            $table->timestamp('delivered_at')->nullable();

            $table->index(['organization_id', 'created_at'], 'notification_deliveries_index');
            $table->index(['event', 'status'], 'notification_deliveries_event_index');
            $table->index(['subject_type', 'subject_id'], 'notification_deliveries_subject_index');
        });

        Schema::create('in_app_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()
                ->constrained('organizations')->nullOnDelete();

            $table->string('notifiable_type', 191);
            $table->ulid('notifiable_id');

            $table->string('event', 64);
            $table->string('title', 191);
            $table->text('body');
            $table->string('action_url', 512)->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');

            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at'],
                'in_app_notifications_notifiable_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('kb_categories');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('canned_responses');
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_replies');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('support_departments');
    }
};
