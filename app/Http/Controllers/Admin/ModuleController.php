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
use App\Domain\Modules\Exceptions\InvalidModule;
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

        $slug = $request->string('slug')->toString();

        return $this->refusable(
            fn (): ModuleRecord => $installer->handle($slug, $this->actor->model()),
            __('modules.installed'),
        );
    }

    public function enable(string $module, EnableModule $enabler): RedirectResponse
    {
        $this->authorizeFor('platform.modules.manage');

        return $this->refusable(
            fn (): ModuleRecord => $enabler->handle($this->find($module), $this->actor->model()),
            __('modules.enabled'),
        );
    }

    public function disable(string $module, DisableModule $disabler): RedirectResponse
    {
        $this->authorizeFor('platform.modules.manage');

        return $this->refusable(
            fn (): ModuleRecord => $disabler->handle($this->find($module), $this->actor->model()),
            __('modules.disabled'),
        );
    }

    public function upgrade(string $module, UpgradeModule $upgrader): RedirectResponse
    {
        $this->authorizeFor('platform.modules.manage');

        return $this->refusable(
            fn (): ModuleRecord => $upgrader->handle($this->find($module), $this->actor->model()),
            __('modules.upgraded'),
        );
    }

    public function uninstall(string $module, UninstallModule $uninstaller): RedirectResponse
    {
        $this->authorizeFor('platform.modules.manage');

        try {
            $uninstaller->handle($this->find($module), $this->actor->model());
        } catch (InvalidModule $refusal) {
            // Uninstall is the one that redirects rather than going back, so it
            // cannot share the helper: a refusal has to stay on the screen that
            // can show it.
            return back()->withErrors(['module' => $refusal->getMessage()]);
        }

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
     * What this module needs to be told.
     *
     * A running module answers for itself — it may work options out at
     * runtime, and the live answer is the true one. A module that is not
     * running answers through its manifest, which is JSON and runs
     * nothing.
     *
     * Both, rather than one: without the manifest a module with a required
     * field could never be enabled, because it cannot be configured until
     * it runs and it cannot run until it is configured. Without the
     * interface a module could never compute anything.
     *
     * @return list<ConfigField>
     */
    private function schemaFor(ModuleRecord $record): array
    {
        $running = $this->modules->find($record->slug)?->configSchema();

        if ($running !== null && $running !== []) {
            return $running;
        }

        $manifest = $this->catalogue->find($record->slug);

        return $manifest instanceof ModuleManifest ? $manifest->config : [];
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

    /**
     * Run a lifecycle step, and turn a refusal into something an operator can
     * read.
     *
     * Every one of these can refuse for a reason that is not a bug: a module
     * built against another SDK, one that has not been configured yet, an
     * upgrade that would move backwards, an uninstall whose registrations are
     * still in use. `InvalidModule` already carries the sentence — it names the
     * module, the versions and the range — and until this existed the operator
     * got a 500 page instead of it.
     *
     * Only `InvalidModule` is caught. Anything else really is a bug and should
     * reach the handler, the log and the correlation id.
     *
     * @param  callable(): mixed  $step
     */
    private function refusable(callable $step, string $status): RedirectResponse
    {
        try {
            $step();
        } catch (InvalidModule $refusal) {
            return back()->withErrors(['module' => $refusal->getMessage()]);
        }

        return back()->with('status', $status);
    }

    private function authorizeFor(string $permission): void
    {
        $this->assertSuperAdmin();

        if (! $this->actor->can($permission)) {
            throw new ForbiddenException(__('modules.errors.not_permitted'));
        }
    }
}
