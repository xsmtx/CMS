<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Infrastructure\Catalog\Models\OptionGroup;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

final readonly class DeleteOptionGroup
{
    public function handle(OptionGroup $group, ?Model $actor = null): void
    {
        Audit::action('catalog.option_group.deleted')
            ->by($actor)
            ->on($group)
            ->forOrganization($group->organization_id)
            ->write();

        $group->delete();
    }
}
