<?php

declare(strict_types=1);

namespace App\Application\Organizations;

use App\Application\Organizations\Exceptions\ResellerRefused;
use App\Domain\Access\SystemRole;
use App\Domain\Organizations\OrganizationType;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Organizations\Models\Organization;
use App\Support\Audit\Facades\Audit;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a reseller, and the person who will run it.
 *
 * The organization tree has had a `reseller` type since Phase 0 and nothing
 * could make one — deliberately, because "an organization is created by the
 * thing that needs one" and nothing needed a reseller until now.
 *
 * **The first staff account is part of this, not a second step.** A reseller
 * organization with nobody in it is a node an operator has to remember to
 * come back to, and the thing they came here to do was give somebody an
 * account. No password is chosen by the creator, here as everywhere: the
 * account is reached through the reset flow, so there is no secret to email,
 * read aloud or leave in a ticket.
 *
 * **A reseller is created under the provider and nowhere else.** One level
 * of resale: `OrganizationType::permittedChildTypes()` already says a
 * reseller owns customers and nothing else, and that stands. A margin on a
 * margin on a margin is a support ticket nobody can answer.
 *
 * A reseller starts able to sell **nothing**. Availability is rows
 * (`ResellerProduct`), and absence is a refusal like it is everywhere here —
 * a reseller created on Friday that could sell the whole catalogue is a
 * reseller exposing a product the provider had not meant to expose, with no
 * way to notice.
 */
final readonly class CreateReseller
{
    public function __construct(private OrganizationContext $organizations) {}

    /**
     * @return array{organization: Organization, owner: StaffUser}
     */
    public function handle(ResellerAttributes $attributes, ?Model $actor = null): array
    {
        $parent = $this->provider();

        if (StaffUser::query()->withoutGlobalScope('organization')
            ->where('email', $attributes->ownerEmail)->exists()) {
            // The address is unique across the installation, so a reseller
            // whose owner already works for somebody else is refused here
            // rather than by a constraint halfway through the transaction.
            throw ResellerRefused::emailTaken($attributes->ownerEmail);
        }

        $created = DB::transaction(function () use ($parent, $attributes): array {
            $organization = Organization::query()->create([
                'parent_id' => $parent->id,
                'type' => OrganizationType::Reseller->value,
                'name' => $attributes->name,
                'slug' => $this->slugFor($attributes),
                'is_active' => true,
            ]);

            // Created inside the new organization's own boundary, so the row
            // cannot land under the provider by inheriting the creator's
            // context.
            $owner = $this->organizations->runAs(
                $organization->id,
                fn (): StaffUser => StaffUser::query()->create([
                    'organization_id' => $organization->id,
                    'name' => $attributes->ownerName,
                    'email' => $attributes->ownerEmail,
                    'password' => Str::password(32),
                    'status' => 'active',
                    'locale' => $attributes->locale,
                    'timezone' => $attributes->timezone,
                ]),
            );

            // Administrator, not super-admin: super-admin bypasses every
            // permission check, and a reseller bypassing checks would be a
            // reseller outside the boundary that makes resale safe.
            if (! Role::query()
                ->withoutGlobalScope('organization')
                ->where('slug', SystemRole::Administrator->value)
                ->exists()) {
                throw ResellerRefused::noAdministratorRole();
            }

            $owner->assignRole(SystemRole::Administrator);

            return ['organization' => $organization, 'owner' => $owner];
        });

        Audit::action('organizations.reseller.created')
            ->by($actor)
            ->on($created['organization'])
            ->forOrganization($created['organization']->id)
            ->withMetadata([
                'name' => $attributes->name,
                'owner' => $attributes->ownerEmail,
            ])
            ->write();

        return $created;
    }

    /**
     * The installation owner, read outside the boundary.
     *
     * A reseller's parent is the provider, and the provider is never inside
     * a caller's subtree unless the caller *is* the provider — so the read
     * escapes the boundary and executes inside the callback (a builder
     * handed back out would be scoped again).
     */
    private function provider(): Organization
    {
        $provider = $this->organizations->withoutBoundary(
            static fn (): ?Organization => Organization::provider()
                ->withoutGlobalScope('organization')
                ->orderBy('path')
                ->first(),
        );

        if (! $provider instanceof Organization) {
            throw ResellerRefused::noProvider();
        }

        return $provider;
    }

    private function slugFor(ResellerAttributes $attributes): string
    {
        $slug = $attributes->slug === null || trim($attributes->slug) === ''
            ? Str::slug($attributes->name)
            : Str::slug($attributes->slug);

        if ($slug === '') {
            $slug = 'reseller';
        }

        $taken = Organization::query()
            ->withoutGlobalScope('organization')
            ->where('slug', $slug)
            ->exists();

        // Suffixed rather than refused: two resellers with the same trading
        // name is an ordinary thing, and the slug is ours rather than
        // theirs.
        return $taken ? $slug.'-'.Str::lower(Str::random(6)) : $slug;
    }
}
