<?php

declare(strict_types=1);

namespace App\Application\Automation\Runs;

use App\Application\Notifications\Notifier;
use App\Application\Notifications\ResolveRecipients;
use App\Domain\Automation\Contracts\AutomationRun;
use App\Domain\Automation\ItemOutcome;
use App\Domain\Automation\RunItem;
use App\Domain\Automation\RunSummary;
use App\Domain\Domains\DomainStatus;
use App\Domain\Notifications\NotificationEvent;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Domains\Models\Domain;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Tells a customer their domain is about to expire.
 *
 * A domain is the one thing in this platform a customer can lose
 * permanently by not acting. A service that lapses is suspended and can be
 * brought back; a domain that lapses goes to redemption at a penalty and
 * then to somebody else.
 *
 * So the notice goes out at several distances — thirty days, seven, one —
 * and **for a domain with auto-renew off as well as on**. An owner who
 * turned auto-renew off may have meant it, or may have turned it off two
 * years ago and forgotten.
 *
 * The guard against sending the same notice twice is two columns on the
 * domain: the narrowest window already sent, and the expiry date it was
 * about. Two rather than one so that a domain whose expiry moves — because
 * it renewed, or because a sync corrected it — gets a fresh set of notices
 * without anything anywhere having to remember to reset a flag.
 */
final readonly class NotifyExpiringDomains implements AutomationRun
{
    public function __construct(
        private OrganizationContext $organizations,
        private Notifier $notifier,
        private ResolveRecipients $recipients,
    ) {}

    public function handle(): RunSummary
    {
        $summary = new RunSummary;
        $windows = $this->windows();
        $widest = max($windows);

        foreach ($this->expiring($widest) as $domain) {
            $summary = $summary->examining();

            try {
                $window = $this->windowFor($domain, $windows);

                if ($window === null || $this->alreadyTold($domain, $window)) {
                    $summary = $summary->skipping();

                    continue;
                }

                $this->tell($domain, $window);

                $summary = $summary->changing(new RunItem(
                    ItemOutcome::Changed,
                    Domain::class,
                    $domain->id,
                    $domain->name,
                    (string) $window,
                ));
            } catch (Throwable $exception) {
                $summary = $summary->failing(new RunItem(
                    ItemOutcome::Failed,
                    Domain::class,
                    $domain->id,
                    $domain->name,
                    $exception->getMessage(),
                ));
            }
        }

        return $summary;
    }

    /**
     * The narrowest window this domain has entered.
     *
     * A domain eleven days out is in the thirty-day window; one six days
     * out is in the seven-day window and has already had the thirty-day
     * notice. Taking the narrowest is what makes the notices progressive
     * rather than three copies of the same warning.
     *
     * @param  list<int>  $windows
     */
    private function windowFor(Domain $domain, array $windows): ?int
    {
        if ($domain->expires_on === null) {
            return null;
        }

        $days = (int) CarbonImmutable::now()->startOfDay()
            ->diffInDays($domain->expires_on->startOfDay(), absolute: false);

        $matching = array_values(array_filter($windows, static fn (int $window): bool => $days <= $window));

        return $matching === [] ? null : min($matching);
    }

    private function alreadyTold(Domain $domain, int $window): bool
    {
        if ($domain->expires_on === null) {
            return false;
        }

        if ($domain->expiry_notified_days === null || $domain->expiry_notified_for === null) {
            return false;
        }

        // A notice about a different expiry date is not a notice about this
        // one, however recent it was.
        if (! $domain->expiry_notified_for->isSameDay($domain->expires_on)) {
            return false;
        }

        // Windows narrow as the date approaches, so "already told about 7"
        // covers a second pass at 7 and not a first pass at 1.
        return $domain->expiry_notified_days <= $window;
    }

    private function tell(Domain $domain, int $window): void
    {
        $domain->loadMissing('customer');

        $customer = $domain->customer;

        if (! $customer instanceof Customer) {
            // A domain with no customer is an import that went wrong. Not
            // this task's problem to fix, and not its place to guess who
            // to write to.
            return;
        }

        $this->notifier->send(
            NotificationEvent::DomainExpiring,
            $this->recipients->forCustomer($customer, NotificationEvent::DomainExpiring),
            [
                'domain' => $domain->name,
                'expires_on' => $domain->expires_on?->toDateString() ?? '',
                'days' => (string) $window,
            ],
            url('/client/domains/'.$domain->id),
            organizationId: $domain->organization_id,
        );

        // After the send, not before: a notifier that could not reach
        // anybody should be tried again tomorrow rather than marked done.
        $domain->forceFill([
            'expiry_notified_days' => $window,
            'expiry_notified_for' => $domain->expires_on,
        ])->save();
    }

    /**
     * @return list<Domain>
     */
    private function expiring(int $days): array
    {
        return $this->organizations->withoutBoundary(
            static fn (): array => array_values(Domain::query()
                ->where('status', DomainStatus::Active->value)
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '>=', CarbonImmutable::now()->toDateString())
                ->whereDate('expires_on', '<=', CarbonImmutable::now()->addDays($days)->toDateString())
                ->with('customer')
                ->orderBy('expires_on')
                ->get()
                ->all()),
        );
    }

    /**
     * @return non-empty-list<int>
     */
    private function windows(): array
    {
        /** @var list<int> $windows */
        $windows = config('platform.automation.domain_expiry_windows', [30, 7, 1]);

        $windows = array_values(array_map(intval(...), $windows));

        // An installation that configured an empty list still gets one
        // notice. Silently sending nothing about an expiring domain is the
        // one outcome worth refusing.
        return $windows === [] ? [7] : $windows;
    }
}
