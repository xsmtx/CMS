<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Billing\RaiseInvoiceForOrder;
use App\Application\Ordering\CheckoutAccount;
use App\Application\Ordering\PlaceOrder;
use App\Application\Ordering\PlaceOrderRequest;
use App\Application\Ordering\PriceCart;
use App\Application\Ordering\RegisterCheckoutAccount;
use App\Application\Ordering\ResolveCart;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Crm\AddressType;
use App\Domain\Shared\Money;
use App\Domain\Tax\TaxableSupply;
use App\Http\Concerns\PresentsCartTotals;
use App\Http\Requests\Ordering\CheckoutRequest;
use App\Infrastructure\Billing\Models\Invoice;
use App\Infrastructure\Identity\Models\Contact;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\Order;
use App\Support\Catalog\StorefrontCurrency;
use App\Support\Identity\CurrentActor;
use App\Support\Organizations\OrganizationContext;
use App\Support\View\StorefrontRenderer;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Checkout: the account, the terms, and the order.
 *
 * A signed-in contact orders against their own customer. A visitor supplies
 * their details and gets an account they reach through the reset flow —
 * which is also where email verification finally bites, since the address
 * has to be proved before the account works.
 */
final class StorefrontCheckoutController extends Controller
{
    use PresentsCartTotals;

    public function __construct(
        private readonly ResolveCart $carts,
        private readonly PriceCart $pricer,
        private readonly StorefrontCurrency $currency,
        private readonly StorefrontRenderer $renderer,
        private readonly CurrentActor $actor,
    ) {}

    public function show(): Renderable|RedirectResponse
    {
        $cart = $this->carts->current();

        if (! $cart instanceof Cart || $cart->isEmpty()) {
            return to_route('storefront.cart');
        }

        $contact = $this->signedInContact();

        return $this->renderer->render('checkout', [
            'brand' => config('app.name'),
            'currency' => $this->currency->current(),
            'currencies' => $this->currency->available(),
            'cart' => $this->present($this->pricer->handle($cart, $this->supplyFor($contact, $cart))),
            'contact' => $contact === null ? null : [
                'name' => $contact->displayName(),
                'email' => $contact->email,
                'company' => $contact->customer?->company_name,
            ],
            'termsVersion' => (string) config('platform.ordering.terms_version', '1'),
        ]);
    }

    public function store(
        CheckoutRequest $request,
        PlaceOrder $placeOrder,
        RaiseInvoiceForOrder $invoices,
        RegisterCheckoutAccount $register,
        OrganizationContext $context,
    ): RedirectResponse {
        $cart = $this->carts->current();

        if (! $cart instanceof Cart) {
            return to_route('storefront.cart');
        }

        $contact = $this->signedInContact();
        $created = false;

        if ($contact === null) {
            $contact = $register->handle(
                (string) $context->id(),
                new CheckoutAccount(
                    firstName: $request->string('first_name')->toString(),
                    lastName: $request->string('last_name')->toString(),
                    email: $request->string('email')->toString(),
                    currencyCode: $cart->currency_code,
                    company: $request->input('company'),
                    phone: $request->input('phone'),
                    taxId: $request->input('tax_id'),
                    addressLine: $request->input('address_line'),
                    city: $request->input('city'),
                    postalCode: $request->input('postal_code'),
                    countryCode: $request->input('country_code'),
                    marketingOptIn: $request->boolean('marketing_opt_in'),
                ),
            );

            $created = true;
        }

        $order = $placeOrder->handle($cart, new PlaceOrderRequest(
            customerId: (string) $contact->customer_id,
            contactId: $contact->id,
            termsAccepted: $request->boolean('terms'),
            expectedTotalMinor: (int) $request->input('expected_total'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            notes: $request->input('notes'),
        ), $this->actor->model());

        $this->carts->forget();

        $invoice = $invoices->handle($order, $contact);

        if ($invoice instanceof Invoice) {
            // An account created at checkout has no password yet, so the
            // browser that placed the order is the only proof of identity
            // there is until the reset mail arrives. It is the same proof
            // the confirmation page runs on.
            StorefrontInvoiceController::remember($request, $invoice);
        }

        return to_route('storefront.order', $order->number)
            ->with('status', __('ordering.checkout.placed', ['number' => $order->number]))
            ->with('accountCreated', $created);
    }

    /**
     * The confirmation page.
     *
     * Reachable by order number, but only for the browser that placed it or
     * the contact it belongs to: an order number is short enough to guess
     * at, and it names a customer.
     */
    public function confirmation(Request $request, string $number): Renderable
    {
        $order = Order::query()->where('number', $number)->first();

        if (! $order instanceof Order) {
            throw new NotFoundHttpException;
        }

        $contact = $this->signedInContact();
        $placedHere = $request->session()->get('status') !== null;

        // Two nulls are not a match. An order with no contact belongs to
        // nobody, and a page that says otherwise hands a customer's name to
        // whoever guesses a number.
        $belongsToViewer = $contact !== null && $order->contact_id === $contact->id;

        if (! $placedHere && ! $belongsToViewer) {
            throw new NotFoundHttpException;
        }

        $order->load('items.options', 'items.children');

        $invoice = Invoice::query()
            ->where('order_id', $order->id)
            ->whereIn('status', InvoiceStatus::owed())
            ->first();

        return $this->renderer->render('order-confirmation', [
            'brand' => config('app.name'),
            'currency' => $order->currency_code,
            'currencies' => $this->currency->available(),
            'order' => [
                'number' => $order->number,
                'status' => (string) __($order->status->labelKey()),
                'total' => $order->total->format(app()->getLocale()),
                'recurringTotal' => $order->recurring_total->isZero()
                    ? null
                    : $order->recurring_total->format(app()->getLocale()),
                'accountCreated' => (bool) $request->session()->get('accountCreated', false),
            ],
            'invoice' => $invoice === null ? null : [
                'number' => $invoice->number,
                'due' => $invoice->balance()->format(app()->getLocale()),
                'url' => route('storefront.invoice', $invoice->number),
            ],
        ]);
    }

    private function signedInContact(): ?Contact
    {
        $model = $this->actor->model();

        return $model instanceof Contact ? $model : null;
    }

    /**
     * Where the supply happens, for tax. Unknown for a visitor who has not
     * typed an address yet, which the calculator is entitled to treat as
     * "no rule applies".
     */
    private function supplyFor(?Contact $contact, Cart $cart): TaxableSupply
    {
        $customer = $contact?->customer;
        $address = $customer?->addressFor(AddressType::Billing);
        $taxId = $customer === null ? null : $customer->tax_id;

        return new TaxableSupply(
            amount: Money::zero($cart->currency_code),
            countryCode: $address?->country_code,
            stateCode: $address?->region,
            postalCode: $address?->postal_code,
            taxId: $taxId,
            isBusiness: $taxId !== null && $taxId !== '',
            supplierCountryCode: config('platform.tax.flat.country'),
        );
    }
}
