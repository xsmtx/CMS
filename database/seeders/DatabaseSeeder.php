<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Deliberately does not use WithoutModelEvents: models in this platform
 * maintain invariants in their event hooks (organization paths, hierarchy
 * guards, append-only enforcement). Muting events would seed invalid rows.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ProviderOrganizationSeeder::class,
            SystemRoleSeeder::class,
        ]);
    }
}
