<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Models;

use App\Domain\Identity\Guard;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\AuthenticatedSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A live sign-in, mirrored out of the session store.
 *
 * Sessions themselves live in Redis. This row is what makes "you are signed
 * in on three devices, sign the others out" answerable without tying the
 * feature to one session driver.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $session_id
 * @property Guard $guard
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $last_active_at
 */
final class AuthenticatedSession extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AuthenticatedSessionFactory> */
    use HasFactory;
    use HasUlids;

    protected $table = 'authenticated_sessions';

    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCurrent(string $currentSessionId): bool
    {
        return hash_equals($this->session_id, $currentSessionId);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guard' => Guard::class,
            'last_active_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
