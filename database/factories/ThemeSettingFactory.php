<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Branding\Surface;
use App\Infrastructure\Branding\Models\ThemeSetting;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThemeSetting>
 */
final class ThemeSettingFactory extends Factory
{
    protected $model = ThemeSetting::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => Organization::query()
                ->withoutGlobalScope('organization')
                ->whereNull('parent_id')
                ->firstOrFail()
                ->id,
            'surface' => Surface::Storefront->value,
            'theme' => 'core',
            'settings' => null,
        ];
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (): array => ['organization_id' => $organization->id]);
    }

    public function surface(Surface $surface, string $theme): self
    {
        return $this->state(fn (): array => [
            'surface' => $surface->value,
            'theme' => $theme,
        ]);
    }
}
