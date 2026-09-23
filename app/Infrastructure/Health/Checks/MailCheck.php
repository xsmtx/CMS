<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Health\HealthReport;
use App\Domain\Notifications\DeliveryStatus;
use App\Infrastructure\Notifications\Models\NotificationDelivery;
use App\Support\Organizations\OrganizationContext;
use Carbon\CarbonImmutable;

/**
 * Is mail actually going out.
 *
 * Read from the delivery log rather than by opening an SMTP connection.
 * Connecting proves the host answers; the log proves that messages this
 * platform actually tried to send reached a transport that accepted them,
 * which is the thing an operator wants to know.
 *
 * A day with no mail at all is reported as ok, not degraded. A small
 * installation on a quiet Sunday has sent nothing, and that is not a fault.
 */
final readonly class MailCheck implements HealthCheck
{
    public function __construct(private OrganizationContext $organizations) {}

    public function key(): string
    {
        return 'mail';
    }

    public function run(): HealthReport
    {
        $since = CarbonImmutable::now()->subDay();

        /** @var array{sent: int, failed: int} $counts */
        $counts = $this->organizations->withoutBoundary(
            static fn (): array => [
                'sent' => NotificationDelivery::query()
                    ->where('created_at', '>=', $since)
                    ->where('status', DeliveryStatus::Sent->value)
                    ->count(),
                'failed' => NotificationDelivery::query()
                    ->where('created_at', '>=', $since)
                    ->where('status', DeliveryStatus::Failed->value)
                    ->count(),
            ],
        );

        $mailer = (string) config('mail.default', 'log');

        if ($mailer === 'log' || $mailer === 'array') {
            // Not a fault, but an operator should know before a customer
            // tells them their password reset never arrived.
            return HealthReport::degraded($this->key(), (string) __('health.mail.not_configured'), $counts);
        }

        if ($counts['failed'] > 0 && $counts['failed'] >= $counts['sent']) {
            return HealthReport::failing($this->key(), (string) __('health.mail.failing'), $counts);
        }

        if ($counts['failed'] > 0) {
            return HealthReport::degraded($this->key(), (string) __('health.mail.some_failed'), $counts);
        }

        return HealthReport::ok($this->key(), $counts);
    }
}
