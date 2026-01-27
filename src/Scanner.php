<?php

namespace CopiaDigital\TailwindSafelist;

class Scanner
{
    /**
     * Scan all content sources for CSS classes.
     */
    public function scanAll(bool $includeTemplates = false): array
    {
        $allClasses = [];

        // 1. Scan all post types (including CF7 forms, pages, etc.)
        $allClasses = array_merge($allClasses, $this->scanAllPosts());

        // 2. Scan ACF options pages
        $allClasses = array_merge($allClasses, $this->scanAcfOptions());

        // 3. Scan widgets
        $allClasses = array_merge($allClasses, $this->scanWidgets());

        // 4. Scan theme templates (Blade files) - only if requested
        if ($includeTemplates) {
            $allClasses = array_merge($allClasses, $this->scanTemplates());
        }

        // Remove duplicates and filter
        $allClasses = array_unique($allClasses);
        $allClasses = $this->filterClasses($allClasses);
        asort($allClasses);

        return array_values($allClasses);
    }

    /**
     * Scan all post types for classes.
     */
    public function scanAllPosts(array $postTypes = []): array
    {
        $classes = [];

        if (empty($postTypes)) {
            $postTypes = get_post_types(['public' => true], 'names');

            // Add CF7 contact forms
            if (post_type_exists('wpcf7_contact_form')) {
                $postTypes['wpcf7_contact_form'] = 'wpcf7_contact_form';
            }
        }

        foreach ($postTypes as $postType) {
            $posts = get_posts([
                'post_type' => $postType,
                'posts_per_page' => -1,
                'post_status' => ['publish', 'draft', 'private'],
            ]);

            foreach ($posts as $post) {
                // Extract from post content
                $classes = array_merge($classes, $this->extractClasses($post->post_content));

                // Extract from ACF fields
                if (function_exists('get_fields')) {
                    try {
                        $acfFields = get_fields($post->ID);
                        if (is_array($acfFields) && !empty($acfFields)) {
                            $classes = array_merge($classes, $this->extractClassesFromAcfFields($acfFields));
                        }
                    } catch (\Throwable $e) {
                        // Skip this post if ACF throws an error
                    }
                }

                // Also scan raw post meta for non-ACF data
                $meta = get_post_meta($post->ID);
                foreach ($meta as $key => $values) {
                    if (str_starts_with($key, '_')) {
                        continue;
                    }
                    foreach ($values as $value) {
                        if (is_string($value)) {
                            $classes = array_merge($classes, $this->extractClasses($value));
                        } elseif (is_serialized($value)) {
                            $unserialized = maybe_unserialize($value);
                            $classes = array_merge($classes, $this->extractClassesFromArray($unserialized));
                        }
                    }
                }

                // For CF7, also check the form content
                if ($postType === 'wpcf7_contact_form') {
                    $cf7Content = get_post_meta($post->ID, '_form', true);
                    if ($cf7Content) {
                        $classes = array_merge($classes, $this->extractClasses($cf7Content));
                    }
                }
            }
        }

        return $classes;
    }

    /**
     * Extract classes from ACF fields including flexible content, repeaters, groups.
     */
    public function extractClassesFromAcfFields($fields, int $depth = 0): array
    {
        $classes = [];

        if ($depth > 10) {
            return $classes;
        }

        if (!is_array($fields)) {
            if (is_string($fields)) {
                return $this->extractClasses($fields);
            }
            return $classes;
        }

        foreach ($fields as $fieldName => $fieldValue) {
            if ($fieldValue === null || $fieldValue === '' || $fieldValue === false) {
                continue;
            }

            if (is_string($fieldValue)) {
                $classes = array_merge($classes, $this->extractClasses($fieldValue));

                if (is_string($fieldName) && $this->isClassField($fieldName)) {
                    $potentialClasses = preg_split('/\s+/', trim($fieldValue));
                    $classes = array_merge($classes, array_filter($potentialClasses));
                }
                continue;
            }

            if (is_numeric($fieldValue) || is_bool($fieldValue)) {
                continue;
            }

            if (is_array($fieldValue) && !empty($fieldValue)) {
                if ($this->isFlexibleContentLayout($fieldValue)) {
                    foreach ($fieldValue as $layout) {
                        if (is_array($layout)) {
                            $classes = array_merge($classes, $this->extractClassesFromAcfFields($layout, $depth + 1));
                        }
                    }
                } elseif ($this->isRepeaterField($fieldValue)) {
                    foreach ($fieldValue as $row) {
                        if (is_array($row)) {
                            $classes = array_merge($classes, $this->extractClassesFromAcfFields($row, $depth + 1));
                        }
                    }
                } else {
                    $classes = array_merge($classes, $this->extractClassesFromAcfFields($fieldValue, $depth + 1));
                }
                continue;
            }

            if (is_object($fieldValue)) {
                if ($fieldValue instanceof \WP_Post) {
                    $classes = array_merge($classes, $this->extractClasses($fieldValue->post_content ?? ''));
                }
            }
        }

        return $classes;
    }

