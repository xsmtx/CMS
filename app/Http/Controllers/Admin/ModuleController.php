<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Modules\DisableModule;
use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\SaveModuleConfig;
use App\Application\Modules\UninstallModule;
use App\Application\Modules\UpgradeModule;
use App\Domain\Modules\ConfigField;
use App\Domain\Modules\ExtensionPoint;
use App\Domain\Modules\ModuleManifest;
use App\Domain\Modules\Registration;
use App\Domain\Modules\Sdk;
use App\Http\Controllers\Controller;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Support\Errors\ForbiddenException;
use App\Support\Identity\CurrentActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Extensions: what is installed, what is on disk, and what each one does.
 *
 * The screen's job is to make the decision an operator is about to take
 * legible. Before they enable anything it shows the type, the version, the
 * author, and — once enabled — exactly which seams the package reached
 * into. "This module provides the Stripe gateway" is a sentence somebody
 * can check against what they expected when they downloaded it.
 *
 * Reading the list never loads a module's code
 * ([ADR 0038](../../../docs/adr/0038-a-module-may-execute.md)). Everything
 * on the page comes from the manifest and the row.
 */
final class ModuleController extends Controller
{
    public function __construct(
        private readonly CurrentActor $actor,
        private readonly ModuleCatalogue $catalogue,
        private readonly ActiveModules $modules,
    ) {}

    public function index(): Response
    {
        $this->authorizeFor('platform.modules.view');

        $records = ModuleRecord::query()->orderBy('name')->get();
        $installed = $records->keyBy('slug');

        return Inertia::render('Admin/Modules/Index', [
            'modules' => $records
                ->map(fn (ModuleRecord $record): array => $this->row($record))
                ->values()
                ->all(),
            // What an operator could install, which is the list they came
            // to this screen with a downloaded folder in mind to find.
            'available' => array_values(array_map(
                static fn (ModuleManifest $manifest): array => [
                    'slug' => $manifest->slug,
                    'name' => $manifest->name,
                    'type' => $manifest->type->value,
                    'typeLabel' => (string) __($manifest->type->labelKey()),
                    'version' => $manifest->version,
                    'provider' => $manifest->provider,
                    'description' => $manifest->description,
                    'sdk' => (string) $manifest->sdk,
                    'platform' => (string) $manifest->platform,
                ],
                array_filter(
                    $this->catalogue->all(),
                    static fn (ModuleManifest $manifest): bool => ! $installed->has($manifest->slug),
                ),
            )),
            'sdk' => Sdk::VERSION,
            'enabledForInstallation' => (bool) config('platform.modules.enabled', true),
            'can' => ['manage' => $this->actor->can('platform.modules.manage')],
        ]);
    }

    public function install(Request $request, InstallModule $installer): RedirectResponse
    {
        $this->authorizeFor('platform.modules.manage');

        $installer->handle($request->string('slug')->toString(), $this->actor->model());

        return back()->with('status', __('modules.installed'));
    }

    public function enable(string $module, EnableModule $enabler): RedirectResponse
    {
        $this->authorizeFor('platform.modules.manage');

        $enabler->handle($this->find($module), $this->actor->model());

        return back()->with('status', __('modules.enabled'));
    }

    public function disable(string $module, DisableModule $disabler): RedirectResponse
    {
        $this->authorizeFor('platform.modules.manage');

        $disabler->handle($this->find($module), $this->actor->model());

        return back()->with('status', __('modules.disabled'));
    }

    public function upgrade(string $module, UpgradeModule $upgrader): RedirectResponse
    {
        $this->authorizeFor('platform.modules.manage');

        $upgrader->handle($this->find($module), $this->actor->model());

        return back()->with('status', __('modules.upgraded'));
    }

    public function uninstall(string $module, UninstallModule $uninstaller): RedirectResponse
    {
        $this->authorizeFor('platform.modules.manage');

        $uninstaller->handle($this->find($module), $this->actor->model());

        return to_route('admin.modules.index')->with('status', __('modules.uninstalled'));
    }

    public function configure(Request $request, string $module, SaveModuleConfig $save): RedirectResponse
    {
        $this->authorizeFor('platform.modules.manage');

        $record = $this->find($module);

        /** @var array<string, mixed> $input */
        $input = $request->input('config', []);

        $save->handle($record, $this->schemaFor($record), $input, $this->actor->model());

        return back()->with('status', __('modules.configured'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ModuleRecord $record): array
    {
        $manifest = $this->catalogue->find($record->slug);
        $registration = Registration::fromArray($record->capabilities ?? []);

        return [
            'slug' => $record->slug,
            'name' => $record->name,
            'type' => $record->type->value,
            'typeLabel' => (string) __($record->type->labelKey()),
            'version' => $record->version,
            'provider' => $record->provider,
            'state' => $record->state->value,
            'stateLabel' => (string) __($record->state->labelKey()),
            'failureReason' => $record->failure_reason,
            'installedAt' => $record->installed_at?->toIso8601String(),
            'enabledAt' => $record->enabled_at?->toIso8601String(),

            // Null when the files have gone. An operator seeing a module
            // with no version on disk knows to put the folder back or
            // uninstall it, rather than wondering why nothing happens.
            'onDisk' => $manifest?->version,
            'upgradable' => $manifest !== null
                && version_compare($manifest->version, $record->version, '>'),

            // The sentence the whole screen exists for.
            'registers' => array_map(
                static fn (ExtensionPoint $point): array => [
                    'point' => $point->value,
                    'label' => (string) __($point->labelKey()),
                    'keys' => $registration->keysFor($point),
                ],
                $registration->points(),
            ),

            // The values of secrets never leave the server; the fact that
            // one is set does.
            'config' => $this->presentConfig($record),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presentConfig(ModuleRecord $record): array
    {
        $values = $record->config ?? [];

        return array_values(array_map(
            static function (ConfigField $field) use ($values): array {
                $value = $values[$field->key] ?? null;

                return [
                    'key' => $field->key,
                    'label' => $field->label,
                    'type' => $field->type->value,
                    'required' => $field->required,
                    'hint' => $field->hint,
                    'options' => $field->options,
                    'secret' => $field->isSecret(),
                    // A secret is never echoed. The screen shows whether
                    // one is set and an empty box that means "leave it".
                    'value' => $field->isSecret() ? null : $value,
                    'isSet' => $value !== null && $value !== '',
                ];
            },
            $this->schemaFor($record),
        ));
    }

    /**
     * The schema a module declared, read from the module.
     *
     * The one place this screen touches a package, and only for a module
     * the operator has already enabled: a form cannot be drawn for fields
     * nobody has declared, and the declaration lives in the code.
     *
     * @return list<ConfigField>
     */
    private function schemaFor(ModuleRecord $record): array
    {
        return $this->modules->find($record->slug)?->configSchema() ?? [];
    }

    private function find(string $slug): ModuleRecord
    {
        $record = ModuleRecord::query()->where('slug', $slug)->first();

        if (! $record instanceof ModuleRecord) {
            abort(404);
        }

        return $record;
    }

    /**
     * Apps and Integrations is shut to everybody but a super administrator,
     * and every controller behind that door repeats it. A gate stated in
     * three places is harder to remove by accident than one line in a route
     * file.
     */
    private function assertSuperAdmin(): void
    {
        AppsController::assertSuperAdminFor($this->actor);
    }

    private function authorizeFor(string $permission): void
    {
        $this->assertSuperAdmin();

        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('modules.errors.not_permitted'));
        }
    }
}
