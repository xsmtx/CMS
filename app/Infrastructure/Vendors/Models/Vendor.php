<?php

declare(strict_types=1);

namespace App\Infrastructure\Vendors\Models;

use App\Domain\Vendors\VendorKind;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody this business buys from (§24).
 *
 * Deliberately dull, and owned by the seller like billing terms: on a
 * reseller installation the provider's transit contract is not the
 * reseller's business and the boundary is what keeps it that way.
 *
 * The contact details are here rather than left to a note because of when
 * they are needed: a contract that lapses on a Sunday is a telephone number
 * somebody wants at the weekend, and "it is in the note somewhere" is not
 * a field anybody can search.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property VendorKind $kind
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $account_reference
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Vendor extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'vendors';

    protected $fillable = [
        'organization_id',
        'name',
        'kind',
        'contact_name',
        'contact_email',
        'contact_phone',
        'account_reference',
        'note',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'kind' => VendorKind::Other->value,
    ];

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => VendorKind::class];
    }
}
