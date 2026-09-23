<?php

declare(strict_types=1);

namespace App\Infrastructure\Branding\Models;

use App\Domain\Branding\Surface;
use App\Infrastructure\Organizations\Concerns\BelongsToOrganization;
use App\Support\Audit\Contracts\AuditLabel;
use Database\Factories\ThemeSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Which theme an organization has chosen for one surface, and what it was
 * configured with.
 *
 * Separate from branding because the two change at different rates: a logo
 * every quarter, a theme twice. Keeping them apart also means replacing a
 * theme can discard its settings wholesale without going near the company's
 * legal name.
 *
 * @property Surface $surface
 * @property string $theme
 * @property array<string, mixed>|null $settings
 */
final class ThemeSetting extends Model implements AuditLabel
{
    use BelongsToOrganization;

    /** @use HasFactory<ThemeSettingFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'theme_settings';

    protected $fillable = ['organization_id', 'surface', 'theme', 'settings'];

    public function auditLabel(): string
    {
        return $this->surface->value.': '.$this->theme;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'surface' => Surface::class,
            'settings' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
