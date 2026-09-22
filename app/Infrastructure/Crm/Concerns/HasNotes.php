<?php

declare(strict_types=1);

namespace App\Infrastructure\Crm\Concerns;

use App\Infrastructure\Crm\Models\Note;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @mixin Model
 */
trait HasNotes
{
    /**
     * @return MorphMany<Note, $this>
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable')
            ->orderByDesc('is_pinned')
            ->latest();
    }
}
