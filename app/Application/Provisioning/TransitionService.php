<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Application\Provisioning\Exceptions\InvalidServiceTransition;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Provisioning\Models\Service;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place a service changes state.
 *
 * The enum decides what is allowed; this writes the timestamps that go with
 * it and the audit record. Everything else in the provisioning code asks
 * here rather than assigning `status` directly, so "how did this service
 * get to terminated" always has an answer.
 */
final readonly class TransitionService
{
    public function handle(
        Service $service,
        ServiceStatus $next,
        ?Model $actor = null,
        ?string $reason = null,
    ): Service {
        $current = $service->status;

        if ($current === $next) {
            return $service;
        }

        if (! $current->canTransitionTo($next)) {
            throw InvalidServiceTransition::between($current, $next);
        }

        $attributes = ['status' => $next->value];

        $attributes += match ($next) {
            ServiceStatus::Active => [
                'provisioned_at' => $service->provisioned_at ?? CarbonImmutable::now(),
                'suspended_at' => null,
                'suspension_reason' => null,
                'failure_reason' => null,
            ],
            ServiceStatus::Suspended => [
                'suspended_at' => CarbonImmutable::now(),
                'suspension_reason' => $reason,
            ],
            ServiceStatus::Terminated => [
                'terminated_at' => CarbonImmutable::now(),
                'ends_on' => CarbonImmutable::now()->toDateString(),
            ],
            ServiceStatus::Failed => ['failure_reason' => $reason],
            default => [],
        };

        $service->forceFill($attributes)->save();

        Audit::action('provisioning.service.'.$next->value)
            ->by($actor)
            ->on($service)
            ->forOrganization($service->organization_id)
            ->because($reason)
            ->withMetadata(['from' => $current->value, 'to' => $next->value])
            ->write();

        return $service;
    }
}
