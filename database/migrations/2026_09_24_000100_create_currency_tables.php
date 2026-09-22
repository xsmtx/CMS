<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Currencies and the history of their rates.
 *
 * Rates never compute a sale price. A customer buying in EUR pays the EUR
 * price row exactly as an operator entered it; converting at display time
 * would produce a price that changes between the page and the cart. The rate
 * exists for reporting, and for the invoice snapshot Phase 4 takes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->char('code', 3);
            $table->string('name', 64);
            $table->string('symbol', 8)->nullable();

            // Carried rather than assumed. JPY has no minor unit and KWD has
            // three; code that assumes two is wrong for both.
            $table->unsignedTinyInteger('exponent')->default(2);

            // Rate to the installation's base currency, as a decimal string.
            // 18,8 is enough for a weak currency against a strong one
            // without losing precision at the far end.
            $table->decimal('rate', 18, 8)->default(1);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'currencies_organization_code_unique');
            $table->index(['organization_id', 'is_active'], 'currencies_active_index');
        });

        Schema::create('exchange_rate_snapshots', function (Blueprint $table): void {
            // Append-only. A historical document has to be able to say what
            // the rate was when it was issued, and a mutable rate table
            // cannot answer that.
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('currency_id')->constrained('currencies')->cascadeOnDelete();

            $table->char('code', 3);
            $table->decimal('rate', 18, 8);
            $table->string('source', 64)->nullable();
            $table->timestamp('captured_at');

            $table->index(['currency_id', 'captured_at'], 'exchange_rates_currency_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rate_snapshots');
        Schema::dropIfExists('currencies');
    }
};
