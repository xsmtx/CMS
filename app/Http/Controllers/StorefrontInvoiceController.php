<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Billing\StartPayment;
use App\Domain\Billing\Contracts\PaymentGateway;
use App\Infrastructure\Billing\GatewayRegistry;
use App\Infrastructure\Billing\Gateways\ManualGateway;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Identity\Models\Contact;
use App\Support\Catalog\StorefrontCurrency;
use App\Support\Identity\CurrentActor;
use App\Support\View\StorefrontRenderer;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Where a customer pays an invoice.
 *
 * Reachable only by the customer it belongs to — an invoice names a person
 * and says what they bought. The full client billing area is Phase 5; this
 * is the one page an unpaid invoice needs in order to be payable.
 *
 * The return from a gateway confirms nothing. It shows a page saying the
 * payment is being checked, and the webhook decides.
 */
final class StorefrontInvoiceController extends Controller
{
    public function __construct(
        private readonly StorefrontRenderer $renderer,
        private readonly StorefrontCurrency $currency,
        private readonly CurrentActor $actor,
        private readonly GatewayRegistry $gateways,
    ) {}

    public function show(string $number): Renderable
    {
        $invoice = $this->findForViewer($number);

        $invoice->load('items');

        return $this->renderer->render('invoice', [
            'brand' => config('app.name'),
            'currency' => $this->currency->current(),
            'currencies' => $this->currency->available(),
            'invoice' => $this->present($invoice),
            'gateways' => $this->gatewayOptions($invoice),
        ]);
    }

    public function pay(Request $request, string $number, StartPayment $start): RedirectResponse
    {
        $invoice = $this->findForViewer($number);

        $result = $start->handle(
            $invoice,
            $request->string('gateway')->toString(),
            route('storefront.invoice.returned', $invoice->number),
        );

        if ($result->needsRedirect()) {
            return redirect()->away((string) $result->redirectUrl);
        }

        return to_route('storefront.invoice.returned', $invoice->number);
    }

    /**
     * Where a gateway sends the customer back to.
     *
     * Deliberately says nothing about success. Whatever the query string
     * claims, the invoice on this page reflects only what a verified
     * webhook has already recorded.
     */
    public function returned(string $number): Renderable
    {
        $invoice = $this->findForViewer($number);

        $invoice->load('items');

        return $this->renderer->render('invoice-returned', [
            'brand' => config('app.name'),
            'currency' => $this->currency->current(),
            'currencies' => $this->currency->available(),
            'invoice' => $this->present($invoice),
        ]);
    }

    private function findForViewer(string $number): Invoice
    {
        $contact = $this->actor->model();

        if (! $contact instanceof Contact) {
            throw new NotFoundHttpException;
        }

        $invoice = Invoice::query()
            ->where('number', $number)
            ->where('customer_id', $contact->customer_id)
            ->first();

        if (! $invoice instanceof Invoice || ! $invoice->status->isIssued()) {
            throw new NotFoundHttpException;
        }

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Invoice $invoice): array
    {
        $locale = app()->getLocale();

        return [
            'number' => $invoice->number,
            'status' => (string) __($invoice->status->labelKey()),
            'isOwed' => $invoice->status->isOwed(),
            'currency' => $invoice->currency_code,
            'total' => $invoice->total->format($locale),
            'paid' => $invoice->paid->format($locale),
            'balance' => $invoice->balance()->format($locale),
            'issuedOn' => $invoice->issued_on?->toDateString(),
            'dueOn' => $invoice->due_on?->toDateString(),
            'isPastDue' => $invoice->isPastDue(),
            'items' => $invoice->items
                ->map(fn ($item): array => [
                    'description' => $item->description,
                    'detail' => $item->detail,
                    'quantity' => $item->quantity,
                    'lineAmount' => $item->line_amount->format($locale),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array{value: string, label: string, instructions: string|null}>
     */
    private function gatewayOptions(Invoice $invoice): array
    {
        return array_values(array_map(
            static fn (PaymentGateway $gateway): array => [
                'value' => $gateway->key(),
                'label' => (string) __('billing.gateways.'.$gateway->key()),
                'instructions' => $gateway instanceof ManualGateway && $gateway->instructions() !== ''
                    ? $gateway->instructions()
                    : null,
            ],
            $this->gateways->forCurrency($invoice->currency_code),
        ));
    }
}
