<?php

declare(strict_types=1);

namespace App\Application\Notifications\Listeners;

use App\Application\Notifications\Notifier;
use App\Application\Notifications\ResolveRecipients;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Reliability\Events\AlertRaised;
use App\Infrastructure\Reliability\Models\Alert;
use App\Infrastructure\Reliability\Models\AlertRule;
use App\Support\Organizations\OrganizationContext;

/**
 * Who is woken by an alert (§15, §16).
 *
 * Three rules, and each is the difference between an alerting system people
 * keep switched on and one they filter into a folder:
 *
 * - **A maintenance window sends nothing.** The alert still exists, still
 *   counts and is still on the screen carrying the window that held it. What
 *   the window buys is that nobody is woken at three in the morning by the
 *   work they themselves scheduled for three in the morning.
 * - **A warning does not interrupt anybody.** `AlertSeverity::interrupts()`
 *   is the platform's own definition of "somebody is told now", and a
 *   warning that woke people would be a warning they turn off — which takes
 *   the criticals with it.
 * - **Staff, and every one of them.** There is no on-call rotation here and
 *   `phase-d-plan.md` says so on purpose: routing and escalation are a
 *   product of their own, and inventing half of one would be worse than
 *   leaving it to the rota people already keep.
 */
final readonly class SendAlertNotifications
{
    public function __construct(
        private Notifier $notifier,
        private ResolveRecipients $recipients,
        private OrganizationContext $organizations,
    ) {}

    public function raised(AlertRaised $event): void
    {
        // A window held it. Nothing is sent, and the alert's own row already
        // records which window and why.
        if ($event->suppressedBy !== null) {
            return;
        }

        $alert = $this->organizations->withoutBoundary(
            static fn (): ?Alert => Alert::query()
                ->withoutGlobalScope('organization')
                ->with('rule')
                ->find($event->alertId),
        );

        if (! $alert instanceof Alert) {
            return;
        }

        if (! $alert->severity->interrupts()) {
            return;
        }

        $recipients = $this->recipients->staffFor();

        if ($recipients === []) {
            return;
        }

        $this->notifier->send(
            NotificationEvent::AlertRaised,
            $recipients,
            [
                'subject' => $alert->subject_label,
                'rule' => $alert->rule instanceof AlertRule ? $alert->rule->name : '',
                'severity' => (string) __($alert->severity->labelKey()),
                'observed' => $alert->observed ?? '',
            ],
            // A deep link, not a list to hunt through: somebody reading this
            // on a phone at three in the morning has one press to make.
            url('/admin/reliability/alerts'),
            organizationId: $event->organizationId,
        );
    }
}
