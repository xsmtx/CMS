<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Modules\ModuleType;
use App\Domain\Modules\Sdk;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * A module that compiles, from nothing, in one command.
 *
 * The scaffold exists because the first ten minutes of writing a module are
 * the ones where an author decides whether the SDK is worth learning. A
 * manifest with a typo in its namespace, or an entrypoint that does not
 * match, fails at enable time with a message about class loading — which is
 * a true message and a useless first impression.
 *
 * What it writes is deliberately the smallest honest thing: a manifest, an
 * entrypoint extending `BaseModule`, and a README saying what to do next.
 * It does **not** scaffold a gateway or a registrar. A stub implementing a
 * contract it does not mean is a stub somebody ships.
 */
final class MakeModuleCommand extends Command
{
    protected $signature = 'module:make
        {slug : The module slug, lowercase and hyphenated}
        {--vendor= : The directory the module lives under (default: local)}
        {--type=addon : One of the module types}
        {--name= : Human name (default: the slug, title-cased)}';

    protected $description = 'Scaffold a module package that compiles and can be installed';

    public function handle(): int
    {
        $slug = Str::lower(trim((string) $this->argument('slug')));

        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            // The same rule `ModuleManifest` enforces, checked here so the
            // author is told now rather than at install. A slug reaches a
            // directory name, a route segment and a log channel.
            $this->error('A slug is lowercase words joined by hyphens: "acme-gateway".');

            return self::FAILURE;
        }

        $type = ModuleType::tryFrom((string) $this->option('type'));

        if (! $type instanceof ModuleType) {
            $this->error(sprintf(
                'Unknown type. One of: %s',
                implode(', ', array_column(ModuleType::cases(), 'value')),
            ));

            return self::FAILURE;
        }

        $vendor = Str::lower(trim((string) ($this->option('vendor') ?: 'local')));
        $name = (string) ($this->option('name') ?: Str::headline($slug));
        $root = (string) config('platform.modules.path', base_path('modules'));
        $directory = $root.'/'.$vendor.'/'.$slug;

        if (is_dir($directory)) {
            $this->error('That directory already exists: '.$directory);

            return self::FAILURE;
        }

        $namespace = Str::studly($vendor).'\\'.Str::studly($slug);
        $entrypoint = Str::studly($slug).'Module';

        if (! mkdir($directory.'/src', 0o755, true) && ! is_dir($directory.'/src')) {
            $this->error('Could not create '.$directory);

            return self::FAILURE;
        }

        file_put_contents($directory.'/module.json', $this->manifest($slug, $name, $type, $namespace, $entrypoint));
        file_put_contents($directory.'/src/'.$entrypoint.'.php', $this->entrypoint($namespace, $entrypoint, $name));
        file_put_contents($directory.'/README.md', $this->readme($name, $type));

        $this->info('Created '.$directory);
        $this->line('');
        $this->line('  module.json          what it claims to be, read before anything of it runs');
        $this->line('  src/'.$entrypoint.'.php'.str_repeat(' ', max(1, 17 - strlen($entrypoint))).'the entrypoint, which runs only once somebody enables it');
        $this->line('  README.md            what to do next');
        $this->line('');
        $this->line('  php artisan module:list    to see it on disk');
        $this->line('  /admin/apps/modules        to install and enable it');

        return self::SUCCESS;
    }

    private function manifest(
        string $slug,
        string $name,
        ModuleType $type,
        string $namespace,
        string $entrypoint,
    ): string {
        // JSON, not PHP: this file is read before anybody has decided
        // whether to trust the package (ADR 0038).
        return json_encode([
            'slug' => $slug,
            'name' => $name,
            'type' => $type->value,
            'version' => '0.1.0',
            'description' => 'Describe what this module does, in one sentence an operator can read.',
            'provider' => null,
            // A range rather than `*`: a module claiming to work with
            // every SDK forever is claiming something nobody can check,
            // and the point of the range is that core can change a
            // contract and have this refuse at install time.
            'sdk' => '>='.Sdk::VERSION,
            'platform' => '*',
            'namespace' => $namespace,
            'entrypoint' => $entrypoint,
            'dependencies' => [],
            'migrations' => false,
            'translations' => false,
            // Declared here as well as in `configSchema()`, and this is the
            // copy that matters before the module runs: a module with a
            // required field that only declared it in code could never be
            // enabled, because it cannot be configured until it runs and
            // cannot run until it is configured.
            'config' => [
                [
                    'key' => 'example',
                    'label' => 'Something this module needs to be told',
                    'type' => 'text',
                    'required' => false,
                    'hint' => 'Delete this, or make it real.',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private function entrypoint(string $namespace, string $class, string $name): string
    {
        $parts = explode('\\', $class);
        $short = end($parts);

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use App\\Domain\\Modules\\BaseModule;
        use App\\Domain\\Modules\\ModuleContext;

        /**
         * {$name}.
         *
         * `BaseModule` answers every question with an empty list, so this
         * class implements only what it actually provides. Add a method
         * from `App\\Domain\\Modules\\Contracts\\Module` when you have
         * something to return from it.
         *
         * Nothing here runs until an operator enables the module. Anything
         * thrown from `boot()` leaves it disabled with the reason recorded,
         * rather than taking the installation down.
         */
        final class {$short} extends BaseModule
        {
            public function boot(ModuleContext \$context): void
            {
                // \$context->config is what the operator filled in on the
                // settings screen, from `configSchema()`. \$context->log
                // writes to this module's own channel.
                \$context->log->info('booted');
            }
        }

        PHP;
    }

    private function readme(string $name, ModuleType $type): string
    {
        return <<<MD
        # {$name}

        A `{$type->value}` module for InfraCMS.

        ## What to do next

        1. `php artisan module:list` — it should appear as `on-disk`.
        2. Install it from **Apps & Integrations → Modules**. Installing
           writes a row and runs nothing.
        3. Declare what it needs: add `configSchema()` returning
           `ConfigField` objects. Core draws the form, validates it and
           encrypts the secrets — this module never renders anything.
        4. Provide something: implement the method on
           `App\\Domain\\Modules\\Contracts\\Module` that matches your type.
           A `{$type->value}` may register the points its type allows, and
           registering anything else is refused at enable time.
        5. Enable it. That is the moment your code runs.

        ## Rules worth knowing before you write much

        - The manifest is JSON because it is read *before* anyone has
          decided to trust this package.
        - You are never handed the container, the request, an Eloquent
          model or a facade. Everything on the interface is a platform
          contract, which is what lets core change its storage without
          breaking you.
        - `sdk` in the manifest is checked against `Sdk::VERSION`. If core
          changes a contract you implement, this module refuses at install
          time with a sentence naming both versions, rather than breaking
          when a customer is waiting.
        - Anything thrown from `boot()` disables this module with the
          reason recorded. Throw when you cannot work; do not carry on half
          configured.

        Full guide: `docs/modules/README.md` in the platform repository.
        MD;
    }
}
