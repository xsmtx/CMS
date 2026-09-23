<?php

declare(strict_types=1);

namespace App\Application\Provisioning;

use App\Application\Provisioning\Exceptions\InvalidServiceTransition;
use App\Domain\Provisioning\AddonStatus;
use App\Domain\Provisioning\ServiceStatus;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Provisioning\Models\ServiceAddon;
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
 *
 * **The addons follow.** An addon is part of the account, so suspending a
 * service suspends the extra backup space with it and resuming brings it
 * back (ADR 0035). One place moves both, for the same reason one place
 * moves the service: two is one too many. An addon the customer has
 * already dropped stays dropped — "we turned the account back on" does not
 * undo "they asked to stop paying for this".
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

        $this->cascadeToAddons($service, $next);

        Audit::action('provisioning.service.'.$next->value)
            ->by($actor)
            ->on($service)
            ->forOrganization($service->organization_id)
            ->because($reason)
            ->withMetadata(['from' => $current->value, 'to' => $next->value])
            ->write();

        return $service;
    }

    /**
     * Move the addons that should move, and leave the rest alone.
     *
     * Asked per addon rather than with one `update`, because what an addon
     * becomes depends on where it is: a terminated one stays terminated,
     * and a cancellation the customer asked for survives a suspension.
     */
    private function cascadeToAddons(Service $service, ServiceStatus $next): void
    {
        $addons = ServiceAddon::query()->where('service_id', $service->id)->get();

        foreach ($addons as $addon) {
            $following = $addon->status->follows($next);

            if ($following === null) {
                continue;
            }

            $addon->forceFill([
                'status' => $following->value,
                'suspended_at' => $following === AddonStatus::Suspended ? CarbonImmutable::now() : null,
                'terminated_at' => $following === AddonStatus::Terminated
                    ? CarbonImmutable::now()
                    : $addon->terminated_at,
                'ends_on' => $following === AddonStatus::Terminated
                    ? CarbonImmutable::now()->toDateString()
                    : $addon->ends_on,
            ])->save();
        }
    }
}
