<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Crm\ContactAttributes;
use App\Application\Crm\CreateCustomer;
use App\Application\Crm\CustomerAttributes;
use App\Application\Crm\SaveContact;
use App\Domain\Crm\AddressType;
use App\Domain\Crm\CustomerStatus;
use App\Domain\Identity\AccountStatus;
use App\Infrastructure\Identity\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Creates the account a visitor needs in order to have ordered something.
 *
 * The contact gets portal access but no password: they set one through the
 * reset flow, which means no password ever travels through a checkout form
 * and no temporary password is ever emailed. It also means the email
 * address is proved before the account can be used.
 *
 * The order proceeds regardless of whether that has happened yet. Refusing
 * an order because an inbox is slow would be absurd.
 */
final readonly class RegisterCheckoutAccount
{
    public function __construct(
        private CreateCustomer $createCustomer,
        private SaveContact $saveContact,
    ) {}

    public function handle(string $parentOrganizationId, CheckoutAccount $account): Contact
    {
        return DB::transaction(function () use ($parentOrganizationId, $account): Contact {
            $customer = $this->createCustomer->handle($parentOrganizationId, new CustomerAttributes(
                companyName: $account->company,
                legalName: null,
                taxId: $account->taxId,
                taxIdType: $account->taxId === null ? null : 'vat',
                // Active rather than pending: they have just agreed to buy
                // something, which is the signal a pending status waits for.
                status: CustomerStatus::Active,
                currencyCode: $account->currencyCode,
                marketingOptIn: $account->marketingOptIn,
            ));

            $contact = $this->saveContact->handle($customer, new ContactAttributes(
                firstName: $account->firstName,
                lastName: $account->lastName,
                email: $account->email,
                phone: $account->phone,
                portalAccess: true,
                isPrimary: true,
                status: AccountStatus::Active,
                notifyMarketing: $account->marketingOptIn,
            ));

            if ($account->countryCode !== null) {
                $customer->addresses()->create([
                    'organization_id' => $customer->organization_id,
                    'type' => AddressType::Billing->value,
                    'line_one' => $account->addressLine ?? '',
                    'city' => $account->city ?? '',
                    'region' => $account->region,
                    'postal_code' => $account->postalCode,
                    'country_code' => strtoupper($account->countryCode),
                    'is_default' => true,
                ]);
            }

            return $contact;
        });
    }
}
