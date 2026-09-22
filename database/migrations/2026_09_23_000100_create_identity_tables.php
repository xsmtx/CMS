<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity.
 *
 * Phase 0 shipped a single placeholder `users` table, documented as a
 * placeholder. It is renamed here rather than dual-written: the split into
 * staff and contacts is the whole point of this phase, and a Phase 0
 * installation has no production data to preserve.
 *
 * Staff and contacts are separate tables so that the two guards cannot share
 * a session, a password-reset token or a rate-limit bucket. A single table
 * with a discriminator column would make every one of those a runtime check
 * somebody can forget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('users', 'staff_users');

        Schema::table('staff_users', function (Blueprint $table): void {
            $table->string('status', 16)->default('active')->after('email');
            $table->string('locale', 8)->nullable()->after('status');
            $table->string('timezone', 64)->nullable()->after('locale');

            // Encrypted at rest and never logged; the redaction key list
            // already covers `two_factor` and `recovery_code`.
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->index(['organization_id', 'status'], 'staff_users_organization_status_index');
        });

        Schema::create('customers', function (Blueprint $table): void {
            // One profile per organization. The profile is owned by the
            // organization it describes, so the Phase 0 boundary gives both
            // the customer and their upstream reseller access with no
            // special-casing.
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();

            $table->string('company_name', 191)->nullable();
            $table->string('legal_name', 191)->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->string('tax_id_type', 32)->nullable();
            $table->timestamp('tax_id_validated_at')->nullable();

            $table->string('status', 16)->default('pending')->index();
            $table->char('currency_code', 3)->default('EUR');
            $table->string('locale', 8)->nullable();
            $table->string('timezone', 64)->nullable();

            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('anonymized_at')->nullable()->index();

            $table->timestamps();

            $table->index(['status', 'created_at'], 'customers_status_created_index');
        });

        Schema::create('contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('first_name', 96);
            $table->string('last_name', 96);
            $table->string('email')->unique();
            $table->string('phone', 32)->nullable();

            // A contact is a person on file first and an account second. A
            // billing contact who never signs in has no password at all.
            $table->boolean('portal_access')->default(false)->index();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamp('email_verified_at')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->string('status', 16)->default('active');

            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->string('locale', 8)->nullable();
            $table->string('timezone', 64)->nullable();

            // Communication preferences. Transactional mail about a service
            // the customer pays for is not opt-out, so it has no flag here.
            $table->boolean('notify_invoices')->default(true);
            $table->boolean('notify_support')->default(true);
            $table->boolean('notify_product')->default(true);
            $table->boolean('notify_marketing')->default(false);

            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'is_primary'], 'contacts_customer_primary_index');
            $table->index(['organization_id', 'status'], 'contacts_organization_status_index');
        });

        Schema::create('contact_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('login_histories', function (Blueprint $table): void {
            // Append-only. Failures are recorded too, which is the point:
            // an operator has to be able to tell a forgotten password apart
            // from a credential-stuffing run.
            $table->ulid('id')->primary();

            // Null until the attempt is tied to a known account.
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('subject_type', 191)->nullable();
            $table->string('subject_id', 64)->nullable();

            $table->string('guard', 16);
            $table->string('email_attempted', 191)->nullable();
            $table->boolean('successful');
            $table->string('failure_reason', 48)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'login_histories_subject_index');
            $table->index(['email_attempted', 'occurred_at'], 'login_histories_email_index');
            $table->index(['successful', 'occurred_at'], 'login_histories_outcome_index');
        });

        Schema::create('authenticated_sessions', function (Blueprint $table): void {
            // Sessions live in Redis for speed. This table is the queryable
            // mirror that makes "sign out my other devices" work under any
            // session driver an installation chooses.
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('session_id', 191)->unique();

            $table->string('subject_type', 191);
            $table->string('subject_id', 64);
            $table->string('guard', 16);

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('last_active_at');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'authenticated_sessions_subject_index');
            $table->index('last_active_at', 'authenticated_sessions_activity_index');
        });

        Schema::create('impersonations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->foreignUlid('impersonator_id')->constrained('staff_users')->cascadeOnDelete();
            $table->string('subject_type', 191);
            $table->string('subject_id', 64);

            // Required. An impersonation without a stated reason is not
            // reviewable, and the review is the only control that makes the
            // feature acceptable at all.
            $table->text('reason');

            $table->string('ip_address', 45)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->index(['impersonator_id', 'started_at'], 'impersonations_actor_index');
            $table->index(['subject_type', 'subject_id', 'started_at'], 'impersonations_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonations');
        Schema::dropIfExists('authenticated_sessions');
        Schema::dropIfExists('login_histories');
        Schema::dropIfExists('contact_password_reset_tokens');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('customers');

        Schema::table('staff_users', function (Blueprint $table): void {
            $table->dropIndex('staff_users_organization_status_index');
            $table->dropColumn([
                'status',
                'locale',
                'timezone',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'password_changed_at',
                'last_login_at',
            ]);
        });

        Schema::rename('staff_users', 'users');
    }
};
