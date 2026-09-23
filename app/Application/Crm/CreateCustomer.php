<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Domain\Crm\CustomFieldEntity;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a customer.
 *
 * A customer is an organization plus a commercial profile, so both are
 * created together. Doing it in one transaction is what stops a failure
 * halfway leaving an organization with nothing hanging off it.
 */
final readonly class CreateCustomer
{
    public function __construct(private SaveCustomFieldValues $customFields) {}

    public function handle(string $parentOrganizationId, CustomerAttributes $attributes, ?Model $actor = null): Customer
    {
        $customer = DB::transaction(function () use ($parentOrganizationId, $attributes): Customer {
            $name = $attributes->companyName ?? $attributes->legalName ?? 'Customer';

            $organization = Organization::query()->create([
                'parent_id' => $parentOrganizationId,
                'type' => OrganizationType::Customer->value,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                'is_active' => true,
            ]);

            $customer = Customer::query()->create([
                'organization_id' => $organization->id,
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

            return $customer;
        });

        $this->customFields->handle($customer, CustomFieldEntity::Customer, $attributes->customFields);

        Audit::action('crm.customer.created')
            ->by($actor)
            ->on($customer)
            ->forOrganization($customer->organization_id)
            ->write();

        return $customer;
    }
}
