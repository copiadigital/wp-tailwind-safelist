<?php

namespace CopiaDigital\TailwindSafelist;

class Admin
{
    /**
     * Initialize admin functionality.
     */
    public function __construct()
    {
        // Only in development environment
        if (!$this->isDevelopment()) {
            return;
        }

        add_action('admin_bar_menu', [$this, 'addAdminBarItem'], 100);
        add_action('wp_ajax_tailwind_safelist_scan', [$this, 'handleAjaxScan']);
        add_action('admin_footer', [$this, 'addAdminScript']);
        add_action('wp_footer', [$this, 'addAdminScript']);
    }

    /**
     * Check if we're in development environment.
     */
    private function isDevelopment(): bool
    {
        $env = defined('WP_ENV') ? WP_ENV : (defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'production');
        return in_array($env, ['development', 'local', 'dev']);
    }

    /**
     * Add admin bar menu item.
     */
    public function addAdminBarItem(\WP_Admin_Bar $adminBar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $adminBar->add_node([
            'id' => 'tailwind-safelist',
            'title' => '<span class="ab-icon dashicons dashicons-image-rotate" style="margin-top: 2px;"></span> Re-process Tailwind',
            'href' => '#',
            'meta' => [
                'class' => 'tailwind-safelist-trigger',
                'title' => 'Scan content and rebuild Tailwind safelist',
            ],
        ]);
    }

