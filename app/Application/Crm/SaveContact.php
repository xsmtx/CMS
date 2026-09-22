<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Domain\Access\SystemRole;
use App\Domain\Crm\CustomFieldEntity;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Crm\Models\Customer;
use App\Infrastructure\Identity\Models\Contact;
use App\Support\Audit\Facades\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create or update a contact.
 *
 * One use case for both, because the invariants are the same: exactly one
 * primary contact per customer, and portal access that implies a usable
 * password.
 */
final readonly class SaveContact
{
    public function __construct(private SaveCustomFieldValues $customFields) {}

    public function handle(
        Customer $customer,
        ContactAttributes $attributes,
        ?Contact $contact = null,
        ?Model $actor = null,
    ): Contact {
        $isNew = $contact === null;
        $before = $contact?->only(['first_name', 'last_name', 'email', 'phone', 'portal_access', 'status']) ?? [];

        $saved = DB::transaction(function () use ($customer, $attributes, $contact): Contact {
            $payload = [
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                'first_name' => $attributes->firstName,
                'last_name' => $attributes->lastName,
                'email' => $attributes->email,
                'phone' => $attributes->phone,
                'portal_access' => $attributes->portalAccess,
                'is_primary' => $attributes->isPrimary,
                'status' => $attributes->status->value,
                'notify_invoices' => $attributes->notifyInvoices,
                'notify_support' => $attributes->notifySupport,
                'notify_product' => $attributes->notifyProduct,
                'notify_marketing' => $attributes->notifyMarketing,
            ];

            if ($contact === null) {
                // Granting portal access does not set a password. The person
                // reaches the account through the reset flow, so no secret is
                // ever chosen on their behalf.
                $payload['password'] = $attributes->portalAccess ? Str::password(32) : null;

                $contact = Contact::query()->create($payload);
            } else {
                $contact->update($payload);

                // Withdrawing access clears the password too, so the account
                // cannot be reached even if the flag is flipped back by a bug.
                if (! $attributes->portalAccess) {
                    $contact->forceFill(['password' => null])->save();
                } elseif ($contact->password === null) {
                    $contact->forceFill(['password' => Str::password(32)])->save();
                }
            }

            // Exactly one primary. Demoting the others here rather than in
            // the caller means no screen can create a customer with two.
            if ($attributes->isPrimary) {
                Contact::query()
                    ->where('customer_id', $customer->id)
                    ->whereKeyNot($contact->getKey())
                    ->update(['is_primary' => false]);
            }

            return $contact;
        });

        $this->syncPortalRole($saved);

        $this->customFields->handle($saved, CustomFieldEntity::Contact, $attributes->customFields);

        Audit::action($isNew ? 'crm.contact.created' : 'crm.contact.updated')
            ->by($actor)
            ->on($saved)
            ->forOrganization($saved->organization_id)
            ->changed($before, $saved->only(['first_name', 'last_name', 'email', 'phone', 'portal_access', 'status']))
            ->write();

        return $saved;
    }

    /**
     * Portal access implies exactly one role.
     *
     * The primary contact owns the account and can change who else reaches
     * it; everyone else can see the account and manage their own security,
     * and nothing more. Withdrawing access takes the role with it, so a
     * contact who cannot sign in also holds no permissions.
     */
    private function syncPortalRole(Contact $contact): void
    {
        if (! $contact->portal_access) {
            $contact->roles()->detach();
            $contact->flushPermissionCache();

            return;
        }

        $role = Role::query()
            ->where('slug', ($contact->is_primary ? SystemRole::AccountOwner : SystemRole::PortalMember)->value)
            ->first();

        if ($role === null) {
            return;
        }

        $contact->roles()->sync([$role->id]);
        $contact->flushPermissionCache();
    }
}
