<?php

declare(strict_types=1);

namespace App\Application\Tax;

use App\Infrastructure\Tax\Models\TaxRule;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Taking a rule away.
 *
 * Deleted rather than deactivated, because a rule an operator wrote by mistake
 * should be removable — and the one that was *right until March* is a row with an
 * `ends_on`, not a deleted one. Both are in the audit log either way.
 */
final readonly class DeleteTaxRule
{
    /**
     * The reason is not paperwork.
     *
     * A rule that stops existing stops being charged, and nothing about the
     * invoices already raised changes — so the first sign anybody has is a
     * document that came out wrong weeks later. The person reading this row
     * then is trying to work out why.
     */
    public function handle(TaxRule $rule, ?Model $actor = null, ?string $reason = null): void
    {
        Audit::action('tax.rule.deleted')
            ->by($actor)
            ->on($rule)
            ->forOrganization($rule->organization_id)
            ->because($reason)
            ->withMetadata(['rate' => $rule->percentage(), 'country' => $rule->country_code])
            ->write();

        $rule->delete();
    }
}
