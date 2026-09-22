<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Models;

use App\Domain\Access\RoleScope;
use App\Domain\Identity\AccountStatus;
use App\Domain\Identity\Guard;
use App\Infrastructure\Access\Concerns\HasRoles;
use App\Infrastructure\Identity\Concerns\Authenticates;
use App\Infrastructure\Identity\Notifications\StaffPasswordReset;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\StaffUserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use SensitiveParameter;

/**
 * A member of provider or reseller staff.
 *
 * Staff belong to an organization like everything else, which is what makes
 * reseller staff work: they authenticate against the same guard and see only
 * their own subtree.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $email
 * @property AccountStatus $status
 * @property string|null $locale
 * @property string|null $timezone
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property CarbonImmutable|null $last_login_at
 */
final class StaffUser extends Authenticatable implements AuditLabel
{
    use Authenticates;

    use BelongsToOrganization;
    use HasApiTokens;
    /** @use HasFactory<StaffUserFactory> */
    use HasFactory;
    use HasRoles;
    use HasUlids;
    use Notifiable;

    protected $table = 'staff_users';

    protected $fillable = ['organization_id', 'name', 'email', 'password', 'status', 'locale', 'timezone'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function authGuard(): Guard
    {
        return Guard::Staff;
    }

    public function roleScope(): RoleScope
    {
        return RoleScope::Staff;
    }

    public function accountStatus(): AccountStatus
    {
        return $this->status;
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function auditLabel(): string
    {
        return $this->name.' <'.$this->email.'>';
    }

    /**
     * Reset mail is sent through the staff broker, so the link lands on the
     * admin reset screen rather than the client one.
     */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        $this->notify(new StaffPasswordReset($token));
    }

    /**
     * @return HasMany<Impersonation, $this>
     */
    public function impersonations(): HasMany
    {
        return $this->hasMany(Impersonation::class, 'impersonator_id')->latest('started_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AccountStatus::Active->value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
