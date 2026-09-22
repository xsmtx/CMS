<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Crm\ContactAttributes;
use App\Application\Crm\DeleteContact;
use App\Application\Crm\SaveContact;
use App\Domain\Identity\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ContactRequest;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contacts, always in the context of the customer they belong to.
 */
final class ContactController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function create(Customer $customer): Response
    {
        $this->authorize('view', $customer);
        $this->authorize('create', Contact::class);

        return Inertia::render('Admin/Contacts/Form', [
            'customer' => ['id' => $customer->id, 'name' => $customer->displayName()],
            'contact' => null,
            'statuses' => $this->statuses(),
        ]);
    }

    public function store(ContactRequest $request, Customer $customer, SaveContact $saveContact): RedirectResponse
    {
        $this->authorize('view', $customer);
        $this->authorize('create', Contact::class);

        $saveContact->handle($customer, $this->attributes($request), null, $this->actor->model());

        return to_route('admin.customers.show', $customer)->with('status', __('crm.contact_saved'));
    }

    public function edit(Customer $customer, Contact $contact): Response
    {
        $this->authorize('update', $contact);
        $this->guardOwnership($customer, $contact);

        return Inertia::render('Admin/Contacts/Form', [
            'customer' => ['id' => $customer->id, 'name' => $customer->displayName()],
            'contact' => [
                'id' => $contact->id,
                'firstName' => $contact->first_name,
                'lastName' => $contact->last_name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'portalAccess' => $contact->portal_access,
                'isPrimary' => $contact->is_primary,
                'status' => $contact->status->value,
                'notifyInvoices' => $contact->notify_invoices,
                'notifySupport' => $contact->notify_support,
                'notifyProduct' => $contact->notify_product,
                'notifyMarketing' => $contact->notify_marketing,
            ],
            'statuses' => $this->statuses(),
        ]);
    }

    public function update(
        ContactRequest $request,
        Customer $customer,
        Contact $contact,
        SaveContact $saveContact,
    ): RedirectResponse {
        $this->authorize('update', $contact);
        $this->guardOwnership($customer, $contact);

        $saveContact->handle($customer, $this->attributes($request), $contact, $this->actor->model());

        return to_route('admin.customers.show', $customer)->with('status', __('crm.contact_saved'));
    }

    public function destroy(
        Customer $customer,
        Contact $contact,
        DeleteContact $deleteContact,
    ): RedirectResponse {
        $this->authorize('delete', $contact);
        $this->guardOwnership($customer, $contact);

        $deleteContact->handle($contact, $this->actor->model());

        return to_route('admin.customers.show', $customer)->with('status', __('crm.contact_deleted'));
    }

    /**
     * Both models are already inside the boundary, but a contact belonging to
     * a different customer must not be editable through this customer's URL.
     */
    private function guardOwnership(Customer $customer, Contact $contact): void
    {
        if ($contact->customer_id !== $customer->id) {
            throw new ForbiddenException(__('crm.contact_not_on_customer'));
        }
    }

    private function attributes(ContactRequest $request): ContactAttributes
    {
        /** @var array<string, mixed> $customFields */
        $customFields = $request->input('custom_fields', []);

        return new ContactAttributes(
            firstName: $request->string('first_name')->toString(),
            lastName: $request->string('last_name')->toString(),
            email: $request->string('email')->toString(),
            phone: $request->input('phone'),
            portalAccess: $request->boolean('portal_access'),
            isPrimary: $request->boolean('is_primary'),
            status: AccountStatus::from($request->string('status')->toString()),
            notifyInvoices: $request->boolean('notify_invoices'),
            notifySupport: $request->boolean('notify_support'),
            notifyProduct: $request->boolean('notify_product'),
            notifyMarketing: $request->boolean('notify_marketing'),
            customFields: $customFields,
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function statuses(): array
    {
        return array_map(
            fn (AccountStatus $status): array => [
                'value' => $status->value,
                'label' => (string) __($status->labelKey()),
            ],
            AccountStatus::cases(),
        );
    }
}
