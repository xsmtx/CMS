<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Crm\ClientAddressAttributes;
use App\Application\Crm\ClientContactAttributes;
use App\Application\Crm\CreateClient;
use App\Application\Crm\CustomerAttributes;
use App\Application\Identity\AuthenticateUser;
use App\Domain\Crm\CustomerStatus;
use App\Domain\Identity\Guard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\RegisterRequest;
use App\Infrastructure\Identity\Models\Contact;
use App\Support\Catalog\StorefrontCurrency;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A visitor opening their own account.
 *
 * **Client guard only, and not in `routes/auth.php`.** Everything else in
 * that file is registered once per guard, because sign-in, password reset and
 * the two-factor challenge are the same flow on both surfaces. This one is
 * not: a staff account is created by `identity:create-owner` or by another
 * staff member, and a self-registration form that existed on the admin
 * surface would be a way to grant oneself a staff session. It is declared in
 * `routes/client.php` so that it cannot be mounted on the other guard by
 * accident.
 *
 * The parent organization comes from `ResolveStorefrontOrganization`, the
 * same seam the storefront and checkout take their boundary from — a visitor
 * registers with whoever's shop they are standing in, and that is not
 * something a request may state.
 *
 * The customer is created **active**, not pending. Pending would be a trap:
 * `PlaceOrder` refuses a customer who cannot transact, nothing in this
 * product moves an account out of pending on its own, and registering
 * creates no obligation there would be anything to withhold against. An
 * operator who wants to vet new accounts turns registration off
 * (`platform.crm.self_registration`) and creates them by hand.
 *
 * The address is not proved. Neither is it at checkout, where an account is
 * created on a browser's word as well; what proves it in both cases is the
 * first message this platform sends there. Registration therefore grants a
 * portal session and nothing else — no service, no credit, no order.
 */
final class RegisterController extends Controller
{
    public function __construct(
        private readonly StorefrontCurrency $currency,
    ) {}

    public function create(): Response
    {
        $this->refuseIfClosed();

        return Inertia::render('Auth/Register', [
            'loginUrl' => route('client.login'),
            'currency' => $this->defaultCurrency(),
            'currencies' => $this->currency->available(),
            'defaultCountry' => (string) config('platform.crm.default_country', ''),
            'phonePlaceholder' => (string) config('platform.crm.phone_placeholder', ''),
        ]);
    }

    public function store(
        RegisterRequest $request,
        CreateClient $clients,
        AuthenticateUser $authenticator,
        OrganizationContext $context,
    ): RedirectResponse {
        $this->refuseIfClosed();

        $customer = $clients->handle(
            (string) $context->id(),
            new CustomerAttributes(
                companyName: $request->input('company_name'),
                legalName: null,
                taxId: $request->input('tax_id'),
                // Unknown, and left that way rather than guessed at: the type
                // of a tax id is a fact about a jurisdiction, and core names
                // no jurisdiction.
                taxIdType: null,
                status: CustomerStatus::Active,
                currencyCode: $request->string('currency_code')->upper()->toString(),
                marketingOptIn: $request->boolean('marketing_opt_in'),
            ),
            new ClientContactAttributes(
                firstName: $request->string('first_name')->toString(),
                lastName: $request->string('last_name')->toString(),
                email: $request->string('email')->toString(),
                phone: $request->input('phone'),
                // The language they were reading the form in, so the first
                // message they get is in it.
                locale: app()->getLocale(),
                password: $request->string('password')->toString(),
            ),
            $this->address($request),
        );

        $contact = $customer->primaryContact;

        // Belt and braces. `CreateClient` writes the primary contact in the
        // same transaction as the customer, so this cannot be null — and if it
        // ever became null, signing nobody in is the right failure.
        if (! $contact instanceof Contact) {
            throw new NotFoundHttpException;
        }

        // The one path that grants a session: login history, the session
        // registry, session-fixation defence and the audit row all come with
        // it, and none of them can be forgotten here.
        $authenticator->complete(Guard::Client, $contact);

        return redirect()->to(Guard::Client->homePath())
            ->with('status', __('identity.register.welcome'));
    }

    /**
     * Where to invoice them, when they said.
     *
     * No street, no address: a country on its own is not one, and storing it
     * would produce an invoice addressed to a country.
     */
    private function address(RegisterRequest $request): ?ClientAddressAttributes
    {
        $line = $request->input('address_line_one');

        if (! is_string($line) || trim($line) === '') {
            return null;
        }

        return new ClientAddressAttributes(
            lineOne: $line,
            city: (string) $request->input('city'),
            countryCode: (string) $request->input('country_code'),
            lineTwo: $request->input('address_line_two'),
            region: $request->input('region'),
            postalCode: $request->input('postal_code'),
        );
    }

    /**
     * The currency the visitor has been browsing prices in.
     */
    private function defaultCurrency(): string
    {
        return $this->currency->current()
            ?? (string) config('platform.crm.default_currency', 'TRY');
    }

    /**
     * A 404 rather than a 403: an installation that does not offer
     * registration has no such page, and saying "forbidden" would advertise
     * one.
     */
    private function refuseIfClosed(): void
    {
        if (config('platform.crm.self_registration') !== true) {
            throw new NotFoundHttpException;
        }
    }
}
