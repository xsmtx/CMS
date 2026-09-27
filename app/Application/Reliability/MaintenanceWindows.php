<?php

declare(strict_types=1);

namespace App\Application\Reliability;

use App\Domain\Reliability\Exceptions\MaintenanceRefused;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Reliability\Models\MaintenanceWindow;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;

/**
 * Planning work, and calling it off (§16).
 *
 * **A window suppresses a notification, never an observation.** The alert is
 * still raised, still counted and still on the screen; what the window
 * changes is whether anybody is woken, and the alert carries the window that
 * held it. An operator asking "did anything happen during the maintenance"
 * has to get the true answer, and a platform that dropped the reading could
 * not give one.
 *
 * **A window is never edited into the past and never deleted.** It can be
 * cancelled, which is a different record: "nobody warned us" and "we warned
 * you and then called it off" are different conversations, and only one of
 * them is the operator's fault.
 */
final readonly class MaintenanceWindows
{
    /**
     * The window currently suppressing notifications for a subject, if any.
     *
     * Asked per alert rather than cached per sweep: a window that starts at
     * 02:00 must start suppressing at 02:00, not at whatever moment the
     * worker last looked something up.
     */
    public function covering(string $organizationId, string $nodeKey, ?CarbonImmutable $at = null): ?MaintenanceWindow
    {
        foreach (MaintenanceWindow::query()->active($at)->where('organization_id', $organizationId)->get() as $window) {
            if ($window->covers($nodeKey)) {
                return $window;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $nodeKeys
     */
    public function schedule(
        string $organizationId,
        string $title,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $body = null,
        array $nodeKeys = [],
        bool $public = false,
        ?StaffUser $actor = null,
    ): MaintenanceWindow {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw MaintenanceRefused::endsBeforeItStarts();
        }

        $window = MaintenanceWindow::query()->create([
            'organization_id' => $organizationId,
            'title' => $title,
            'body' => $body,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_public' => $public,
            // Deduplicated and re-indexed: a list with the same machine twice
            // is a list somebody pasted, and `covers()` should not care.
            'node_keys' => array_values(array_unique($nodeKeys)),
            'created_by' => $actor?->id,
        ]);

        Audit::action('reliability.maintenance.scheduled')
            ->by($actor)
            ->on($window)
            ->forOrganization($organizationId)
            ->because($title)
            ->withMetadata([
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
                'public' => $public,
                'nodes' => count($nodeKeys),
            ])
            ->write();

        return $window;
    }

    /**
     * Call it off.
     *
     * Only before it has ended: cancelling one that already ran would be
     * rewriting what happened, and the alerts it suppressed carry its id.
     */
    public function cancel(MaintenanceWindow $window, ?StaffUser $actor = null, ?string $reason = null): MaintenanceWindow
    {
        if ($window->isCancelled()) {
            return $window;
        }

        if ($window->hasEnded()) {
            throw MaintenanceRefused::alreadyOver($window->title);
        }

        $window->cancelled_at = CarbonImmutable::now();
        $window->save();

        Audit::action('reliability.maintenance.cancelled')
            ->by($actor)
            ->on($window)
            ->forOrganization($window->organization_id)
            ->because($reason ?? $window->title)
            ->write();

        return $window;
    }
}
