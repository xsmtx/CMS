<?php

declare(strict_types=1);

namespace App\Infrastructure\Automation\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\InvoiceDunningStepFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A step that has already run against an invoice.
 *
 * This table is what makes the whole sequence safe to re-run: the question
 * becomes "which owed invoices have not had step 3" rather than "which
 * invoices are exactly seven days old". The unique index is the guarantee —
 * a second attempt to write the same pair fails at the database rather than
 * relying on the code to remember.
 *
 * @property CarbonImmutable $ran_at
 */
final class InvoiceDunningStep extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<InvoiceDunningStepFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'invoice_dunning_steps';

    protected $fillable = [
        'organization_id',
        'invoice_id',
        'step_id',
        'ran_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['ran_at' => 'immutable_datetime'];
    }
}
