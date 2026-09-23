<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Application\Billing\Exceptions\OrderNotInvoiceable;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Model;

/**
 * The invoice a customer is sent the moment they place an order.
 *
 * Checkout asks for this, and so does an operator pressing the button on
 * the order screen. Both go through here so that "when does an order get an
 * invoice" has one answer rather than two.
 *
 * Three rules:
 *
 * - An order held for review is not invoiced. Asking somebody to pay for
 *   something we have not decided to sell them is the wrong order of
 *   events, and refunding it afterwards is worse.
 * - An order that costs nothing gets no invoice. There is nothing to
 *   collect and a zero-total tax document only confuses an accountant.
 * - Asking twice returns the invoice that already exists. A double-
 *   submitted form, a retried job and a refreshed confirmation page must
 *   not each produce a document.
 */
final readonly class RaiseInvoiceForOrder
{
    public function __construct(
        private CreateInvoiceFromOrder $create,
        private IssueInvoice $issue,
    ) {}

    /**
     * @param  bool  $notify  false when the desk will hand the invoice over itself
     */
    public function handle(Order $order, ?Model $actor = null, bool $notify = true): ?Invoice
    {
        $existing = $this->existing($order);

        if ($existing instanceof Invoice) {
            return $existing;
        }

        if (! $this->shouldInvoice($order)) {
            return null;
        }

        try {
            $invoice = $this->create->handle($order, $actor);
        } catch (OrderNotInvoiceable) {
            // Something else raised it between the check and here. The
            // document that exists is the right answer.
            return $this->existing($order);
        }

        return $this->issue->handle($invoice, $actor, $notify);
    }

    private function shouldInvoice(Order $order): bool
    {
        if ($order->total->isZero()) {
            return false;
        }

        return $order->status === OrderStatus::AwaitingPayment;
    }

    private function existing(Order $order): ?Invoice
    {
        return Invoice::query()
            ->where('order_id', $order->id)
            ->whereNot('status', InvoiceStatus::Cancelled->value)
            ->first();
    }
}
