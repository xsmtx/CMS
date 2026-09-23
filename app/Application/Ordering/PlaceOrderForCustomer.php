<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Billing\RaiseInvoiceForOrder;
use App\Application\Domains\AddDomainToCart;
use App\Application\Notifications\Notifier;
use App\Application\Notifications\ResolveRecipients;
use App\Application\Ordering\Exceptions\CartNotOrderable;
use App\Domain\Domains\DomainAction;
use App\Domain\Domains\DomainOrderType;
use App\Domain\Notifications\NotificationEvent;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Ordering\Models\Cart;
use App\Infrastructure\Ordering\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * An order somebody placed over the phone.
 *
 * **It goes through the cart.** The obvious shortcut — write the order and
 * its lines straight from the form — would be a second place that prices a
 * sale, and the two would disagree the first time somebody changed a tax
 * rule or a promotion. So the operator's form builds a real cart, prices
 * it the way the storefront prices one, and hands it to `PlaceOrder`. The
 * only difference between an order taken on the phone and an order taken
 * on the web is who typed it.
 *
 * The cart is created here rather than resolved from the session. An
 * operator has a session of their own, and putting a customer's basket in
 * it would mean the next customer they serve inherits it.
 *
 * Nothing is trusted from the form. Every product, price, cycle and domain
 * is checked against the catalogue by the use cases that already do that,
 * and an override is an amount the operator agreed — not a price the form
 * is allowed to invent for a product that is not sold at all.
 */
final readonly class PlaceOrderForCustomer
{
    public function __construct(
        private AddToCart $items,
        private AddDomainToCart $domains,
        private ApplyPromotionCode $promotions,
        private PlaceOrder $orders,
        private RaiseInvoiceForOrder $invoices,
        private Notifier $notifier,
        private ResolveRecipients $recipients,
    ) {}

    public function handle(PlaceOrderForCustomerRequest $request, ?Model $actor = null): Order
    {
        $customer = Customer::query()->whereKey($request->customerId)->firstOrFail();

        if ($request->lines === [] && ! $request->wantsDomain()) {
            throw CartNotOrderable::empty();
        }

        $cart = $this->cartFor($customer);

        try {
            $this->fill($cart, $request);

            $order = $this->orders->handle($cart, new PlaceOrderRequest(
                customerId: $customer->id,
                contactId: $customer->primaryContact?->id,
                // The customer did not click anything, so `terms_accepted_at`
                // stays empty. `onBehalfBy` is what satisfies the check:
                // somebody on the phone agreed to something, and who
                // recorded it is in the audit trail.
                termsAccepted: false,
                notes: $request->notes,
                onBehalfBy: $actor?->getKey() === null ? 'desk' : (string) $actor->getKey(),
            ), $actor);
        } catch (Throwable $exception) {
            // A cart that never became an order is litter. Left behind it
            // would be picked up by the next thing that looks for one.
            $this->discard($cart);

            throw $exception;
        }

        return $this->settle($order, $request, $actor);
    }

    /**
     * A cart of its own, belonging to the customer and to nobody's session.
     */
    private function cartFor(Customer $customer): Cart
    {
        return Cart::query()->create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'contact_id' => $customer->primaryContact?->id,
            'token' => Str::ulid().Str::lower(Str::random(14)),
            'currency_code' => $customer->currency_code,
            // Short, because it exists for the length of one form
            // submission. A sweep that finds it later should bin it.
            'expires_at' => CarbonImmutable::now()->addHour(),
        ]);
    }

    private function fill(Cart $cart, PlaceOrderForCustomerRequest $request): void
    {
        foreach ($request->lines as $line) {
            $item = $this->items->handle($cart, new AddToCartRequest(
                productId: $line->productId,
                cycle: $line->cycle,
                quantity: $line->quantity,
                domain: $line->domain,
            ));

            if ($line->priceOverrideMinor !== null) {
                // Written on the line rather than applied to the total, so
                // the document says what was agreed for what — and so tax
                // and promotions see the agreed number rather than the
                // list price.
                $item->forceFill(['price_override_minor' => $line->priceOverrideMinor])->save();
            }
        }

        if ($request->wantsDomain()) {
            $this->domains->handle(
                $cart,
                (string) $request->domainName,
                max($request->domainYears, 1),
                $request->domainAction === DomainOrderType::Transfer
                    ? DomainAction::Transfer
                    : DomainAction::Register,
                $request->domainAddons,
                $request->domainRegistrationOverrideMinor,
            );
        }

        if ($request->promotionCode !== null && trim($request->promotionCode) !== '') {
            // A refusal is returned rather than thrown: an operator who
            // typed an expired code wants the order, and the reason, not a
            // lost form.
            $this->promotions->handle($cart, trim($request->promotionCode));
        }
    }

    /**
     * What the desk decided about the order once it exists.
     *
     * The status is **not** one of those decisions. `PlaceOrder` works it
     * out from the risk assessment and the total, exactly as it does for a
     * storefront order, and a screen that overrode it would be a second
     * opinion about fraud.
     *
     * Confirming and billing are separate switches because "raise the
     * invoice but do not email it yet" is a normal thing to want while
     * somebody is still on the phone. `sendEmail` gates both: it is the
     * one switch that says whether the customer hears anything at all.
     */
    private function settle(Order $order, PlaceOrderForCustomerRequest $request, ?Model $actor): Order
    {
        if ($request->generateInvoice) {
            $this->invoices->handle($order, $actor, $request->sendEmail);
        }

        if ($request->confirm && $request->sendEmail) {
            $this->confirm($order);
        }

        return $order->refresh();
    }

    /**
     * Tell the customer the order exists.
     *
     * Not a bespoke mail: the wording is a template an operator can edit
     * and the send is recorded in the delivery log like every other
     * (ADR 0029).
     */
    private function confirm(Order $order): void
    {
        $customer = $order->customer;

        if ($customer === null) {
            return;
        }

        $this->notifier->send(
            NotificationEvent::OrderPlaced,
            $this->recipients->forCustomer($customer, NotificationEvent::OrderPlaced),
            [
                'customer' => $customer->displayName(),
                'number' => $order->number,
                'total' => $order->total->format(app()->getLocale()),
            ],
            url('/client/orders/'.$order->id),
            organizationId: $order->organization_id,
        );
    }

    private function discard(Cart $cart): void
    {
        DB::transaction(function () use ($cart): void {
            $cart->allItems()->delete();
            $cart->delete();
        });
    }
}
