<?php

declare(strict_types=1);

namespace App\Infrastructure\Crm\Models;

use App\Domain\Crm\CustomerStatus;
use App\Domain\Crm\CustomFieldEntity;
use App\Infrastructure\Billing\Models\PaymentMethod;
use App\Infrastructure\Crm\Concerns\HasAddresses;
use App\Infrastructure\Crm\Concerns\HasCustomFields;
use App\Infrastructure\Crm\Concerns\HasNotes;
use App\Infrastructure\Crm\Concerns\HasTags;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Infrastructure\Organizations\Models\Organization;
use App\Infrastructure\Provisioning\Models\Service;
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
 * @property bool $send_overdue_notices
 * @property bool $automatic_suspension
 * @property bool $separate_invoices
 * @property CarbonImmutable|null $tax_id_validated_at
 * @property CarbonImmutable|null $anonymized_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
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

    /**
     * Stated here as well as in the migration.
     *
     * A database default fills the row but leaves the model in memory
     * without the attribute, and a cast reads that absence as null — so a
     * freshly created customer would answer "no" to every one of these
     * until it was read back. It bit the money columns in Phase 4 and the
     * booleans in Phase 7.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'send_overdue_notices' => true,
        'automatic_suspension' => true,
        'separate_invoices' => false,
    ];

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
        'send_overdue_notices',
        'automatic_suspension',
        'separate_invoices',
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
     * The card this customer pays with unless somebody says otherwise.
     *
     * Declared as its own relation rather than filtered from
     * `paymentMethods` in a presenter: a services list that showed the
     * payment method would otherwise load every card of every customer on
     * the page to find one of them.
     *
     * @return HasOne<PaymentMethod, $this>
     */
    public function defaultPaymentMethod(): HasOne
    {
        return $this->hasOne(PaymentMethod::class)->where('is_default', true);
    }

    /**
     * Cards on file.
     *
     * Declared here so an operator can search on the last four digits of a
     * card — which is what a chargeback notice gives them, and often all it
     * gives them.
     *
     * @return HasMany<PaymentMethod, $this>
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /**
     * What this customer is running.
     *
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * The relations `displayName()` may read, ready to hand to `with()`.
     *
     * `displayName()` falls through the company name, the legal name, the
     * primary contact and finally the organization. A list that eager-loads
     * the customer alone therefore lazy-loads the moment one row has no
     * company name — and Laravel only reports that when the page returns
     * more than one row, which is why it reaches an operator rather than a
     * test.
     *
     * Stating the list in one place is the point: every list that shows a
     * customer's name asks this method rather than remembering the chain,
     * so the next list added cannot get it wrong.
     *
     *   Customer::query()->with(Customer::displayNameWith())
     *   Contact::query()->with(Customer::displayNameWith('customer'))
     *
     * No column constraints, deliberately. A partial select is the other
     * half of this same trap: it leaves the attributes a caller did not
     * anticipate missing, and strict mode throws for those too.
     *
     * @return list<string>
     */
    public static function displayNameWith(?string $relation = null): array
    {
        if ($relation === null) {
            return ['primaryContact', 'organization'];
        }

        return [$relation, $relation.'.primaryContact', $relation.'.organization'];
    }

    /**
     * The name a human uses for this customer. Falls through company, legal
     * name, then the primary contact, so a sole trader with no company name
     * is never rendered as an empty string.
     */
    /**
     * Whether this customer is a company rather than a person.
     *
     * **A company name, not a tax id.** Inferring it from the tax id was how it
     * worked until the tax rules needed the answer, and it is circular: it makes
     * an individual who typed a tax id a business, a company that has not given
     * one an individual, and a rule saying "a business must state a tax id"
     * impossible to ever fire — a customer without one would not be a business.
     *
     * "Has a tax id" is a different question and is still asked separately,
     * because reverse charge needs both: a business *and* an id to charge it to.
     */
    public function isBusiness(): bool
    {
        return trim((string) $this->company_name) !== ''
            || trim((string) $this->legal_name) !== '';
    }

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
     * Everybody except the ones who are gone.
     *
     * A closed account is a record kept for the accounts department, not a
     * customer somebody is working with. Hiding them by default is what
     * makes the list usable after a few years — and it is a default rather
     * than a filter, so one switch brings them back.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeStillTrading(Builder $query): Builder
    {
        return $query->whereNot('status', CustomerStatus::Closed->value);
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
            'send_overdue_notices' => 'boolean',
            'automatic_suspension' => 'boolean',
            'separate_invoices' => 'boolean',
            'tax_id_validated_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
