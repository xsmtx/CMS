<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Modules\ModuleState;
use App\Http\Controllers\Controller;
use App\Infrastructure\Access\Models\Role;
use App\Infrastructure\Catalog\Models\Product;
use App\Infrastructure\Catalog\Models\ProductGroup;
use App\Infrastructure\Domains\Models\Tld;
use App\Infrastructure\Identity\Models\StaffUser;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Notifications\Models\NotificationTemplate;
use App\Infrastructure\Promotions\Models\Promotion;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup: everything an operator configures, and everything that connects this
 * platform to something else, on one page.
 *
 * It replaced the Setup dropdown. A menu of eight undifferentiated links is a
 * list nobody reads and, worse, a list that says nothing about what the eight
 * things *are* — a page can put a sentence under each one and a count beside it,
 * which is the difference between "Roles" and "Roles — 6 defined, who may do
 * what".
 *
 * **Two sections, because the second one is dangerous.** Enabling a module runs
 * code this repository does not contain, adding a server hands out credentials to
 * somebody else's machine, and Import writes straight past every use case. Those
 * are owner-only, they are the things that turn an administrator account into
 * control of the estate, and they belong together where an operator can see that
 * they are the same kind of thing.
 *
 * **The gate moved to the doors, and it was already there.** This page used to be
 * super-admin only as a whole; now an administrator reaches it for Products and
 * Roles, and the owner-only tiles are simply absent for them. Nothing is relaxed
 * by that: `ModuleController`, `InfrastructureController`, `ConnectController`,
 * `LicenceController` and `ImportController` each refuse on their own, which they
 * did before this page existed and which is why `assertSuperAdminFor` is a static
 * method they all call rather than a middleware somebody could forget to list.
 */
final class AppsController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(): Response
    {
        $sections = array_values(array_filter(
            [
                ['key' => 'setup', 'areas' => $this->setupAreas()],
                ['key' => 'integrations', 'areas' => $this->integrationAreas()],
            ],
            static fn (array $section): bool => $section['areas'] !== [],
        ));

        if ($sections === []) {
            // A staff member who may configure nothing at all. Refusing is
            // better than an empty page: an empty page reads as a bug, and this
            // is a decision somebody made about their role.
            throw new ForbiddenException(__('apps.errors.nothing_to_setup'));
        }

        return Inertia::render('Admin/Apps/Index', ['sections' => $sections]);
    }

    /**
     * The gate the owner-only area shares.
     *
     * Duplicated as a method rather than a middleware on purpose: there are five
     * controllers behind this door and each one says out loud that it is shut,
     * which is harder to remove by accident than a line in a route file. It stayed
     * when the hub page opened to administrators, and that is exactly why opening
     * the hub was safe.
     */
    public static function assertSuperAdminFor(CurrentActor $actor): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new ForbiddenException(__('apps.errors.super_admin_only'));
        }
    }

    /**
     * The Setup rows, each one permission-gated exactly as its screen is.
     *
     * A tile an operator cannot open is worse than no tile: it advertises a
     * screen and answers 403. So visibility here is the same question the screen
     * itself asks.
     *
     * @return list<array<string, mixed>>
     */
    private function setupAreas(): array
    {
        return $this->visible([
            $this->area('products', '/admin/catalog/products', 'catalog.products.view', Product::query()->count()),
            $this->area('product_groups', '/admin/catalog/groups', 'catalog.groups.view', ProductGroup::query()->count()),
            $this->area('promotions', '/admin/promotions', 'promotions.view', Promotion::query()->count()),
            $this->area('tlds', '/admin/catalog/tlds', 'domains.tlds.manage', Tld::query()->count()),
            $this->area('staff', '/admin/staff', 'identity.staff.view', StaffUser::query()->count()),
            $this->area('roles', '/admin/roles', 'access.roles.view', Role::query()->count()),
            $this->area('settings', '/admin/settings', 'settings.view'),
            $this->area(
                'notification_templates',
                '/admin/notifications/templates',
                'notifications.view',
                NotificationTemplate::query()->count(),
            ),
        ]);
    }

    /**
     * The owner-only rows.
     *
     * Five tiles that were spread across Setup and Utilities, where a day-to-day
     * administrator met them by accident. Every one of them is about who somebody
     * *is* rather than what they may do — an Administrator holds every staff
     * permission by design, so no permission could ever mean "the owner of this
     * installation".
     *
     * @return list<array<string, mixed>>
     */
    private function integrationAreas(): array
    {
        if (! $this->actor->isSuperAdmin()) {
            return [];
        }

        return [
            $this->area(
                'modules',
                '/admin/apps/modules',
                null,
                ModuleRecord::query()->where('state', ModuleState::Enabled->value)->count(),
            ),
            $this->area('servers', '/admin/apps/infrastructure', null, Server::query()->count()),
            $this->area('connect', '/admin/apps/connect'),
            $this->area('licence', '/admin/licence'),
            $this->area('import', '/admin/import'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return list<array<string, mixed>>
     */
    private function visible(array $areas): array
    {
        return array_values(array_filter(
            $areas,
            fn (array $area): bool => $area['permission'] === null
                || $this->actor->can((string) $area['permission']),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function area(string $key, string $href, ?string $permission = null, ?int $count = null): array
    {
        return [
            'key' => $key,
            'href' => $href,
            'permission' => $permission,
            'label' => (string) __('apps.areas.'.$key.'.label'),
            'description' => (string) __('apps.areas.'.$key.'.description'),
            'unit' => (string) __('apps.areas.'.$key.'.unit'),
            'count' => $count,
        ];
    }
}
