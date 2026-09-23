<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Models;

use App\Infrastructure\Organizations\OrganizationBoundary;
use Carbon\CarbonImmutable;
use Database\Factories\ApiRequestRecordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One API request, and what came back.
 *
 * **Never the body.** A request body carries whatever the client sent — a
 * ticket message quoting a password, a billing address, an email — and a
 * log that keeps it becomes the most sensitive table in the installation
 * without anybody deciding that it should.
 *
 * The organization is nullable because the requests most worth recording
 * are the refused ones, and a request refused for a bad token has no
 * organization to attribute. It therefore registers the nullable boundary
 * scope by hand, like the audit log and the delivery log before it.
 *
 * @property string|null $token_id
 * @property string|null $token_name
 * @property string $method
 * @property string $path
 * @property string|null $route
 * @property int $status
 * @property int $duration_ms
 * @property string|null $ip
 * @property string|null $correlation_id
 * @property string|null $error_code
 * @property CarbonImmutable|null $created_at
 */
final class ApiRequestRecord extends Model
{
    /** @use HasFactory<ApiRequestRecordFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'api_requests';

    protected $fillable = [
        'organization_id',
        'token_id',
        'token_name',
        'method',
        'path',
        'route',
        'status',
        'duration_ms',
        'ip',
        'correlation_id',
        'error_code',
        'created_at',
    ];

    public function wasRefused(): bool
    {
        return $this->status >= 400;
    }

    protected static function booted(): void
    {
        self::addGlobalScope('organization', static function (Builder $query): void {
            app(OrganizationBoundary::class)->applyToNullable($query);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
