<?php

namespace CopiaDigital\TailwindSafelist;

use CopiaDigital\TailwindSafelist\Admin;
use CopiaDigital\TailwindSafelist\TailwindSafelist;
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

        // Initialize admin bar and AJAX handlers (administrators only)
        add_action('init', function () {
            new Admin();
        });

        // Auto-enqueue dynamic.css if the generated file exists.
        //
        // NOTE: The Sage layout calls wp_head() BEFORE the `@vite` directive
        // that prints app.css, so dynamic.css ends up *before* app.css in
        // the rendered document. That means for equal-specificity utility
        // class conflicts, the bundle wins. This is intentional — putting
        // dynamic.css after app.css would break Tailwind's natural
        // larger-breakpoint-overrides-smaller cascade (because Tailwind
        // achieves that purely through source order in the generated CSS).
        //
        // The right way to handle CMS classes that should override
        // template defaults is `tailwind-merge` at the template layer:
        // it strips conflicting classes BEFORE they reach the DOM, so
        // there's no cascade conflict in the first place.
        //   composer require gehrisandro/tailwind-merge-laravel
        $enqueue = function () {
            $cssPath = TailwindSafelist::getCssOutputPath();
            if (!file_exists($cssPath)) {
                return;
            }

            $themeDir = get_stylesheet_directory();
            $themeUri = get_stylesheet_directory_uri();
            if (str_starts_with($cssPath, $themeDir)) {
                $cssUrl = $themeUri . substr($cssPath, strlen($themeDir));
            } else {
                $cssUrl = content_url(str_replace(WP_CONTENT_DIR, '', $cssPath));
            }

            wp_enqueue_style(
                'tailwind-safelist-dynamic',
                $cssUrl,
                [],
                (string) filemtime($cssPath)
            );
        };

        add_action('wp_enqueue_scripts', $enqueue, 999);
        add_action('enqueue_block_assets', $enqueue, 999);

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
