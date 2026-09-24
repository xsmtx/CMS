<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What to charge, where, and what to call it.
 *
 * The reasoning is [ADR 0045](../../docs/adr/0045-tax-is-rows-an-operator-edits.md):
 * core holds the rows and still implements no country's law. Until now the rate
 * came from `.env`, which meant one rate for an installation that sells in three
 * countries, changed by a deployment, by the person least likely to know it.
 *
 * What a reviewer should check here:
 *
 * **`rate_ppm` is an integer, in parts per million.** 20% is 200,000 and
 * Quebec's 9.975% is 99,750. A rate is on a monetary path and a float rate is a
 * float one step away from a cent — but basis points, the obvious choice, give
 * only two decimal places of a percent and QST needs three. The arithmetic
 * happens in `Money::percentage()`, which is the one place in this platform that
 * multiplies money.
 *
 * **The place is three optional columns, narrowest last.** A rule with a region
 * beats one with only a country, which beats the rule with no country at all.
 * Operators write overlapping rows on purpose — a national rate plus one
 * province — so the order has to be predictable rather than clever.
 *
 * **`starts_on` and `ends_on` mean a rate change is a new row.** The old rate
 * stays, dated, because an invoice issued under it has to remain explicable. The
 * document itself is frozen anyway (ADR 0023); this is so the *configuration* can
 * still be read back.
 *
 * **Rules belong to the seller.** `ResolveSeller` answers who that is, exactly as
 * it does for document numbering and support departments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // What the invoice calls it. `VAT`, `KDV`, `GST`, `PST`, `IVA`.
            $table->string('name', 64);

            // Where. Each narrower field is optional, and an empty one means
            // "anywhere within the one above".
            $table->char('country_code', 2)->nullable();
            $table->string('region_code', 8)->nullable();
            // A prefix or `*` wildcard, matched case-insensitively. Deliberately
            // not a regular expression: an operator types postcodes, not
            // patterns, and a regex in this column is a denial of service an
            // operator wrote by accident.
            $table->string('postcode_pattern', 32)->nullable();

            // Parts per million: 200000 is 20%, 99750 is 9.975%. A million is
            // 100%, which the form refuses to exceed.
            $table->unsignedInteger('rate_ppm');

            // 1 or 2. A second tax on the same supply; `compound` decides
            // whether it is charged on the first one's amount as well as on the
            // base, which is the difference between Quebec and the rest of
            // Canada.
            $table->unsignedTinyInteger('level')->default(1);
            $table->boolean('compound')->default(false);

            $table->string('applies_to', 16)->default('all');
            $table->string('customer_kind', 16)->default('all');

            // A business elsewhere with a tax id accounts for it themselves.
            // Whether the id is genuinely registered is not core's answer:
            // validating one means calling VIES, and core makes no such call
            // (ADR 0022). The operator's word is what this trusts.
            $table->boolean('exempts_validated_business')->default(false);
            $table->string('exemption_note', 191)->nullable();

            // Breaks a tie between rules of equal specificity. Higher first.
            $table->unsignedSmallInteger('priority')->default(0);

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'is_active', 'country_code'], 'tax_rules_lookup_index');
            $table->index(['organization_id', 'level', 'priority'], 'tax_rules_order_index');
        });

        Schema::create('tax_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            /*
             * Whether the prices in the catalog already include tax.
             *
             * This is the one setting that changes what a *price* means rather
             * than what is added to it, which is why it is a setting and not a
             * rule: in much of the world a consumer price is quoted with tax in
             * it, and in much of the rest it is not.
             */
            $table->boolean('prices_include_tax')->default(false);

            // `per_line` or `per_invoice`. Which one a jurisdiction wants is a
            // real difference and the total differs by a cent either way.
            $table->string('rounding', 16)->default('per_line');

            // What a form calls a tax id, because "VAT number" is wrong in most
            // of the world.
            $table->string('tax_id_label', 32)->nullable();
            $table->boolean('require_tax_id_for_business')->default(false);

            // Printed when a rule's exemption applies and the rule itself does
            // not say something more specific.
            $table->string('exemption_note', 191)->nullable();

            $table->timestamps();

            $table->unique('organization_id', 'tax_settings_organization_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_settings');
        Schema::dropIfExists('tax_rules');
    }
};
