<?php

declare(strict_types=1);

namespace App\Infrastructure\Crm\Concerns;

use App\Infrastructure\Crm\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @mixin Model
 */
trait HasTags
{
    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Replace the tag set. Tags outside the acting organization's boundary
     * are filtered out by the global scope on the lookup, so a crafted
     * request cannot attach another reseller's vocabulary.
     *
     * @param  list<string>  $tagIds
     */
    public function syncTags(array $tagIds): void
    {
        $permitted = Tag::query()->whereIn('id', $tagIds)->pluck('id')->all();

        $this->tags()->sync($permitted);
    }
}
