<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Domain\Crm\AddressType;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A whole client, in one go: the company, the person and the address.
 *
 * `CreateCustomer` makes the commercial record and nothing else, which is
 * right for the API and for an import. An operator adding a client on the
 * telephone needs all three at once — a customer with no contact cannot
 * sign in, and one with no address cannot be invoiced in most of Europe.
 *
 * **One transaction.** A half-created client is worse than none: the
 * operator retypes everything, and now there are two companies with one
 * contact between them.
 *
 * The password is optional. An operator taking details over the telephone
 * should be able to leave it blank and let the customer set their own
 * through the reset link, which is both safer and one fewer thing to read
 * out loud.
 */
final readonly class CreateClient
{
    public function __construct(private CreateCustomer $customers) {}

    public function handle(
        string $parentOrganizationId,
        CustomerAttributes $customer,
        ClientContactAttributes $contact,
        ?ClientAddressAttributes $address = null,
        ?Model $actor = null,
    ): Customer {
        $record = $this->customers->handle($parentOrganizationId, $customer, $actor);

        DB::transaction(function () use ($record, $contact, $address): void {
            $person = Contact::query()->create([
                'organization_id' => $record->organization_id,
                'customer_id' => $record->id,
                'first_name' => $contact->firstName,
                'last_name' => $contact->lastName,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'locale' => $contact->locale,
                'is_primary' => true,
                'portal_access' => true,
                // Null is a real state: the account exists and cannot be
                // signed into until somebody sets a password through the
                // reset link.
                'password' => $contact->password === null ? null : Hash::make($contact->password),
                'notify_invoices' => $contact->notifyInvoices,
                'notify_support' => $contact->notifySupport,
                'notify_product' => $contact->notifyProduct,
                'notify_marketing' => $contact->notifyMarketing,
            ]);

            // The first person on an account owns it. Anything narrower
            // would leave a new client unable to do the thing they were
            // created to do.
            $person->assignRole($contact->role);

            if ($address instanceof ClientAddressAttributes) {
                // Through the relation, not `Address::create`: the morph
                // columns are not fillable, and writing them by hand
                // fails under strict mass assignment.
                $record->addresses()->create([
                    'organization_id' => $record->organization_id,
                    'type' => AddressType::Billing->value,
                    'line_one' => $address->lineOne,
                    'line_two' => $address->lineTwo,
                    'city' => $address->city,
                    'region' => $address->region,
                    'postal_code' => $address->postalCode,
                    'country_code' => $address->countryCode,
                    'is_default' => true,
                ]);
            }
        });

        Audit::action('crm.client.created')
            ->by($actor)
            ->on($record)
            ->forOrganization($record->organization_id)
            ->withMetadata([
                'contact' => $contact->email,
                'with_password' => $contact->password !== null ? 'yes' : 'no',
            ])
            ->write();

        return $record->refresh();
    }
}
