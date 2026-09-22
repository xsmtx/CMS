<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Models;

use App\Domain\Identity\Guard;
use App\Domain\Identity\LoginFailureReason;
use App\Infrastructure\Organizations\OrganizationBoundary;
use Carbon\CarbonImmutable;
use Database\Factories\LoginHistoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * One sign-in attempt, successful or not.
 *
 * Append-only for the same reason the audit trail is: this is the record an
 * operator reads when deciding whether an account was compromised, and a
 * record the application can rewrite answers nothing.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property Guard $guard
 * @property string|null $email_attempted
 * @property bool $successful
 * @property LoginFailureReason|null $failure_reason
 * @property string|null $ip_address
 * @property string|null $correlation_id
 * @property CarbonImmutable $occurred_at
 */
final class LoginHistory extends Model
{
    /** @use HasFactory<LoginHistoryFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'login_histories';

    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeFailed(Builder $query): Builder
    {
        return $query->where('successful', false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guard' => Guard::class,
            'failure_reason' => LoginFailureReason::class,
            'successful' => 'boolean',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::addGlobalScope('organization', static function (Builder $query): void {
            app(OrganizationBoundary::class)->applyTo($query);
        });

        self::updating(static function (): never {
            throw new RuntimeException('Login history is append-only and cannot be updated.');
        });

        self::deleting(static function (): never {
            throw new RuntimeException('Login history is append-only and cannot be deleted.');
        });
    }
}
