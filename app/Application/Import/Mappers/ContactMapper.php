<?php

declare(strict_types=1);

namespace App\Application\Import\Mappers;

use App\Application\Import\ImportMapper;
use App\Application\Import\ImportResult;
use App\Application\Import\ImportWriter;
use App\Domain\Identity\AccountStatus;
use App\Domain\Import\ImportDomain;
use App\Domain\Import\ImportRecord;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A legacy contact becomes a contact of an imported customer.
 *
 * **No password comes across.** Not the hash either. A legacy system's hashes
 * are its own algorithm's, and importing them would either not work or work by
 * this platform accepting somebody else's crypto — so an imported contact has a
 * random password and reaches the portal through the reset flow, which is how
 * every account in this platform is reached.
 *
 * `portal_access` is imported as **false** unless the legacy row says the
 * contact could sign in. An import that handed portal access to every address
 * in a legacy contacts table would be an import that emailed a few thousand
 * people the moment somebody triggered a password reset campaign.
 */
final readonly class ContactMapper implements ImportMapper
{
    public function __construct(private OrganizationContext $organizations) {}

    public function domain(): ImportDomain
    {
        return ImportDomain::Contacts;
    }

    public function map(ImportRecord $record, ImportWriter $writer): ImportResult
    {
        $clientId = $record->text('userid');
        $customerId = $writer->mappedId(ImportDomain::Customers, $clientId);

        if ($customerId === null) {
            // An orphan with a null foreign key would be worse than a row an
            // operator can see and decide about.
            return ImportResult::failed("Its client [{$clientId}] was not imported.");
        }

        $email = strtolower($record->text('email'));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ImportResult::failed('It has no usable email address.');
        }

        return $writer->create($this->domain(), $record->externalId, function () use ($record, $customerId, $email): Model {
            $customer = Customer::query()->withoutGlobalScope('organization')->findOrFail($customerId);

            return $this->organizations->runAs(
                $customer->organization_id,
                fn (): Contact => Contact::query()->create([
                    'organization_id' => $customer->organization_id,
                    'customer_id' => $customer->id,
                    'first_name' => $record->text('firstname', 'Imported'),
                    'last_name' => $record->text('lastname', 'Contact'),
                    'email' => $email,
                    'phone' => $record->text('phonenumber') ?: null,
                    // Never the legacy hash, and never a password anybody
                    // chose: the reset flow is how an account is reached here.
                    'password' => Str::password(32),
                    'portal_access' => $this->hasPortalAccess($record),
                    'is_primary' => $record->text('is_primary') === '1',
                    'status' => AccountStatus::Active->value,
                    'locale' => config('app.locale'),
                    'timezone' => config('app.timezone'),
                ]),
            );
        });
    }

    /**
     * Whether this contact could sign in to the legacy portal.
     *
     * False unless the legacy row says otherwise. Handing portal access to
     * every address in a legacy contacts table is how an import ends up
     * emailing a few thousand people who never had an account.
     */
    private function hasPortalAccess(ImportRecord $record): bool
    {
        return in_array($record->text('subaccount'), ['1', 'on', 'true'], strict: true);
    }
}
