<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\IdempotencyRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A promise that a write happens once, and the answer it produced.
 *
 * Storing only "this key was used" would let the platform refuse a retry
 * and never tell the client what happened the first time — which leaves the
 * client in exactly the state the key was supposed to rescue it from. So
 * the response is stored and replayed verbatim, status code included.
 *
 * The fingerprint is what keeps the promise honest. The same key with a
 * different payload is a bug in the client, and answering it with the first
 * result would hide the bug behind a correct-looking response.
 *
 * @property string $key
 * @property string $fingerprint
 * @property int|null $status
 * @property string|null $response
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $completed_at
 */
final class IdempotencyRecord extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<IdempotencyRecordFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'organization_id',
        'token_id',
        'key',
        'fingerprint',
        'status',
        'response',
        'created_at',
        'completed_at',
    ];

    public function isComplete(): bool
    {
        return $this->completed_at !== null && $this->status !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'created_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
