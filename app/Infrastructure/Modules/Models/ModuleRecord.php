<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules\Models;

use App\Domain\Modules\ModuleState;
use App\Domain\Modules\ModuleType;
use App\Support\Audit\Contracts\AuditLabel;
use Carbon\CarbonImmutable;
use Database\Factories\ModuleRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An installed module, as a row.
 *
 * Named `ModuleRecord` rather than `Module` on purpose: `Module` is the
 * contract a package implements, and a class that was both the interface a
 * third party writes against and the table this application stores would be
 * the worst possible name in the codebase.
 *
 * No `BelongsToOrganization`: a module is installation-wide. That is the one
 * place this platform's ownership rule does not apply, and it applies
 * nowhere else by accident — see the migration for why.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property ModuleType $type
 * @property string $version
 * @property string|null $provider
 * @property string $path
 * @property ModuleState $state
 * @property string|null $failure_reason
 * @property array<string, list<string>>|null $capabilities
 * @property array<string, mixed>|null $config
 * @property CarbonImmutable|null $installed_at
 * @property CarbonImmutable|null $enabled_at
 * @property CarbonImmutable|null $disabled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ModuleRecord extends Model implements AuditLabel
{
    /** @use HasFactory<ModuleRecordFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'modules';

    protected $fillable = [
        'slug',
        'name',
        'type',
        'version',
        'provider',
        'path',
        'state',
        'failure_reason',
        'capabilities',
        'config',
        'installed_at',
        'enabled_at',
        'disabled_at',
    ];

    /**
     * The configuration holds secrets, so it never leaves this object by
     * accident. A module's values reach the module through `ModuleContext`
     * and reach the screen through a presenter that masks them.
     *
     * @var list<string>
     */
    protected $hidden = ['config'];

    /**
     * @var array<string, string>
     */
    protected $attributes = ['state' => 'installed'];

    public function auditLabel(): string
    {
        return $this->name.' ('.$this->slug.')';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ModuleType::class,
            'state' => ModuleState::class,
            'capabilities' => 'array',
            // Encrypted, because a module's API key lives in here and a
            // database dump is the commonest way one leaves a company.
            'config' => 'encrypted:array',
            'installed_at' => 'immutable_datetime',
            'enabled_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