    /**
     * Handle AJAX scan request.
     */
    public function handleAjaxScan(): void
    {
        check_ajax_referer('tailwind_safelist_scan', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        try {
            $scanner = new Scanner();
            $classes = $scanner->scanAll();

            // Save to database and file
            $scanner->saveClasses($classes);

            // Trigger build via watcher file
            $this->triggerBuild();

            wp_send_json_success([
                'message' => sprintf('Found %d unique classes. Safelist updated. Build triggered.', count($classes)),
                'classes_count' => count($classes),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error([
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Trigger yarn build via watcher file.
     */
    private function triggerBuild(): void
    {
        $vendorDir = dirname(__DIR__);
        $themeDir = get_stylesheet_directory();

        // Write to trigger file for build-watcher.cjs
        $triggerFile = $themeDir . '/.tailwind-build-trigger';
        file_put_contents($triggerFile, time());

        // Also touch tailwind.config.js for when yarn dev is running
        $tailwindConfig = $themeDir . '/tailwind.config.js';
        if (file_exists($tailwindConfig)) {
            touch($tailwindConfig);
        }

        // Try to execute build command if configured
        $this->executeYarnBuild();
    }

    /**
     * Execute yarn build.
     *
     * Priority:
     * 1. Use configured build_command if set
     * 2. If not in Docker, run standalone yarn binary directly
     * 3. If in Docker, rely on trigger file + build-watcher
     */
    private function executeYarnBuild(): void
    {
        // 1. Check for configured build command first
        $buildCommand = config('tailwind-safelist.build_command');

        if (!empty($buildCommand)) {
            $this->runCommand($buildCommand);
            return;
        }

        // 2. If not in Docker, try to run standalone yarn binary directly
        if (!$this->isRunningInDocker()) {
            $this->runStandaloneYarnBuild();
            return;
        }

        // 3. In Docker without build_command - rely on trigger file + watcher
        // The trigger file was already written in triggerBuild()
    }

    /**
     * Check if running inside a Docker container.
     */
    private function isRunningInDocker(): bool
    {
        // Check for .dockerenv file (most reliable)
        if (file_exists('/.dockerenv')) {
            return true;
        }

        // Check cgroup for docker/container references
        if (file_exists('/proc/1/cgroup')) {
            $cgroup = @file_get_contents('/proc/1/cgroup');
            if ($cgroup && (str_contains($cgroup, 'docker') || str_contains($cgroup, '/lxc/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect the operating system.
     *
     * @return string 'linux', 'macos', or 'unknown'
     */
    private function detectOS(): string
    {
        $os = strtolower(PHP_OS_FAMILY);

        if ($os === 'darwin') {
            return 'macos';
        }

        if ($os === 'linux') {
            return 'linux';
        }

        // Fallback check using PHP_OS for older PHP versions
        $phpOs = strtolower(PHP_OS);
        if (str_contains($phpOs, 'darwin')) {
            return 'macos';
        }
        if (str_contains($phpOs, 'linux') || str_contains($phpOs, 'ubuntu')) {
            return 'linux';
        }

        return 'unknown';
    }

    /**
     * Run the standalone yarn binary to execute build.
     */
    private function runStandaloneYarnBuild(): void
    {
        $os = $this->detectOS();

        if ($os === 'unknown') {
            error_log('Tailwind Safelist: Unable to detect OS for yarn build');
            return;
        }

        $vendorDir = dirname(__DIR__);
        $themeDir = get_stylesheet_directory();
        $yarnBinary = $vendorDir . '/yarn-' . $os;

        if (!file_exists($yarnBinary)) {
            error_log('Tailwind Safelist: Yarn binary not found at ' . $yarnBinary);
            return;
        }

        // Make executable if needed
        if (!is_executable($yarnBinary)) {
            @chmod($yarnBinary, 0755);
        }

        $command = sprintf(
            'cd %s && %s build 2>&1',
            escapeshellarg($themeDir),
            escapeshellarg($yarnBinary)
        );

        $this->runCommand($command);
    }

    /**
     * Run a shell command and log the result.
     */
    private function runCommand(string $command): void
    {
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            error_log('Tailwind Safelist: Build failed with code ' . $returnCode . ': ' . implode("\n", $output));
        } else {
            error_log('Tailwind Safelist: Build completed successfully');
        }
    }

    /**
     * Add admin bar JavaScript and styles.
     */
    public function addAdminScript(): void
    {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }

        $nonce = wp_create_nonce('tailwind_safelist_scan');
        ?>
        <style>
            #wpadminbar .tailwind-safelist-trigger .ab-icon:before {
                content: "\f531";
                top: 2px;
            }
            #wpadminbar .tailwind-safelist-trigger.processing .ab-icon {
                animation: tw-spin 1s linear infinite;
            }
            @keyframes tw-spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            #wpadminbar .tailwind-safelist-trigger.success .ab-item {
                background: #00a32a !important;
                color: #fff !important;
            }
            #wpadminbar .tailwind-safelist-trigger.error .ab-item {
                background: #d63638 !important;
                color: #fff !important;
            }

            /* WordPress-style notice */
            .tw-safelist-notice {
                position: fixed;
                top: 42px;
                right: 20px;
                z-index: 99999;
                padding: 12px 20px;
                border-left: 4px solid #00a32a;
                background: #fff;
                box-shadow: 0 1px 4px rgba(0,0,0,0.15);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                font-size: 13px;
                line-height: 1.5;
                max-width: 400px;
                animation: tw-notice-slide-in 0.3s ease-out;
            }
            .tw-safelist-notice.error {
                border-left-color: #d63638;
            }
            .tw-safelist-notice-close {
                position: absolute;
                top: 8px;
                right: 8px;
                background: none;
                border: none;
                cursor: pointer;
                padding: 0;
                font-size: 16px;
                color: #666;
                line-height: 1;
            }
            .tw-safelist-notice-close:hover {
                color: #000;
            }
            @keyframes tw-notice-slide-in {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        </style>
        <script>
        (function() {
            function showNotice(message, isError) {
                // Remove existing notices
                var existing = document.querySelectorAll('.tw-safelist-notice');
                existing.forEach(function(el) { el.remove(); });

                var notice = document.createElement('div');
                notice.className = 'tw-safelist-notice' + (isError ? ' error' : '');
                notice.innerHTML = '<button type="button" class="tw-safelist-notice-close">&times;</button>' + message;

                document.body.appendChild(notice);

                notice.querySelector('.tw-safelist-notice-close').addEventListener('click', function() {
                    notice.remove();
                });

                // Auto-dismiss after 5 seconds
                setTimeout(function() {
                    if (notice.parentNode) {
                        notice.style.animation = 'tw-notice-slide-in 0.3s ease-out reverse';
                        setTimeout(function() { notice.remove(); }, 300);
                    }
                }, 5000);
            }

            document.addEventListener('DOMContentLoaded', function() {
                var trigger = document.querySelector('#wp-admin-bar-tailwind-safelist > a');
                if (!trigger) return;

                trigger.addEventListener('click', function(e) {
                    e.preventDefault();

                    var parent = this.closest('.tailwind-safelist-trigger');
                    if (parent.classList.contains('processing')) return;

                    parent.classList.remove('success', 'error');
                    parent.classList.add('processing');

                    var formData = new FormData();
                    formData.append('action', 'tailwind_safelist_scan');
                    formData.append('nonce', '<?php echo esc_js($nonce); ?>');

                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        parent.classList.remove('processing');
                        if (data.success) {
                            parent.classList.add('success');
                            showNotice(data.data.message, false);
                        } else {
                            parent.classList.add('error');
                            showNotice('Error: ' + (data.data ? data.data.message : 'Unknown error'), true);
                        }
                        setTimeout(function() {
                            parent.classList.remove('success', 'error');
                        }, 3000);
                    })
                    .catch(function(error) {
                        parent.classList.remove('processing');
                        parent.classList.add('error');
                        showNotice('Error: ' + error.message, true);
                        setTimeout(function() {
                            parent.classList.remove('error');
                        }, 3000);
                    });
                });
            });
        })();
        </script>
        <?php
    }
}
