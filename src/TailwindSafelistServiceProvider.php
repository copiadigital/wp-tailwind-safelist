<?php

namespace CopiaDigital\TailwindSafelist;

use CopiaDigital\TailwindSafelist\Commands\BuildCommand;
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

        $this->app->singleton(Scanner::class, function () {
            return new Scanner();
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
            BuildCommand::class,
            UpdateDbCommand::class,
            ScanCommand::class,
        ]);

        // Initialize admin bar and AJAX handlers (development only)
        add_action('init', function () {
            new Admin();
        });

        // Auto-scan on post save is disabled by default
        // Only enable if explicitly configured AND in development environment
        if (config('tailwind-safelist.auto_scan_on_save', false) && is_admin() && $this->isDevelopment()) {
            add_action('init', function () {
                $this->app->make(TailwindSafelist::class);
            }, 99);
        }
    }

    /**
     * Check if we're in development environment.
     */
    private function isDevelopment(): bool
    {
        $env = defined('WP_ENV') ? WP_ENV : (defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'production');
        return in_array($env, ['development', 'local', 'dev']);
    }
}
