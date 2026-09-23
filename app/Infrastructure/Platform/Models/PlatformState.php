<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Small pieces of installation state that have to outlive a cache flush.
 *
 * The scheduler heartbeat is the reason this is a table rather than a cache
 * key: a heartbeat that disappears when Redis restarts would report the
 * scheduler dead every deploy, and an operator who has seen three false
 * alarms stops reading the fourth.
 *
 * Not a settings store. Settings are per organization and arrive with the
 * settings screen; this is one row per installation-wide fact.
 *
 * @property string $key
 * @property mixed $value
 * @property CarbonImmutable|null $updated_at
 */
final class PlatformState extends Model
{
    public const string SCHEDULER_HEARTBEAT = 'scheduler.heartbeat';

    public const string MAINTENANCE = 'maintenance';

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'platform_state';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
