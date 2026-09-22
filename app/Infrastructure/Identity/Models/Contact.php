<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Models;

use App\Domain\Access\RoleScope;
use App\Domain\Crm\CustomFieldEntity;
use App\Domain\Identity\AccountStatus;
use App\Domain\Identity\Guard;
use App\Infrastructure\Access\Concerns\HasRoles;
use App\Infrastructure\Crm\Concerns\HasAddresses;
use App\Infrastructure\Crm\Concerns\HasCustomFields;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Concerns\Authenticates;
use App\Infrastructure\Identity\Notifications\ContactPasswordReset;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use SensitiveParameter;

/**
 * A person at a customer organization.
 *
 * A contact is a person on file first and an account second. The billing
 * contact who exists only so invoices reach the right inbox has no password
 * and cannot sign in; `portal_access` is what separates the two.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string|null $phone
 * @property bool $portal_access
 * @property bool $is_primary
 * @property AccountStatus $status
 * @property CarbonImmutable|null $anonymized_at
 */
final class Contact extends Authenticatable implements AuditLabel
{
    use Authenticates;

    use BelongsToOrganization;
    use HasAddresses;
    use HasApiTokens;
    use HasCustomFields;
    /** @use HasFactory<ContactFactory> */
    use HasFactory;
    use HasRoles;
    use HasUlids;
    use Notifiable;

    protected $table = 'contacts';

    protected $fillable = [
        'organization_id',
        'customer_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'portal_access',
        'password',
        'is_primary',
        'status',
        'locale',
        'timezone',
        'notify_invoices',
        'notify_support',
        'notify_product',
        'notify_marketing',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function authGuard(): Guard
    {
        return Guard::Client;
    }

    public function customFieldEntity(): CustomFieldEntity
    {
        return CustomFieldEntity::Contact;
    }

    public function roleScope(): RoleScope
    {
        return RoleScope::Customer;
    }

    public function accountStatus(): AccountStatus
    {
        return $this->status;
    }

    public function displayName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function auditLabel(): string
    {
        return $this->displayName().' <'.$this->email.'>';
    }

    /**
     * A contact with no password, or with portal access withdrawn, is a
     * record rather than an account. Both are checked, because withdrawing
     * access must not depend on also clearing the password.
     */
    public function canAuthenticate(): bool
    {
        return $this->portal_access
            && $this->password !== null
            && $this->anonymized_at === null
            && $this->accountStatus()->canAuthenticate();
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Password reset mail is sent through the client broker, so the link
     * lands on the client area rather than the admin sign-in page.
     */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        $this->notify(new ContactPasswordReset($token));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeWithPortalAccess(Builder $query): Builder
    {
        return $query->where('portal_access', true)->whereNotNull('password');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'portal_access' => 'boolean',
            'is_primary' => 'boolean',
            'notify_invoices' => 'boolean',
            'notify_support' => 'boolean',
            'notify_product' => 'boolean',
            'notify_marketing' => 'boolean',
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
