<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Infrastructure\Crm\Models\Address;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Crm\Models\Note;
use App\Infrastructure\Identity\Models\Contact;
use App\Support\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything the platform holds about one customer, as a structure.
 *
 * The subject-access half of the privacy obligation. It returns data rather
 * than a file on purpose: the caller decides whether that becomes JSON on a
 * screen, a download, or a mail attachment.
 *
 * Secrets are not included. A password hash and a two-factor secret are the
 * platform's authentication material, not the person's personal data, and
 * exporting them would turn a routine request into a credential disclosure.
 */
final readonly class ExportCustomerData
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Customer $customer, ?Model $actor = null): array
    {
        $contacts = $customer->contacts()->get()->map(
            fn (Contact $contact): array => [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'is_primary' => $contact->is_primary,
                'portal_access' => $contact->portal_access,
                'status' => $contact->status->value,
                'last_login_at' => $contact->last_login_at?->toIso8601String(),
                'communication_preferences' => [
                    'invoices' => $contact->notify_invoices,
                    'support' => $contact->notify_support,
                    'product' => $contact->notify_product,
                    'marketing' => $contact->notify_marketing,
                ],
                'addresses' => $this->addresses($contact),
            ],
        )->all();

        $export = [
            'exported_at' => CarbonImmutable::now()->toIso8601String(),
            'customer' => [
                'id' => $customer->id,
                'company_name' => $customer->company_name,
                'legal_name' => $customer->legal_name,
                'tax_id' => $customer->tax_id,
                'tax_id_type' => $customer->tax_id_type,
                'status' => $customer->status->value,
                'currency_code' => $customer->currency_code,
                'marketing_opt_in' => $customer->marketing_opt_in,
                'created_at' => $customer->created_at?->toIso8601String(),
            ],
            'contacts' => $contacts,
            'addresses' => $this->addresses($customer),
            'custom_fields' => $customer->customFieldSchema()
                ->map(fn (array $row): array => [
                    'key' => $row['definition']->key,
                    'label' => $row['definition']->label,
                    'value' => $row['value'],
                ])
                ->all(),
            // Only what the customer was allowed to see. An internal staff
            // note about a customer is not part of a subject access request.
            'notes' => $customer->notes()->where('is_customer_visible', true)->get()
                ->map(fn (Note $note): array => [
                    'body' => $note->body,
                    'created_at' => $note->created_at?->toIso8601String(),
                ])
                ->all(),
        ];

        Audit::action('crm.customer.exported')
            ->by($actor)
            ->on($customer)
            ->forOrganization($customer->organization_id)
            ->withMetadata(['contact_count' => count($contacts)])
            ->write();

        return $export;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function addresses(Model $owner): array
    {
        return Address::query()
            ->where('addressable_type', $owner->getMorphClass())
            ->where('addressable_id', (string) $owner->getKey())
            ->get()
            ->map(fn (Address $address): array => $address->toSnapshot() + ['type' => $address->type->value])
            ->values()
            ->all();
    }
}
