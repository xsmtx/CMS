<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Domains\Models\Domain;
use App\Infrastructure\Ordering\Models\Order;
use App\Infrastructure\Provisioning\Models\Service;
use App\Infrastructure\Support\Models\Ticket;

/**
 * Where a message's button lands (§26).
 *
 * **One place, because the mistake is invisible.** A notification's action URL
 * is built by a listener, rendered into an email, and opened by somebody days
 * later — nothing between those three steps checks that the path exists or
 * that the value in it is the key the route binds on. A listener sent
 * `/client/orders/{ulid}` to a route that looks an order up by its **number**,
 * so the first message this platform ever sends a customer — their order
 * confirmation — arrived with a link that answered 404. Every test passed: the
 * message was sent, the delivery row was written, and the string in it was
 * never asked to resolve.
 *
 * So the links live here, named after the thing rather than the path, and
 * `DeepLinkTest` opens every one of them as the person who would receive it.
 *
 * **Two areas, and which one is the audience's.** A link sent to a customer
 * goes to the portal and one sent to staff goes to the console; handing a
 * customer an `/admin` URL is the other half of this bug, and it is the half
 * that leaks rather than 404s.
 */
final readonly class DeepLink
{
    /** The customer's copy of an order. Bound on the **number**, not the id. */
    public function order(Order $order): string
    {
        return url('/client/orders/'.$order->number);
    }

    /** An invoice, bound on its number. */
    public function invoice(Invoice $invoice): string
    {
        return url('/client/billing/invoices/'.$invoice->number);
    }

    /** A service, bound on its id. */
    public function service(Service $service): string
    {
        return url('/client/services/'.$service->id);
    }

    /** A domain, bound on its id. */
    public function domain(Domain $domain): string
    {
        return url('/client/domains/'.$domain->id);
    }

    public function customerTicket(Ticket $ticket): string
    {
        return url('/client/support/'.$ticket->id);
    }

    public function staffTicket(Ticket $ticket): string
    {
        return url('/admin/support/'.$ticket->id);
    }

    /**
     * The alerts screen.
     *
     * A list rather than one alert, because there is no page for a single
     * one — and at three in the morning the list is the more useful landing
     * anyway: whatever woke somebody is rarely the only thing wrong.
     */
    public function alerts(): string
    {
        return url('/admin/reliability/alerts');
    }

    /** The public status page, which needs no session at all. */
    public function status(): string
    {
        return url('/status');
    }
}