    /**
     * Check if field name suggests it contains class names.
     */
    private function isClassField(string $fieldName): bool
    {
        $classPatterns = config('tailwind-safelist.class_field_patterns', [
            'class', 'classes', 'className', 'css_class', 'css_classes',
            'custom_class', 'additional_class', 'wrapper_class',
            'container_class', 'section_class', 'style', 'styles', 'tailwind',
        ]);

        $fieldNameLower = strtolower($fieldName);

        foreach ($classPatterns as $pattern) {
            if (str_contains($fieldNameLower, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if array is a flexible content field.
     */
    private function isFlexibleContentLayout(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        $firstItem = reset($value);
        return is_array($firstItem) && isset($firstItem['acf_fc_layout']);
    }

    /**
     * Check if array is a repeater field.
     */
    private function isRepeaterField(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        if (array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }

        $firstItem = reset($value);
        return is_array($firstItem);
    }

    /**
     * Scan ACF options pages.
     */
    public function scanAcfOptions(): array
    {
        $classes = [];

        if (!function_exists('get_fields')) {
            return $classes;
        }

        try {
            $optionsFields = get_fields('options');
            if (is_array($optionsFields) && !empty($optionsFields)) {
                $classes = array_merge($classes, $this->extractClassesFromAcfFields($optionsFields));
            }

            if (function_exists('acf_get_options_pages')) {
                $optionsPages = acf_get_options_pages();
                if (!empty($optionsPages) && is_array($optionsPages)) {
                    foreach ($optionsPages as $page) {
                        $pageSlug = $page['post_id'] ?? null;
                        if ($pageSlug && $pageSlug !== 'options') {
                            $fields = get_fields($pageSlug);
                            if (is_array($fields) && !empty($fields)) {
                                $classes = array_merge($classes, $this->extractClassesFromAcfFields($fields));
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Skip on error
        }

        return $classes;
    }

    /**
     * Scan widgets.
     */
    public function scanWidgets(): array
    {
        $classes = [];

        $sidebarsWidgets = get_option('sidebars_widgets', []);

        foreach ($sidebarsWidgets as $sidebar => $widgets) {
            if (!is_array($widgets)) {
                continue;
            }

            foreach ($widgets as $widgetId) {
                $widgetBase = preg_replace('/-\d+$/', '', $widgetId);
                $widgetSettings = get_option('widget_' . $widgetBase);

                if ($widgetSettings) {
                    $classes = array_merge($classes, $this->extractClassesFromArray($widgetSettings));
                }
            }
        }

        return $classes;
    }

    /**
     * Scan Blade templates.
     */
    public function scanTemplates(): array
    {
        $classes = [];
        $templateDir = get_stylesheet_directory() . '/resources/views';

        if (!is_dir($templateDir)) {
            return $classes;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                $classes = array_merge($classes, $this->extractClasses($content));
            }
        }

        return $classes;
    }

    /**
     * Extract classes from a string.
     */
    public function extractClasses(string $content): array
    {
        if (empty($content)) {
            return [];
        }

        $classes = [];

        // Match class="..." and class='...'
        preg_match_all('/class=["\']([^"\']+)["\']/', $content, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $classString) {
                $classList = preg_split('/\s+/', $classString);
                $classes = array_merge($classes, $classList);
            }
        }

        // Also match @class([...]) Blade directive
        preg_match_all('/@class\(\[([^\]]+)\]\)/', $content, $bladeMatches);
        if (!empty($bladeMatches[1])) {
            foreach ($bladeMatches[1] as $bladeClass) {
                preg_match_all('/["\']([^"\']+)["\']/', $bladeClass, $quotedMatches);
                if (!empty($quotedMatches[1])) {
                    foreach ($quotedMatches[1] as $classString) {
                        $classList = preg_split('/\s+/', $classString);
                        $classes = array_merge($classes, $classList);
                    }
                }
            }
        }

        return $classes;
    }

    /**
     * Extract classes from an array.
     */
    public function extractClassesFromArray($data): array
    {
        $classes = [];

        if (!is_array($data)) {
            if (is_string($data)) {
                return $this->extractClasses($data);
            }
            return $classes;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $classes = array_merge($classes, $this->extractClassesFromArray($value));
            } elseif (is_string($value)) {
                $classes = array_merge($classes, $this->extractClasses($value));
            }
        }

        return $classes;
    }

    /**
     * Filter classes based on exclude patterns.
     */
    public function filterClasses(array $classes): array
    {
        $excludePatterns = config('tailwind-safelist.exclude_patterns', [
            '/^wp-/',
            '/^wpcf7/',
        ]);

        return array_filter($classes, function ($class) use ($excludePatterns) {
            $class = trim($class);

            if (empty($class)) {
                return false;
            }

            foreach ($excludePatterns as $pattern) {
                if (preg_match($pattern, $class)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Save classes to database and file.
     */
    public function saveClasses(array $classes): void
    {
        global $wpdb;

        $table_name = TailwindSafelist::getTableName();

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            $wpdb->query("TRUNCATE TABLE $table_name");

            foreach ($classes as $class) {
                $wpdb->insert($table_name, [
                    'class_name' => $class,
                    'post_id' => 0,
                ]);
            }
        }

        // Save to base64 file (for safelist approach)
        $classes_base64 = base64_encode(implode(' ', $classes));
        $file_path = TailwindSafelist::getOutputPath();
        file_put_contents($file_path, $classes_base64);

        // Also save as HTML file for Tailwind content scanning
        // This allows yarn dev to pick up changes without restart
        $themeDir = get_stylesheet_directory();
        $htmlPath = $themeDir . '/tailwind-safelist.html';
        $htmlContent = "<!-- Auto-generated by wp-tailwind-safelist. Do not edit. -->\n";
        $htmlContent .= '<div class="' . esc_attr(implode(' ', $classes)) . '"></div>';
        file_put_contents($htmlPath, $htmlContent);
    }
}
