<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domain\Crm\AddressType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\BillingDetailsRequest;
use App\Infrastructure\Billing\Models\PaymentMethod;
use App\Infrastructure\Crm\Models\Address;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use App\Support\Identity\CurrentCustomer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What goes on the next invoice, and how it will be paid.
 *
 * The billing address and tax id are copied onto an invoice when it is
 * issued and never read again ([ADR 0023](../../docs/adr/0023-issued-documents-are-frozen.md)),
 * so this screen changes the next document rather than the last one. It
 * says so, because a customer who corrects their address expecting last
 * month's invoice to change has been misled by every system that let them
 * think it would.
 *
 * Stored payment methods are listed, defaulted and removed here. Adding one
 * is a gateway-hosted flow and is not built: raw card data never reaches
 * this platform, and pretending otherwise with a form would be the single
 * worst thing in the codebase.
 */
final class BillingDetailsController extends Controller
{
    public function __construct(
        private readonly CurrentCustomer $customer,
        private readonly CurrentActor $actor,
    ) {}

    public function show(): Response
    {
        $this->authorizeBilling();

        $customer = $this->customer->model();
        $address = $customer->addressFor(AddressType::Billing);

        return Inertia::render('Client/Billing/Details', [
            'details' => [
                'companyName' => $customer->company_name,
                'legalName' => $customer->legal_name,
                'taxId' => $customer->tax_id,
                'currencyCode' => $customer->currency_code,
                'lineOne' => $address?->line_one,
                'lineTwo' => $address?->line_two,
                'city' => $address?->city,
                'region' => $address?->region,
                'postalCode' => $address?->postal_code,
                'countryCode' => $address?->country_code,
            ],
            'methods' => $this->customer
                ->owned(PaymentMethod::query())
                ->orderByDesc('is_default')
                ->latest()
                ->get()
                // Never the token. It is `$hidden` on the model as well;
                // this is the second lock on the same door.
                ->map(fn (PaymentMethod $method): array => [
                    'id' => $method->id,
                    'gateway' => (string) __('billing.gateways.'.$method->gateway),
                    'brand' => $method->brand,
                    'lastFour' => $method->last_four,
                    'expiry' => $method->expiry_month === null
                        ? null
                        : sprintf('%02d/%d', $method->expiry_month, $method->expiry_year),
                    'isDefault' => $method->is_default,
                ])
                ->values()
                ->all(),
            'can' => ['manage' => $this->actor->can('portal.payment_methods.manage')],
        ]);
    }

    public function update(BillingDetailsRequest $request): RedirectResponse
    {
        $this->authorizeBilling();

        $customer = $this->customer->model();
        $before = $customer->only(['company_name', 'legal_name', 'tax_id']);

        $customer->update([
            'company_name' => $request->input('company_name'),
            'legal_name' => $request->input('legal_name'),
            'tax_id' => $request->input('tax_id'),
        ]);

        $address = $customer->addressFor(AddressType::Billing);
        $attributes = [
            'type' => AddressType::Billing->value,
            'line_one' => $request->string('line_one')->toString(),
            'line_two' => $request->input('line_two'),
            'city' => $request->string('city')->toString(),
            'region' => $request->input('region'),
            'postal_code' => $request->input('postal_code'),
            'country_code' => mb_strtoupper($request->string('country_code')->toString()),
            'is_default' => true,
        ];

        if ($address instanceof Address) {
            $address->update($attributes);
        } else {
            $customer->addresses()->create([
                'organization_id' => $customer->organization_id,
                ...$attributes,
            ]);
        }

        Audit::action('portal.billing_details.updated')
            ->by($this->customer->contact())
            ->on($customer)
            ->changed($before, $customer->only(['company_name', 'legal_name', 'tax_id']))
            ->write();

        return back()->with('status', __('billing.details_saved'));
    }

    public function makeDefault(string $method): RedirectResponse
    {
        $stored = $this->findMethod($method);

        $this->customer
            ->owned(PaymentMethod::query())
            ->update(['is_default' => false]);

        $stored->forceFill(['is_default' => true])->save();

        Audit::action('portal.payment_method.default')
            ->by($this->customer->contact())
            ->on($stored)
            ->write();

        return back()->with('status', __('billing.methods.default_set'));
    }

    public function destroy(string $method): RedirectResponse
    {
        $stored = $this->findMethod($method);

        $stored->delete();

        Audit::action('portal.payment_method.removed')
            ->by($this->customer->contact())
            ->on($stored)
            ->withMetadata(['gateway' => $stored->gateway, 'last_four' => $stored->last_four])
            ->write();

        return back()->with('status', __('billing.methods.removed'));
    }

    private function findMethod(string $id): PaymentMethod
    {
        if (! $this->actor->can('portal.payment_methods.manage')) {
            throw new ForbiddenException(__('billing.not_permitted'));
        }

        /** @var PaymentMethod $method */
        $method = $this->customer->find(PaymentMethod::query()->whereKey($id));

        return $method;
    }

    private function authorizeBilling(): void
    {
        if (! $this->actor->can('portal.billing.view')) {
            throw new ForbiddenException(__('billing.not_permitted'));
        }
    }
}
