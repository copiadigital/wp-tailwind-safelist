<?php

namespace CopiaDigital\TailwindSafelist;

use CopiaDigital\TailwindSafelist\Commands\ScanCommand;
use CopiaDigital\TailwindSafelist\Commands\UpdateDbCommand;
use Illuminate\Support\ServiceProvider;

class TailwindSafelistServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/tailwind-safelist.php', 'tailwind-safelist');

        $this->app->singleton(TailwindSafelist::class, function () {
            return new TailwindSafelist($this->app);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/tailwind-safelist.php' => $this->app->configPath('tailwind-safelist.php'),
        ], 'tailwind-safelist-config');

        $this->commands([
            UpdateDbCommand::class,
            ScanCommand::class,
        ]);

        // Only initialize on admin requests where post saving occurs
        // This avoids ACF timing issues on the frontend
        if (is_admin()) {
            add_action('init', function () {
                $this->app->make(TailwindSafelist::class);
            }, 99);
        }
    }
}
