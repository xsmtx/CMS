<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Infrastructure\Catalog\Models\Addon;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

final readonly class DeleteAddon
{
    public function handle(Addon $addon, ?Model $actor = null): void
    {
        Audit::action('catalog.addon.deleted')
            ->by($actor)
            ->on($addon)
            ->forOrganization($addon->organization_id)
            ->write();

        $addon->delete();
    }
}
