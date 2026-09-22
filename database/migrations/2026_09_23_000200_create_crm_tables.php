<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM: the records that hang off a customer.
 *
 * All four are polymorphic, because later phases attach the same structures
 * to services, invoices and tickets. Getting the shape right once is cheaper
 * than four near-identical tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('addressable_type', 191);
            $table->string('addressable_id', 64);

            $table->string('type', 16)->index();
            $table->string('label', 96)->nullable();

            $table->string('line_one', 191);
            $table->string('line_two', 191)->nullable();
            $table->string('city', 96);
            $table->string('region', 96)->nullable();
            $table->string('postal_code', 32)->nullable();

            // ISO 3166-1 alpha-2. Tax rules, registrar requirements and
            // payment routing all key off this, so it is never free text.
            $table->char('country_code', 2);

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['addressable_type', 'addressable_id', 'type'], 'addresses_owner_index');
        });

        Schema::create('tags', function (Blueprint $table): void {
            // The vocabulary is per organization: a reseller's tags are their
            // own and never leak into another reseller's filter list.
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('name', 64);
            $table->string('slug', 64);
            $table->string('color', 16)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug'], 'tags_organization_slug_unique');
        });

        Schema::create('taggables', function (Blueprint $table): void {
            $table->foreignUlid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->string('taggable_type', 191);
            $table->string('taggable_id', 64);

            $table->primary(['tag_id', 'taggable_type', 'taggable_id'], 'taggables_primary');
            $table->index(['taggable_type', 'taggable_id'], 'taggables_owner_index');
        });

        Schema::create('custom_field_definitions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('entity_type', 32)->index();
            $table->string('key', 64);
            $table->string('label', 191);
            $table->string('type', 16);
            $table->json('options')->nullable();
            $table->string('help_text', 255)->nullable();

            $table->boolean('is_required')->default(false);

            // Whether the customer sees it in the client area at all. An
            // internal field is staff-only and must never reach a client
            // payload.
            $table->boolean('is_customer_visible')->default(false);
            $table->boolean('is_customer_editable')->default(false);

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'entity_type', 'key'], 'custom_fields_key_unique');
        });

        Schema::create('custom_field_values', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('definition_id')->constrained('custom_field_definitions')->cascadeOnDelete();

            $table->string('owner_type', 191);
            $table->string('owner_id', 64);

            // JSON rather than a column per type: the definition owns the
            // type, and casting at the boundary keeps one row shape.
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['definition_id', 'owner_type', 'owner_id'], 'custom_field_values_unique');
            $table->index(['owner_type', 'owner_id'], 'custom_field_values_owner_index');
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('notable_type', 191);
            $table->string('notable_id', 64);

            // Denormalised author, for the same reason audit records
            // denormalise theirs: the note has to stay readable after the
            // staff member who wrote it leaves.
            $table->string('author_type', 191)->nullable();
            $table->string('author_id', 64)->nullable();
            $table->string('author_label', 191)->nullable();

            $table->text('body');
            $table->boolean('is_customer_visible')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->index(['notable_type', 'notable_id', 'created_at'], 'notes_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_definitions');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('addresses');
    }
};
