<?php

declare(strict_types=1);

namespace App\Infrastructure\Crm\Models;

use App\Domain\Crm\CustomerStatus;
use App\Domain\Crm\CustomFieldEntity;
use App\Infrastructure\Crm\Concerns\HasAddresses;
use App\Infrastructure\Crm\Concerns\HasCustomFields;
use App\Infrastructure\Crm\Concerns\HasNotes;
use App\Infrastructure\Crm\Concerns\HasTags;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The commercial profile of an organization.
 *
 * One per organization, owned by that same organization, so the Phase 0
 * boundary gives the customer their own profile in the client area and gives
 * their reseller or the provider the same row in admin, with no branching.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $company_name
 * @property string|null $legal_name
 * @property string|null $tax_id
 * @property string|null $tax_id_type
 * @property CustomerStatus $status
 * @property string $currency_code
 * @property bool $marketing_opt_in
 * @property CarbonImmutable|null $anonymized_at
 */
final class Customer extends Model implements AuditLabel
{
    use BelongsToOrganization;
    use HasAddresses;
    use HasCustomFields;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use HasNotes;
    use HasTags;
    use HasUlids;

    protected $table = 'customers';

    protected $fillable = [
        'organization_id',
        'company_name',
        'legal_name',
        'tax_id',
        'tax_id_type',
        'status',
        'currency_code',
        'locale',
        'timezone',
        'marketing_opt_in',
    ];

    public function customFieldEntity(): CustomFieldEntity
    {
        return CustomFieldEntity::Customer;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class)->orderByDesc('is_primary')->orderBy('last_name');
    }

    /**
     * @return HasOne<Contact, $this>
     */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class)->where('is_primary', true);
    }

    /**
     * The name a human uses for this customer. Falls through company, legal
     * name, then the primary contact, so a sole trader with no company name
     * is never rendered as an empty string.
     */
    public function displayName(): string
    {
        if ($this->company_name !== null && $this->company_name !== '') {
            return $this->company_name;
        }

        if ($this->legal_name !== null && $this->legal_name !== '') {
            return $this->legal_name;
        }

        $contactName = $this->primaryContact?->displayName();

        if ($contactName !== null && $contactName !== '') {
            return $contactName;
        }

        // A customer always has an organization: the column is not nullable
        // and the relation is unique. The branch covers a model built in
        // memory and never saved.
        $organization = $this->organization;

        if ($organization !== null) {
            return $organization->name;
        }

        return 'Customer';
    }

    public function auditLabel(): string
    {
        return $this->displayName();
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CustomerStatus::Active->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->where('company_name', 'like', $like)
                ->orWhere('legal_name', 'like', $like)
                ->orWhere('tax_id', 'like', $like)
                ->orWhereHas('contacts', function (Builder $contacts) use ($like): void {
                    $contacts->where('email', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                });
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'marketing_opt_in' => 'boolean',
            'tax_id_validated_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
