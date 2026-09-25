<?php

declare(strict_types=1);

use App\Application\Modules\EnableModule;
use App\Application\Modules\InstallModule;
use App\Application\Modules\SaveModuleConfig;
use App\Infrastructure\Modules\ActiveModules;
use App\Infrastructure\Modules\Models\ModuleRecord;
use App\Infrastructure\Modules\ModuleCatalogue;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stripe, cPanel and Namecheap leave core, and keep working.
 *
 * `modules-and-marketplace-plan.md` §2: all three are adapters this
 * repository has never proven against the thing they adapt, so a core
 * release is the wrong unit of shipping for them. **This is a migration, not
 * a deletion.** An installation crossing this release must find its gateway
 * still taking payments — a release that silently stopped taking money would
 * be the worst upgrade this product could ship.
 *
 * So an installation whose environment already configured one of them gets
 * the package installed, configured from those same values, and enabled.
 * An installation that never configured them gets nothing, which is what it
 * had before.
 *
 * The values are read through `config()`, and the three blocks they live in
 * stay in `config/platform.php` for that reason — marked as the upgrade
 * source rather than as settings anything still reads. `env()` would answer
 * null on any deployment that has cached its config, which is every
 * production one.
 *
 * Nothing here throws. A module that cannot be enabled leaves a log line and
 * the migration continues: a failed upgrade of an adapter is a support
 * ticket, and a failed migration is an installation that will not boot.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Modules belong to the provider organization, and a migration has
        // no actor to take a boundary from.
        $providerId = DB::table('organizations')->whereNull('parent_id')->value('id');

        if (! is_string($providerId)) {
            return;
        }

        app(OrganizationContext::class)->set($providerId);
        app(ModuleCatalogue::class)->forget();

        foreach ($this->carried() as $slug => $config) {
            $this->adopt($slug, $config);
        }

        app(ActiveModules::class)->forget();
    }

    public function down(): void
    {
        // Deliberately irreversible. Rolling back would disable a gateway
        // that is taking payments, and the packages are on disk either way.
    }

    /**
     * What the old environment said, for the adapters that were configured.
     *
     * @return array<string, array<string, string|bool>>
     */
    private function carried(): array
    {
        $carried = [];

        $stripe = (array) config('platform.billing.gateways.stripe', []);
        $secret = is_string($stripe['secret'] ?? null) ? $stripe['secret'] : '';
        $webhookSecret = is_string($stripe['webhook_secret'] ?? null) ? $stripe['webhook_secret'] : '';

        // Both keys or neither, exactly as the service provider decided.
        if ($secret !== '' && $webhookSecret !== '') {
            $carried['gateway-stripe'] = [
                'secret' => $secret,
                'webhook_secret' => $webhookSecret,
                'api_base' => is_string($stripe['api_base'] ?? null) && $stripe['api_base'] !== ''
                    ? $stripe['api_base']
                    : 'https://api.stripe.com',
            ];
        }

        $namecheap = (array) config('platform.domains.registrars.namecheap', []);
        $username = is_string($namecheap['username'] ?? null) ? $namecheap['username'] : '';
        $apiKey = is_string($namecheap['api_key'] ?? null) ? $namecheap['api_key'] : '';

        if ($username !== '' && $apiKey !== '') {
            $carried['registrar-namecheap'] = [
                'username' => $username,
                'api_key' => $apiKey,
                'client_ip' => is_string($namecheap['client_ip'] ?? null) ? $namecheap['client_ip'] : '',
                'sandbox' => (bool) ($namecheap['sandbox'] ?? false),
            ];
        }

        /*
         * cPanel was registered unless an installation turned it off, so the
         * default carries the other way: it is adopted unless the setting
         * said no. Its credentials were never here — a WHM token belongs to
         * a server in the fleet.
         */
        if ((bool) config('platform.provisioning.modules.cpanel.enabled', true)) {
            $carried['provisioning-cpanel'] = [
                'timeout' => (string) (int) config('platform.provisioning.timeout', 30),
                'retries' => (string) (int) config('platform.provisioning.retries', 2),
            ];
        }

        return $carried;
    }

    /**
     * @param  array<string, string|bool>  $config
     */
    private function adopt(string $slug, array $config): void
    {
        try {
            $record = ModuleRecord::query()->where('slug', $slug)->first()
                ?? app(InstallModule::class)->handle($slug);

            $manifest = app(ModuleCatalogue::class)->find($slug);

            if ($manifest === null) {
                return;
            }

            app(SaveModuleConfig::class)->handle($record, $manifest->config, $config);

            app(EnableModule::class)->handle($record->fresh() ?? $record);
        } catch (Throwable $failure) {
            // Named, so an operator who finds their gateway missing has the
            // sentence that explains it rather than a silence.
            Log::warning('Could not adopt '.$slug.' during the adapter move.', [
                'slug' => $slug,
                'reason' => $failure->getMessage(),
            ]);
        }
    }
};
