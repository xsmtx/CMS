<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Notifications\Listeners\SendEventNotifications;
use App\Application\Notifications\Listeners\SendTicketNotifications;
use App\Domain\Billing\Events\InvoiceIssued;
use App\Domain\Billing\Events\PaymentFailed;
use App\Domain\Billing\Events\PaymentReceived;
use App\Domain\Domains\Events\DomainRegistered;
use App\Domain\Ordering\Events\OrderPaid;
use App\Domain\Provisioning\Events\ServiceProvisioned;
use App\Domain\Provisioning\Events\ServiceSuspended;
use App\Domain\Provisioning\Events\ServiceTerminated;
use App\Domain\Support\Events\TicketOpened;
use App\Domain\Support\Events\TicketReplied;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Notifications\ChannelRegistry;
use App\Infrastructure\Notifications\Channels\DatabaseChannel;
use App\Infrastructure\Notifications\Channels\MailChannel;
use App\Infrastructure\Notifications\Channels\WebhookChannel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the channels this installation can send through, and wires
 * every event that produces a message.
 *
 * The listener map is here, in one readable list, rather than discovered:
 * "what does this platform email people about" should be answerable by
 * reading a file, not by grepping for method signatures.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRegistry::class, function (Application $app): ChannelRegistry {
            $registry = new ChannelRegistry;

            $registry->register(new MailChannel);
            $registry->register(new DatabaseChannel);

            $endpoint = config('platform.notifications.webhook.endpoint');
            $secret = config('platform.notifications.webhook.secret');

            // Both, or neither. An endpoint with no secret is an endpoint
            // that cannot tell our calls from anybody else's.
            if (is_string($endpoint) && $endpoint !== '' && is_string($secret) && $secret !== '') {
                $registry->register(new WebhookChannel(
                    endpoint: $endpoint,
                    secret: $secret,
                    timeout: (int) config('platform.notifications.webhook.timeout', 10),
                ));
            }

            // And whatever the enabled modules add. Asked last, so a module
            // cannot displace an adapter this installation ships with: a
            // registry keys by name, and core has already claimed its own.
            foreach ($app->make(ActiveModules::class)->channels() as $channel) {
                $registry->register($channel);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        Event::listen(OrderPaid::class, [SendEventNotifications::class, 'orderPaid']);
        Event::listen(InvoiceIssued::class, [SendEventNotifications::class, 'invoiceIssued']);
        Event::listen(PaymentReceived::class, [SendEventNotifications::class, 'paymentReceived']);
        Event::listen(PaymentFailed::class, [SendEventNotifications::class, 'paymentFailed']);

        Event::listen(ServiceProvisioned::class, [SendEventNotifications::class, 'serviceProvisioned']);
        Event::listen(ServiceSuspended::class, [SendEventNotifications::class, 'serviceSuspended']);
        Event::listen(ServiceTerminated::class, [SendEventNotifications::class, 'serviceTerminated']);

        Event::listen(DomainRegistered::class, [SendEventNotifications::class, 'domainRegistered']);

        Event::listen(TicketOpened::class, [SendTicketNotifications::class, 'opened']);
        Event::listen(TicketReplied::class, [SendTicketNotifications::class, 'replied']);
    }
}
