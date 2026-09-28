<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * One dismissal table, because there is now more than one kind of finding
 * (§21, §22).
 *
 * "This one is deliberate" is the same act whether the finding is a machine
 * the provider has and we do not, or a service nothing is billing for — an
 * author, a reason, and usually a date it stops applying. A second table
 * would be a second answer to one question, and the second one is always the
 * one that gets the feature nobody back-ports.
 *
 * `source` was already the discriminator: it held `service` and `orphan`, and
 * it holds `leakage` now. Nothing about the columns changes; only the name
 * stops claiming the table belongs to one family.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('reconciliation_dismissals', 'finding_dismissals');
    }

    public function down(): void
    {
        Schema::rename('finding_dismissals', 'reconciliation_dismissals');
    }
};
