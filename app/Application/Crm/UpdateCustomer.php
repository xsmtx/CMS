<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Application\Crm\Exceptions\InvalidCustomerTransition;
use App\Domain\Crm\CustomFieldEntity;
use App\Infrastructure\Crm\Models\Customer;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCustomer
{
    public function __construct(private SaveCustomFieldValues $customFields) {}

    public function handle(Customer $customer, CustomerAttributes $attributes, ?Model $actor = null): Customer
    {
        // The state machine is explicit rather than implied by the form: a
        // closed customer reopening is a decision, not a dropdown change.
        if ($customer->status !== $attributes->status
            && ! $customer->status->canTransitionTo($attributes->status)) {
            throw InvalidCustomerTransition::between($customer->status, $attributes->status);
        }

        $before = $customer->only(['company_name', 'legal_name', 'tax_id', 'status', 'currency_code']);

        DB::transaction(function () use ($customer, $attributes): void {
            $customer->update([
                'company_name' => $attributes->companyName,
                'legal_name' => $attributes->legalName,
                'tax_id' => $attributes->taxId,
                'tax_id_type' => $attributes->taxIdType,
                'status' => $attributes->status->value,
                'currency_code' => $attributes->currencyCode,
                'marketing_opt_in' => $attributes->marketingOptIn,
                'send_overdue_notices' => $attributes->sendOverdueNotices,
                'automatic_suspension' => $attributes->automaticSuspension,
                'separate_invoices' => $attributes->separateInvoices,
            ]);

            $customer->syncTags($attributes->tagIds);
        });

        $this->customFields->handle($customer, CustomFieldEntity::Customer, $attributes->customFields);

        Audit::action('crm.customer.updated')
            ->by($actor)
            ->on($customer)
            ->forOrganization($customer->organization_id)
            ->changed($before, $customer->only(['company_name', 'legal_name', 'tax_id', 'status', 'currency_code']))
            ->write();

        return $customer;
    }
}
