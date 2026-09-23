<?php

declare(strict_types=1);

namespace App\Application\Import\Mappers;

use App\Application\Import\ImportMapper;
use App\Application\Import\ImportResult;
use App\Application\Import\ImportWriter;
use App\Domain\Crm\CustomerStatus;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportRecord;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Exceptions\UnknownCurrency;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A legacy client becomes a customer — **and an organization**.
 *
 * The second half is the part that surprises people. A customer is an
 * organization of its own in this platform (ADR 0025 and the whole ownership
 * model), so importing a client means creating the node it hangs off. An
 * importer that only wrote the `customers` row would produce a customer whose
 * every subsequent record lands in the provider's own subtree, and the boundary
 * would be quietly wrong for the imported half of the installation.
 *
 * The status is narrowed on purpose. A legacy system has statuses this one does
 * not — WHMCS's `Inactive` is not a state here — so an unrecognised status
 * becomes `Active` and the row is still imported. The alternative, failing the
 * row, loses a real customer over a word.
 */
final readonly class CustomerMapper implements ImportMapper
{
    public function __construct(private OrganizationContext $organizations) {}

    public function domain(): ImportDomain
    {
        return ImportDomain::Customers;
    }

    public function map(ImportRecord $record, ImportWriter $writer): ImportResult
    {
        $currency = $record->text('currency', 'EUR');

        try {
            $resolved = Currency::of($currency)->code;
        } catch (UnknownCurrency) {
            // Something an operator can act on, rather than the exception name.
            return ImportResult::failed("The currency [{$currency}] is not one this platform knows.");
        }

        $name = $this->name($record);

        return $writer->create($this->domain(), $record->externalId, function () use ($record, $resolved, $name): Model {
            $organization = Organization::query()->create([
                'parent_id' => $this->organizations->id(),
                'type' => 'customer',
                'name' => $name,
                // Suffixed unconditionally: two legacy clients called "Acme"
                // is an ordinary thing and the slug is ours rather than theirs.
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                'is_active' => true,
            ]);

            // Inside the new organization's own boundary, so the customer row
            // cannot land under the importer's context by inheriting it.
            return $this->organizations->runAs(
                $organization->id,
                fn (): Customer => Customer::query()->create([
                    'organization_id' => $organization->id,
                    'company_name' => $record->text('companyname') ?: null,
                    'legal_name' => null,
                    'tax_id' => $record->text('tax_id') ?: null,
                    'status' => $this->status($record->text('status')),
                    'currency_code' => $resolved,
                    'locale' => config('app.locale'),
                    'timezone' => config('app.timezone'),
                ]),
            );
        });
    }

    /**
     * What to call the organization.
     *
     * A company name if there is one, the person's name otherwise. An
     * individual customer has no company name, which is the case
     * `Customer::displayName()` exists for — and the organization needs
     * *something* legible, because it is what an operator sees in the tree.
     */
    private function name(ImportRecord $record): string
    {
        $company = $record->text('companyname');

        if ($company !== '') {
            return $company;
        }

        $person = trim($record->text('firstname').' '.$record->text('lastname'));

        return $person !== '' ? $person : 'Imported client '.$record->externalId;
    }

    private function status(string $legacy): string
    {
        return match (strtolower($legacy)) {
            'closed' => CustomerStatus::Closed->value,
            'suspended' => CustomerStatus::Suspended->value,
            // Everything else, including a legacy status this platform has no
            // equivalent for. Losing a real customer over a word would be
            // worse than importing them active.
            default => CustomerStatus::Active->value,
        };
    }
}
