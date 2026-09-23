<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

use App\Domain\Access\PermissionDefinition;
use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Domains\Contracts\DomainRegistrar;
use App\Domain\Health\Contracts\HealthCheck;
use App\Domain\Modules\Contracts\Module;
use App\Domain\Modules\ModuleContext;
use App\Domain\Modules\ModuleState;
use App\Domain\Modules\NavigationItem;
use App\Domain\Modules\Widget;
use App\Domain\Notifications\Contracts\DeliversNotifications;
use App\Domain\Provisioning\Contracts\ProvisioningModule;
use App\Domain\Risk\Contracts\RiskEvaluator;
use App\Domain\Tax\Contracts\TaxCalculator;
use App\Infrastructure\Modules\Models\ModuleRecord;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * The modules running in this process, resolved once and asked many times.
 *
 * Every registry in the platform asks this the same question — "and what do
 * the modules add" — so the loading, the failure handling and the ordering
 * live here rather than five times over.
 *
 * **Resolved lazily.** Nothing here touches the database until somebody
 * needs a registry, because service providers register during `artisan
 * migrate` on an empty database too, and a platform that could not install
 * itself without a `modules` table would be a platform nobody could
 * install.
 *
 * **A module that throws is dropped, logged and disabled.** Not rethrown:
 * by the time this runs, somebody is loading a page, and one bad package
 * must not be the end of the installation. The row carries the reason so
 * the modules screen can say what happened rather than showing a module
 * that claims to be enabled and does nothing.
 */
final class ActiveModules
{
    /** @var list<Module>|null */
    private ?array $modules = null;

    /** @var array<string, Module> */
    private array $bySlug = [];

    public function __construct(
        private readonly ModuleCatalogue $catalogue,
        private readonly ModuleLoader $loader,
    ) {}

    /**
     * @return list<Module>
     */
    public function all(): array
    {
        return $this->modules ??= $this->resolve();
    }

    /**
     * One running module, by slug.
     *
     * The screen needs this to draw a module's settings form: the schema
     * belongs to the package, and the only honest place to read it is the
     * package itself. A module that is not running has no form, which is
     * correct — there is nothing to configure that anything would read.
     */
    public function find(string $slug): ?Module
    {
        $this->all();

        return $this->bySlug[$slug] ?? null;
    }

    /**
     * @return list<PaymentGateway>
     */
    public function gateways(): array
    {
        return $this->collect(static fn (Module $module): array => $module->gateways());
    }

    /**
     * @return list<ProvisioningModule>
     */
    public function provisioningModules(): array
    {
        return $this->collect(static fn (Module $module): array => $module->provisioningModules());
    }

    /**
     * @return list<DomainRegistrar>
     */
    public function registrars(): array
    {
        return $this->collect(static fn (Module $module): array => $module->registrars());
    }

    /**
     * @return list<DeliversNotifications>
     */
    public function channels(): array
    {
        return $this->collect(static fn (Module $module): array => $module->channels());
    }

    /**
     * @return list<HealthCheck>
     */
    public function healthChecks(): array
    {
        return $this->collect(static fn (Module $module): array => $module->healthChecks());
    }

    /**
     * @return list<PermissionDefinition>
     */
    public function permissions(): array
    {
        return $this->collect(static fn (Module $module): array => $module->permissions());
    }

    /**
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        return $this->collect(static fn (Module $module): array => $module->navigation());
    }

    /**
     * @return list<Widget>
     */
    public function widgets(): array
    {
        return $this->collect(static fn (Module $module): array => $module->widgets());
    }

    /**
     * The last module to answer wins, and there is normally none.
     *
     * A single question with a single answer: two modules deciding whether
     * an order is risky would mean whichever was enabled last quietly
     * winning, so `InspectModule` records the claim and an operator can see
     * both on the screen.
     */
    public function riskEvaluator(): ?RiskEvaluator
    {
        foreach ($this->all() as $module) {
            $evaluator = $module->riskEvaluator();

            if ($evaluator !== null) {
                return $evaluator;
            }
        }

        return null;
    }

    public function taxCalculator(): ?TaxCalculator
    {
        foreach ($this->all() as $module) {
            $calculator = $module->taxCalculator();

            if ($calculator !== null) {
                return $calculator;
            }
        }

        return null;
    }

    public function forget(): void
    {
        $this->modules = null;
        $this->bySlug = [];
        $this->catalogue->forget();
    }

    /**
     * @template T
     *
     * @param  callable(Module): list<T>  $reader
     * @return list<T>
     */
    private function collect(callable $reader): array
    {
        $collected = [];

        foreach ($this->all() as $module) {
            foreach ($reader($module) as $item) {
                $collected[] = $item;
            }
        }

        return $collected;
    }

    /**
     * @return list<Module>
     */
    private function resolve(): array
    {
        if (! (bool) config('platform.modules.enabled', true)) {
            return [];
        }

        // Before the query, not after: an installation being migrated for
        // the first time has no such table, and asking would end the
        // request that was creating it.
        if (! $this->tableExists()) {
            return [];
        }

        $modules = [];

        $records = ModuleRecord::query()
            ->where('state', ModuleState::Enabled->value)
            ->orderBy('slug')
            ->get();

        foreach ($records as $record) {
            $module = $this->boot($record);

            if ($module instanceof Module) {
                $modules[] = $module;
                $this->bySlug[$record->slug] = $module;
            }
        }

        return $modules;
    }

    private function boot(ModuleRecord $record): ?Module
    {
        $manifest = $this->catalogue->find($record->slug);
        $directory = $this->catalogue->pathFor($record->slug);

        if ($manifest === null || $directory === null) {
            $this->stop($record, 'The module is no longer on disk.');

            return null;
        }

        try {
            $module = $this->loader->instantiate($manifest, $directory);

            $module->boot(new ModuleContext(
                $record->slug,
                new ChannelModuleLogger($record->slug),
                $record->config ?? [],
            ));

            return $module;
        } catch (Throwable $exception) {
            $this->stop($record, $exception->getMessage());

            return null;
        }
    }

    private function stop(ModuleRecord $record, string $reason): void
    {
        report(new RuntimeException("Module [{$record->slug}] was disabled: {$reason}"));

        $record->forceFill([
            'state' => ModuleState::Failed->value,
            'failure_reason' => $reason,
        ])->save();
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('modules');
        } catch (Throwable) {
            // No database yet, or one this process cannot reach. Either
            // way the answer is "no modules", not "stop".
            return false;
        }
    }
}
