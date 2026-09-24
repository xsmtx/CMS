<?php

declare(strict_types=1);

namespace App\Application\Shared;

use App\Domain\Shared\NumberResetPeriod;
use App\Infrastructure\Shared\Models\NumberSequence;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What a seller's document numbers look like, and where they start.
 *
 * The one settings write in this product where a wrong value is not an
 * inconvenience but a **duplicate document number**, so two things are true of
 * it that are not true of the others.
 *
 * First, `next_value` may be moved and the audit row says by how much. An
 * operator migrating from another panel has to be able to say "continue at
 * 10421", or their new invoices collide with the ones their customers already
 * have on paper. Lowering it is the same power pointed the other way and is not
 * refused — the platform cannot know which numbers a legacy system used — but it
 * is recorded, because the next failure will be a unique-index violation and
 * this row is what explains it.
 *
 * Second, the write takes the row's lock. `AllocateNumber` holds that lock while
 * it increments, so an operator saving the prefix at the moment a customer
 * checks out waits for the number to be handed out rather than overwriting the
 * increment with a stale read.
 *
 * `period_key` is deliberately **not** settable. It is bookkeeping that
 * `AllocateNumber` owns; a form that could write it is a form that can make a
 * sequence restart mid-year.
 */
final readonly class SaveNumberSequence
{
    private const array AUDITED = ['prefix', 'padding', 'next_value', 'reset_period'];

    public function handle(
        NumberSequence $sequence,
        string $prefix,
        int $padding,
        int $nextValue,
        NumberResetPeriod $resetPeriod,
        ?Model $actor = null,
    ): NumberSequence {
        /** @var array{0: NumberSequence, 1: array<string, mixed>} $saved */
        $saved = DB::transaction(function () use ($sequence, $prefix, $padding, $nextValue, $resetPeriod): array {
            $locked = NumberSequence::query()
                ->withoutGlobalScope('organization')
                ->whereKey($sequence->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Read before the fill, not from `getOriginal()` afterwards: saving
            // moves the original, so the audit row would record the new value
            // twice and say nothing changed.
            $before = $locked->only(self::AUDITED);

            $locked->fill([
                'prefix' => $prefix,
                'padding' => $padding,
                'next_value' => $nextValue,
                'reset_period' => $resetPeriod->value,
            ])->save();

            return [$locked, $before];
        });

        [$sequence, $before] = $saved;

        Audit::action('billing.numbering.updated')
            ->by($actor)
            ->on($sequence)
            ->forOrganization($sequence->organization_id)
            ->changed($before, $sequence->only(self::AUDITED))
            ->write();

        return $sequence;
    }
}
