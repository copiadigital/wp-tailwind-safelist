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
        $themeDir = get_stylesheet_directory();

        // Write to trigger file for build-watcher.sh
        $triggerFile = $themeDir . '/.tailwind-build-trigger';
        file_put_contents($triggerFile, time());

        // Also touch tailwind.config.js for when yarn dev is running
        $tailwindConfig = $themeDir . '/tailwind.config.js';
        if (file_exists($tailwindConfig)) {
            touch($tailwindConfig);
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
