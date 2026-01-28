<?php

namespace CopiaDigital\TailwindSafelist;

class Admin
{
    /**
     * Initialize admin functionality.
     * Available in all environments, restricted to administrators only.
     */
    public function __construct()
    {
        add_action('admin_bar_menu', [$this, 'addAdminBarItem'], 100);
        add_action('wp_ajax_tailwind_safelist_scan', [$this, 'handleAjaxScan']);
        add_action('admin_footer', [$this, 'addAdminScript']);
        add_action('wp_footer', [$this, 'addAdminScript']);
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

        // Write to trigger file (for external watchers if needed)
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
     * Execute yarn build via WP-CLI.
     *
     * Uses `wp acorn tailwind:build` which handles OS detection
     * and runs the appropriate standalone yarn binary.
     */
    private function executeYarnBuild(): void
    {
        // Check for custom build command first
        $buildCommand = config('tailwind-safelist.build_command');

        if (!empty($buildCommand)) {
            $this->runCommand($buildCommand);
            return;
        }

        // Use WP-CLI to run the build command
        $wpCliPath = $this->getWpCliPath();

        if (!$wpCliPath) {
            error_log('Tailwind Safelist: WP-CLI not found, relying on trigger file');
            return;
        }

        $themeDir = get_stylesheet_directory();
        $buildDir = $themeDir . '/public/build';

        // Fix permissions before build to avoid EACCES errors
        // This handles cases where files were created by different users (root vs www-data)
        $this->fixBuildPermissions($buildDir);

        // Build the WP-CLI command
        // Note: $wpCliPath is already shell-escaped by getWpCliPath()
        $command = sprintf(
            'cd %s && %s acorn tailwind:build --allow-root 2>&1',
            escapeshellarg($themeDir),
            $wpCliPath
        );

        $this->runCommand($command);

        // Fix permissions after build so subsequent builds can overwrite
        $this->fixBuildPermissions($buildDir);
    }

    /**
     * Fix permissions on the build directory to avoid EACCES errors.
     *
     * When builds run from different contexts (PHP container, node container, host),
     * files may be owned by different users. This makes the build directory
     * world-writable since it only contains compiled assets (not sensitive data).
     */
    private function fixBuildPermissions(string $buildDir): void
    {
        if (!is_dir($buildDir)) {
            return;
        }

        // Make build directory and all contents world-writable
        // This is safe because public/build only contains compiled CSS/JS assets
        $command = sprintf('chmod -R a+w %s 2>/dev/null', escapeshellarg($buildDir));
        @exec($command);
    }

    /**
     * Get the path to WP-CLI executable.
     * Uses wp-cli.phar bundled with this package.
     *
     * Returns a shell-safe command string that can be used directly in shell commands.
     * The path component is properly escaped with escapeshellarg().
     *
     * @return string|null Shell-safe WP-CLI command or null if not found
     */
    private function getWpCliPath(): ?string
    {
        // Use wp-cli.phar bundled with this package (same directory as yarn binaries)
        $packageDir = dirname(__DIR__);
        $wpCliPhar = $packageDir . '/wp-cli.phar';

        if (file_exists($wpCliPhar)) {
            // Return with php command and properly escaped phar path
            return 'php ' . escapeshellarg($wpCliPhar);
        }

        // Fallback to wp command in PATH
        $check = shell_exec('which wp 2>/dev/null');
        if (!empty(trim($check ?? ''))) {
            return escapeshellarg(trim($check));
        }

        return null;
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
