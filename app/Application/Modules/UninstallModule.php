<?php

declare(strict_types=1);

namespace App\Application\Modules;

use App\Application\Access\SyncPermissions;
use App\Domain\Access\PermissionRegistry;
use App\Domain\Modules\Exceptions\InvalidModule;
use App\Domain\Modules\ExtensionPoint;
use App\Domain\Modules\Registration;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Support\Audit\Facades\Audit;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Remove a module, or say why that cannot be done.
 *
 * Three rules, each of which exists because the obvious alternative is
 * quietly destructive.
 *
 * **The guard reads the row, not the package.** What a module registered
 * was written down when it was enabled, so refusing here never means
 * loading the code an operator has decided to be rid of.
 *
 * **In use means refused, and the refusal names what is in the way.** A
 * provisioning module with live services, a gateway with payments or cards
 * against it, a registrar holding domains: removing it would leave rows
 * pointing at a key nothing answers to, and every screen that reads them
 * would start failing somewhere far from here.
 *
 * **The module's tables are left alone.** Uninstall removes the row, the
 * configuration and the permissions; it does not drop whatever the module
 * migrated. An operator who wants that data gone asks for it deliberately,
 * having read the sentence saying it cannot be undone — and a platform that
 * dropped tables on uninstall is a platform nobody dares uninstall anything
 * on.
 */
final readonly class UninstallModule
{
    public function __construct(
        private PermissionRegistry $permissions,
        private SyncPermissions $permissionSync,
        private OrganizationContext $organizations,
    ) {}

    public function handle(ModuleRecord $record, ?Model $actor = null): void
    {
        $registration = Registration::fromArray($record->capabilities ?? []);

        $blocker = $this->inUse($registration);

        if ($blocker !== null) {
            throw InvalidModule::inUse($record->slug, $blocker);
        }

        $slug = $record->slug;
        $label = $record->auditLabel();

        DB::transaction(function () use ($record, $slug): void {
            // Orphaned rather than deleted: a role that granted one of
            // these keeps the grant, and the grant is what explains a
            // historical decision.
            $this->permissions->forgetModule($slug);
            $this->permissionSync->handle($this->permissions);

            $record->delete();
        });

        Audit::action('modules.uninstalled')
            ->by($actor)
            ->withMetadata([
                'module' => $slug,
                'name' => $label,
                // Said out loud in the record, because the next question
                // anybody asks is "did that delete their data".
                'data' => 'tables left in place',
            ])
            ->write();
    }

    /**
     * What still points at this module, if anything.
     *
     * Asked past the organization boundary: a provider uninstalling a
     * module has to be stopped by a reseller's services too, and "no rows
     * in my subtree" is the wrong question entirely.
     */
    private function inUse(Registration $registration): ?string
    {
        return $this->organizations->withoutBoundary(function () use ($registration): ?string {
            foreach ($registration->keysFor(ExtensionPoint::ProvisioningModule) as $key) {
                $count = DB::table('services')->where('module', $key)->count();

                if ($count > 0) {
                    return "{$count} service(s) are provisioned by [{$key}]";
                }
            }

            foreach ($registration->keysFor(ExtensionPoint::Gateway) as $key) {
                $payments = DB::table('payments')->where('gateway', $key)->count();

                if ($payments > 0) {
                    return "{$payments} payment(s) were taken through [{$key}]";
                }

                $methods = DB::table('payment_methods')->where('gateway', $key)->count();

                if ($methods > 0) {
                    return "{$methods} stored card(s) belong to [{$key}]";
                }
            }

            foreach ($registration->keysFor(ExtensionPoint::Registrar) as $key) {
                $domains = DB::table('domains')->where('registrar', $key)->count();

                if ($domains > 0) {
                    return "{$domains} domain(s) are registered through [{$key}]";
                }
            }

            return null;
        });
    }
}
