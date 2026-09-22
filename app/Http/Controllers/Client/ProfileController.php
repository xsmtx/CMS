<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ProfileRequest;
use App\Infrastructure\Identity\Models\Contact;
use App\Support\Audit\Facades\Audit;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's own profile, as they see it.
 *
 * The boundary already limits what this contact can reach, so the controller
 * reads the customer through the relation rather than from a URL parameter:
 * there is exactly one customer a contact can be looking at.
 */
final class ProfileController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function show(): Response
    {
        $contact = $this->contact();
        $customer = $contact->owningCustomer();

        return Inertia::render('Client/Profile', [
            'customer' => [
                'companyName' => $customer->company_name,
                'legalName' => $customer->legal_name,
                'taxId' => $customer->tax_id,
                'status' => $customer->status->value,
                'currencyCode' => $customer->currency_code,
                'marketingOptIn' => $customer->marketing_opt_in,
            ],
            'me' => [
                'firstName' => $contact->first_name,
                'lastName' => $contact->last_name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'isPrimary' => $contact->is_primary,
                'notifyInvoices' => $contact->notify_invoices,
                'notifySupport' => $contact->notify_support,
                'notifyProduct' => $contact->notify_product,
                'notifyMarketing' => $contact->notify_marketing,
            ],
            'can' => ['manage' => $this->actor->can('portal.profile.manage')],
        ]);
    }

    public function update(ProfileRequest $request): RedirectResponse
    {
        $contact = $this->contact();

        // Only ever their own row. A contact editing a colleague goes
        // through the contacts screen, which has its own permission.
        $before = $contact->only(['first_name', 'last_name', 'phone']);

        $contact->update([
            'first_name' => $request->string('first_name')->toString(),
            'last_name' => $request->string('last_name')->toString(),
            'phone' => $request->input('phone'),
            'notify_invoices' => $request->boolean('notify_invoices'),
            'notify_support' => $request->boolean('notify_support'),
            'notify_product' => $request->boolean('notify_product'),
            'notify_marketing' => $request->boolean('notify_marketing'),
        ]);

        Audit::action('portal.profile.updated')
            ->by($contact)
            ->on($contact)
            ->changed($before, $contact->only(['first_name', 'last_name', 'phone']))
            ->write();

        return back()->with('status', __('crm.profile_updated'));
    }

    /**
     * The customer record itself, editable only by the account owner.
     */
    public function updateCustomer(ProfileRequest $request): RedirectResponse
    {
        $contact = $this->contact();

        if (! $this->actor->can('portal.profile.manage')) {
            throw new ForbiddenException(__('crm.profile_not_editable'));
        }

        $customer = $contact->owningCustomer();
        $before = $customer->only(['company_name', 'legal_name', 'tax_id']);

        $customer->update([
            'company_name' => $request->input('company_name'),
            'legal_name' => $request->input('legal_name'),
            'tax_id' => $request->input('tax_id'),
            'marketing_opt_in' => $request->boolean('marketing_opt_in'),
        ]);

        Audit::action('portal.customer.updated')
            ->by($contact)
            ->on($customer)
            ->changed($before, $customer->only(['company_name', 'legal_name', 'tax_id']))
            ->write();

        return back()->with('status', __('crm.customer_updated'));
    }

    private function contact(): Contact
    {
        $contact = $this->actor->model();

        abort_if(! $contact instanceof Contact, 401);

        return $contact;
    }
}
