<?php

declare(strict_types=1);

namespace App\Infrastructure\Provisioning\Models;

use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use Database\Factories\ServiceOptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An option as it was chosen, copied onto the service.
 *
 * @property string $group_name
 * @property string $label
 * @property string|null $value
 */
final class ServiceOption extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ServiceOptionFactory> */
    use HasFactory;

    use HasUlids;

    public $timestamps = false;

    protected $table = 'service_options';

    protected $fillable = [
        'organization_id',
        'service_id',
        'group_name',
        'label',
        'value',
        'position',
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
