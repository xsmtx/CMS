<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Domain\Crm\CustomerStatus;
use App\Domain\Identity\AccountStatus;
use App\Infrastructure\Crm\Models\Address;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Erase a customer's personal data while keeping the commercial record.
 *
 * The erasure half of the privacy obligation. Rows are overwritten rather
 * than deleted, because the financial history that references them has to
 * stay intact: an invoice cannot lose the fact that it was issued.
 *
 * What survives is deliberate. The audit trail is untouched, because a record
 * of who suspended a service is the platform's own account of its actions
 * rather than the customer's personal data, and destroying it would remove
 * the evidence that the erasure itself was authorised.
 */
final readonly class AnonymizeCustomer
{
    public function handle(Customer $customer, string $reason, ?Model $actor = null): Customer
    {
        $contactCount = $customer->contacts()->count();

        DB::transaction(function () use ($customer): void {
            $now = CarbonImmutable::now();

            foreach ($customer->contacts()->get() as $contact) {
                $this->anonymizeContact($contact, $now);
            }

            // Addresses are personal data with no commercial value once the
            // relationship ends; issued invoices already carry their own
            // snapshot of the address they were sent to.
            $this->deleteAddresses($customer);

            $customer->customFieldValues()->delete();
            $customer->notes()->delete();
            $customer->tags()->detach();

            $customer->forceFill([
                'company_name' => 'Anonymised customer',
                'legal_name' => null,
                'tax_id' => null,
                'tax_id_type' => null,
                'marketing_opt_in' => false,
                'status' => CustomerStatus::Closed->value,
                'anonymized_at' => $now,
            ])->save();
        });

        Audit::action('crm.customer.anonymized')
            ->by($actor)
            ->on($customer)
            ->forOrganization($customer->organization_id)
            ->because($reason)
            ->withMetadata(['contacts_anonymized' => $contactCount])
            ->write();

        return $customer;
    }

    private function anonymizeContact(Contact $contact, CarbonImmutable $now): void
    {
        $this->deleteAddresses($contact);

        $contact->customFieldValues()->delete();
        $contact->tokens()->delete();

        $contact->forceFill([
            'first_name' => 'Anonymised',
            'last_name' => 'contact',
            // Unique and unusable. The column is unique, so a placeholder
            // shared between contacts would fail on the second one.
            'email' => 'anonymised+'.Str::lower((string) Str::ulid()).'@invalid',
            'phone' => null,
            'password' => null,
            'remember_token' => null,
            'portal_access' => false,
            'status' => AccountStatus::Closed->value,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'anonymized_at' => $now,
        ])->save();
    }

    private function deleteAddresses(Model $owner): void
    {
        Address::query()
            ->where('addressable_type', $owner->getMorphClass())
            ->where('addressable_id', (string) $owner->getKey())
            ->delete();
    }
}
