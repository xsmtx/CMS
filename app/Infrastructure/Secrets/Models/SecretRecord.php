<?php

declare(strict_types=1);

namespace App\Infrastructure\Secrets\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\SecretRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One credential, encrypted at rest.
 *
 * `$hidden` carries the value, so the one way to read it is to ask for it by
 * name. A model that serialises its own secret is a model somebody will
 * eventually `dd()`, put in a JSON response, or hand to a queue payload — and
 * `SecretRedactor` is the safety net for logs, not a reason to be careless
 * about the other three.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $reference
 * @property string $value
 * @property CarbonImmutable|null $last_rotated_at
 */
final class SecretRecord extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<SecretRecordFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'secrets';

    protected $fillable = ['organization_id', 'reference', 'value', 'last_rotated_at'];

    /**
     * Never in an array, a JSON response, a queue payload or a dump.
     *
     * @var list<string>
     */
    protected $hidden = ['value'];

    /**
     * The reference, and only the reference.
     *
     * An audit row names what changed; a label that reached for the value
     * would put a credential in the one table this product keeps forever.
     */
    public function auditLabel(): string
    {
        return $this->reference;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'last_rotated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
