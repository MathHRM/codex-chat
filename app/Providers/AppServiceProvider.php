<?php

namespace App\Providers;

use App\Services\EvolutionContract;
use App\Support\BotConfig;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BotConfig::class, fn (): BotConfig => new BotConfig(config('bot')));
        $this->app->singleton(EvolutionContract::class, function (): EvolutionContract {
            $config = $this->app->make(BotConfig::class);

            return new EvolutionContract($config->evolutionUrl, $config->evolutionKey, $config->instance, $config->ownerNumber);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
