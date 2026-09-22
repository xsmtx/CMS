<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Organizations\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the single provider organization that owns the installation.
 *
 * Everything else in the hierarchy hangs off this row, so it is seeded before
 * any account exists and is idempotent across deployments.
 */
final class ProviderOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $name = (string) config('app.name', 'InfraCMS');

        Organization::query()
            ->withoutGlobalScope('organization')
            ->firstOrCreate(
                ['type' => OrganizationType::Provider->value],
                [
                    'name' => $name,
                    'slug' => Str::slug($name).'-provider',
                    'is_active' => true,
                ],
            );
    }
}
