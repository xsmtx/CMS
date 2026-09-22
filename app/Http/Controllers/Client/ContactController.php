<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Application\Crm\ContactAttributes;
use App\Application\Crm\DeleteContact;
use App\Application\Crm\SaveContact;
use App\Domain\Identity\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ClientContactRequest;
use App\Infrastructure\Identity\Models\Contact;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A customer managing their own contacts.
 *
 * Only the account owner reaches these routes. Letting any portal user add
 * another portal user would make the access list self-amending, which is the
 * kind of privilege escalation nobody notices until it matters.
 */
final class ContactController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(): Response
    {
        $me = $this->me();

        return Inertia::render('Client/Contacts', [
            'contacts' => $me->owningCustomer()->contacts()->get()->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'firstName' => $contact->first_name,
                'lastName' => $contact->last_name,
                'name' => $contact->displayName(),
                'email' => $contact->email,
                'phone' => $contact->phone,
                'isPrimary' => $contact->is_primary,
                'portalAccess' => $contact->portal_access,
                'isMe' => $contact->id === $me->id,
            ])->values()->all(),
            'can' => ['manage' => $this->actor->can('portal.contacts.manage')],
        ]);
    }

    public function store(ClientContactRequest $request, SaveContact $saveContact): RedirectResponse
    {
        $me = $this->guardOwner();

        $saveContact->handle($me->owningCustomer(), $this->attributes($request), null, $me);

        return back()->with('status', __('crm.contact_saved'));
    }

    public function update(
        ClientContactRequest $request,
        Contact $contact,
        SaveContact $saveContact,
    ): RedirectResponse {
        $me = $this->guardOwner();
        $this->guardSameCustomer($me, $contact);

        $saveContact->handle($me->owningCustomer(), $this->attributes($request, $contact), $contact, $me);

        return back()->with('status', __('crm.contact_saved'));
    }

    public function destroy(Contact $contact, DeleteContact $deleteContact): RedirectResponse
    {
        $me = $this->guardOwner();
        $this->guardSameCustomer($me, $contact);

        // Removing yourself would end your own access mid-request, and
        // removing the primary contact would leave the account with nobody
        // to bill.
        if ($contact->id === $me->id || $contact->is_primary) {
            throw new ForbiddenException(__('crm.contact_not_removable'));
        }

        $deleteContact->handle($contact, $me);

        return back()->with('status', __('crm.contact_deleted'));
    }

    private function attributes(ClientContactRequest $request, ?Contact $existing = null): ContactAttributes
    {
        return new ContactAttributes(
            firstName: $request->string('first_name')->toString(),
            lastName: $request->string('last_name')->toString(),
            email: $request->string('email')->toString(),
            phone: $request->input('phone'),
            portalAccess: $request->boolean('portal_access'),
            // A customer cannot hand primacy around from this screen: it
            // decides where invoices go, so it stays the provider's to set.
            isPrimary: $existing !== null && $existing->is_primary,
            status: $existing !== null ? $existing->status : AccountStatus::Active,
            notifyInvoices: $request->boolean('notify_invoices'),
            notifySupport: $request->boolean('notify_support'),
            notifyProduct: $request->boolean('notify_product'),
            notifyMarketing: $request->boolean('notify_marketing'),
        );
    }

    private function guardOwner(): Contact
    {
        if (! $this->actor->can('portal.contacts.manage')) {
            throw new ForbiddenException(__('crm.contacts_not_manageable'));
        }

        return $this->me();
    }

    private function guardSameCustomer(Contact $me, Contact $contact): void
    {
        if ($contact->customer_id !== $me->customer_id) {
            throw new ForbiddenException(__('crm.contact_not_on_customer'));
        }
    }

    private function me(): Contact
    {
        $contact = $this->actor->model();

        abort_if(! $contact instanceof Contact, 401);

        return $contact;
    }
}
