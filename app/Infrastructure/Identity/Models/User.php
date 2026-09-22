<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Models;

use App\Domain\Access\RoleScope;
use App\Infrastructure\Access\Concerns\HasRoles;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * An authenticatable account, owned by exactly one organization.
 *
 * Phase 0 models a single staff-scoped account so that the authorization
 * foundation can be exercised end to end. Phase 1 (Identity & CRM) splits
 * this into staff users, customers and customer contacts, each with its own
 * table, guard and role scope; the trait composition here is what those
 * models will reuse.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 */
final class User extends Authenticatable implements AuditLabel
{
    use BelongsToOrganization;

    use HasApiTokens;
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasRoles;
    use HasUlids;
    use Notifiable;

    protected $fillable = ['organization_id', 'name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    public function roleScope(): RoleScope
    {
        return RoleScope::Staff;
    }

    public function auditLabel(): string
    {
        return $this->name.' <'.$this->email.'>';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
