<?php

declare(strict_types=1);

namespace App\Application\Domains;

use App\Application\Domains\Exceptions\InvalidDomainTransition;
use App\Domain\Domains\DomainStatus;
use App\Infrastructure\Domains\Models\Domain;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place a domain changes state.
 *
 * The enum decides what is allowed; this writes the dates that go with it
 * and the audit record. Everything else asks here rather than assigning
 * `status` directly, so "how did this domain end up deleted" always has an
 * answer.
 */
final readonly class TransitionDomain
{
    public function handle(
        Domain $domain,
        DomainStatus $next,
        ?Model $actor = null,
        ?string $reason = null,
    ): Domain {
        $current = $domain->status;

        if ($current === $next) {
            return $domain;
        }

        if (! $current->canTransitionTo($next)) {
            throw InvalidDomainTransition::between($current, $next);
        }

        $attributes = ['status' => $next->value];

        $attributes += match ($next) {
            DomainStatus::Active => [
                'registered_on' => $domain->registered_on ?? CarbonImmutable::now()->toDateString(),
                'failure_reason' => null,
            ],
            DomainStatus::Failed => ['failure_reason' => $reason],
            default => [],
        };

        $domain->forceFill($attributes)->save();

        Audit::action('domains.domain.'.$next->value)
            ->by($actor)
            ->on($domain)
            ->forOrganization($domain->organization_id)
            ->because($reason)
            ->withMetadata(['from' => $current->value, 'to' => $next->value])
            ->write();

        return $domain;
    }
}
