<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Ai\AiProviders;
use App\Infrastructure\Modules\ActiveModules;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Where the assistant's providers come from (ADR 0050).
 *
 * **Core registers none**, which is the whole shape of this file. Every other
 * registry here starts with something core ships — a mail channel, a manual
 * gateway, a file probe — because "an operator does it by hand" has to be a
 * real answer. There is no hand-operated AI provider, and inventing a default
 * vendor would be core choosing who a seller's customers' words are sent to.
 *
 * So an installation that has enabled no AI module has an empty registry, and
 * `Draft` refuses with `noProvider()`. That is the shipped state and it is
 * correct: nothing leaves until somebody installs a package and turns a
 * feature on.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiProviders::class, function (Application $app): AiProviders {
            $registry = new AiProviders;

            foreach ($app->make(ActiveModules::class)->aiProviders() as $provider) {
                $registry->register($provider);
            }

            return $registry;
        });
    }
}
