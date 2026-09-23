<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Modules\ModuleState;
use App\Http\Controllers\Controller;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Provisioning\Models\Server;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Apps and Integrations: everything that connects this platform to
 * something else, behind one door.
 *
 * **Super administrators only**, and that is deliberately not a permission.
 * An Administrator holds every staff-scoped permission by design — the
 * seeder grants the lot — so a permission could never mean "only the
 * person who owns this installation". This area is about who somebody is.
 *
 * Why this area in particular: enabling a module runs code this repository
 * does not contain, and adding a server hands out credentials to somebody
 * else's machine. Those are the two things that can turn an administrator
 * account into control of the estate, and they belong together behind the
 * same door rather than scattered through Setup where a day-to-day
 * administrator meets them by accident.
 */
final class AppsController extends Controller
{
    public function __construct(private readonly CurrentActor $actor) {}

    public function index(): Response
    {
        $this->assertSuperAdmin();

        return Inertia::render('Admin/Apps/Index', [
            'areas' => [
                [
                    'key' => 'modules',
                    'href' => '/admin/apps/modules',
                    'count' => ModuleRecord::query()
                        ->where('state', ModuleState::Enabled->value)
                        ->count(),
                    'total' => ModuleRecord::query()->count(),
                ],
                [
                    'key' => 'servers',
                    'href' => '/admin/apps/infrastructure',
                    'count' => Server::query()->count(),
                    'total' => Server::query()->count(),
                ],
            ],
        ]);
    }

    /**
     * The gate the whole area shares.
     *
     * Duplicated as a method rather than a middleware on purpose: there are
     * three controllers behind this door and each one says out loud that it
     * is shut, which is harder to remove by accident than a line in a route
     * file.
     */
    public static function assertSuperAdminFor(CurrentActor $actor): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new ForbiddenException(__('apps.errors.super_admin_only'));
        }
    }

    private function assertSuperAdmin(): void
    {
        self::assertSuperAdminFor($this->actor);
    }
}
