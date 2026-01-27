<?php

namespace CopiaDigital\TailwindSafelist\Commands;

use CopiaDigital\TailwindSafelist\TailwindSafelist;
use Illuminate\Console\Command;

class ScanCommand extends Command
{
    protected $signature = 'tailwind:scan
                            {--post-types=* : Specific post types to scan}
                            {--skip-templates : Skip scanning Blade templates}';

    protected $description = 'Scan all content (posts, pages, CF7 forms, ACF fields, etc.) and rebuild the Tailwind safelist.';

    public function handle(): int
    {
        $this->info('Scanning all content for Tailwind classes...');

        $allClasses = [];

        // 1. Scan all post types (including CF7 forms, pages, etc.)
        $allClasses = array_merge($allClasses, $this->scanAllPosts());

        // 2. Scan ACF options pages
        $allClasses = array_merge($allClasses, $this->scanAcfOptions());

        // 3. Scan widgets
        $allClasses = array_merge($allClasses, $this->scanWidgets());

        // 4. Scan theme templates (Blade files)
        if (!$this->option('skip-templates')) {
            $allClasses = array_merge($allClasses, $this->scanTemplates());
        }

        // Remove duplicates and filter
        $allClasses = array_unique($allClasses);
        $allClasses = $this->filterClasses($allClasses);
        asort($allClasses);
        $allClasses = array_values($allClasses);

        // Save to database and file
        $this->saveClasses($allClasses);

        $this->info(sprintf('Found %d unique classes.', count($allClasses)));
        $this->info('Safelist saved to ' . basename(TailwindSafelist::getOutputPath()));

        return self::SUCCESS;
    }

    private function scanAllPosts(): array
    {
        $classes = [];

        // Get post types to scan
        $postTypes = $this->option('post-types');
        if (empty($postTypes)) {
            $postTypes = get_post_types(['public' => true], 'names');

            // Add CF7 contact forms
            if (post_type_exists('wpcf7_contact_form')) {
                $postTypes['wpcf7_contact_form'] = 'wpcf7_contact_form';
            }
        }

        $this->line('Scanning post types: ' . implode(', ', $postTypes));

        foreach ($postTypes as $postType) {
            $posts = get_posts([
                'post_type' => $postType,
                'posts_per_page' => -1,
                'post_status' => ['publish', 'draft', 'private'],
            ]);

            foreach ($posts as $post) {
                // Extract from post content
                $classes = array_merge($classes, $this->extractClasses($post->post_content));

                // Extract from ACF fields (flexible content, repeaters, groups, etc.)
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
                    // Skip ACF internal fields (already handled above)
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

            $this->line(sprintf('  - %s: %d items scanned', $postType, count($posts)));
        }

        return $classes;
    }

    /**
     * Extract classes from ACF fields including flexible content, repeaters, groups.
     */
    private function extractClassesFromAcfFields($fields, int $depth = 0): array
    {
        $classes = [];

        // Prevent infinite recursion and handle invalid input
        if ($depth > 10) {
            return $classes;
        }

        // Handle non-array values
        if (!is_array($fields)) {
            if (is_string($fields)) {
                return $this->extractClasses($fields);
            }
            return $classes;
        }

        foreach ($fields as $fieldName => $fieldValue) {
            // Skip null, empty, or false values
            if ($fieldValue === null || $fieldValue === '' || $fieldValue === false) {
                continue;
            }

            // Handle string values - extract classes
            if (is_string($fieldValue)) {
                $classes = array_merge($classes, $this->extractClasses($fieldValue));

                // Also check if the field name suggests it contains classes
                if (is_string($fieldName) && $this->isClassField($fieldName)) {
                    // Add the raw value as potential class names
                    $potentialClasses = preg_split('/\s+/', trim($fieldValue));
                    $classes = array_merge($classes, array_filter($potentialClasses));
                }
                continue;
            }

            // Handle numeric/bool values - skip
            if (is_numeric($fieldValue) || is_bool($fieldValue)) {
                continue;
            }

            // Handle arrays (repeaters, flexible content, groups, galleries, etc.)
            if (is_array($fieldValue) && !empty($fieldValue)) {
                // Check if it's a flexible content layout
                if ($this->isFlexibleContentLayout($fieldValue)) {
                    foreach ($fieldValue as $layout) {
                        if (is_array($layout)) {
                            $classes = array_merge($classes, $this->extractClassesFromAcfFields($layout, $depth + 1));
                        }
                    }
                }
                // Check if it's a repeater (array of arrays)
                elseif ($this->isRepeaterField($fieldValue)) {
                    foreach ($fieldValue as $row) {
                        if (is_array($row)) {
                            $classes = array_merge($classes, $this->extractClassesFromAcfFields($row, $depth + 1));
                        }
                    }
                }
                // Check if it's a group or single nested array
                else {
                    $classes = array_merge($classes, $this->extractClassesFromAcfFields($fieldValue, $depth + 1));
                }
                continue;
            }

            // Handle objects (like post objects, user objects)
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
            'class',
            'classes',
            'className',
            'css_class',
            'css_classes',
            'custom_class',
            'additional_class',
            'wrapper_class',
            'container_class',
            'section_class',
            'style',
            'styles',
            'tailwind',
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
     * Check if array is a flexible content field (has acf_fc_layout key).
     */
    private function isFlexibleContentLayout(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        // Check first item for acf_fc_layout key
        $firstItem = reset($value);
        return is_array($firstItem) && isset($firstItem['acf_fc_layout']);
    }

    /**
     * Check if array is a repeater field (sequential array of arrays).
     */
    private function isRepeaterField(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        // Check if it's a sequential array
        if (array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }

        // Check if first item is an array
        $firstItem = reset($value);
        return is_array($firstItem);
    }

    private function scanAcfOptions(): array
    {
        $classes = [];

        if (!function_exists('get_fields')) {
            return $classes;
        }

        $this->line('Scanning ACF options pages...');

        try {
            // Scan main options
            $optionsFields = get_fields('options');
            if (is_array($optionsFields) && !empty($optionsFields)) {
                $classes = array_merge($classes, $this->extractClassesFromAcfFields($optionsFields));
                $this->line('  - options: scanned');
            }

            // Scan registered options pages
            if (function_exists('acf_get_options_pages')) {
                $optionsPages = acf_get_options_pages();
                if (!empty($optionsPages) && is_array($optionsPages)) {
                    foreach ($optionsPages as $page) {
                        $pageSlug = $page['post_id'] ?? null;
                        if ($pageSlug && $pageSlug !== 'options') {
                            $fields = get_fields($pageSlug);
                            if (is_array($fields) && !empty($fields)) {
                                $classes = array_merge($classes, $this->extractClassesFromAcfFields($fields));
                                $this->line(sprintf('  - %s: scanned', $pageSlug));
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->warn('  - Error scanning options: ' . $e->getMessage());
        }

        return $classes;
    }

    private function scanWidgets(): array
    {
        $classes = [];

        $this->line('Scanning widgets...');

        $sidebarsWidgets = get_option('sidebars_widgets', []);

        foreach ($sidebarsWidgets as $sidebar => $widgets) {
            if (!is_array($widgets)) {
                continue;
            }

            foreach ($widgets as $widgetId) {
                // Get widget settings
                $widgetBase = preg_replace('/-\d+$/', '', $widgetId);
                $widgetSettings = get_option('widget_' . $widgetBase);

                if ($widgetSettings) {
                    $classes = array_merge($classes, $this->extractClassesFromArray($widgetSettings));
                }
            }
        }

        return $classes;
    }

    private function scanTemplates(): array
    {
        $classes = [];
        $templateDir = get_stylesheet_directory() . '/resources/views';

        if (!is_dir($templateDir)) {
            return $classes;
        }

        $this->line('Scanning Blade templates...');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                $classes = array_merge($classes, $this->extractClasses($content));
                $count++;
            }
        }

        $this->line(sprintf('  - %d template files scanned', $count));

        return $classes;
    }

    private function extractClasses(string $content): array
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
                // Extract quoted strings from the array
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

    private function extractClassesFromArray($data): array
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

    private function filterClasses(array $classes): array
    {
        $excludePatterns = config('tailwind-safelist.exclude_patterns', [
            '/^wp-/',
            '/^wpcf7/',
        ]);

        return array_filter($classes, function ($class) use ($excludePatterns) {
            $class = trim($class);

            // Skip empty
            if (empty($class)) {
                return false;
            }

            // Check exclude patterns
            foreach ($excludePatterns as $pattern) {
                if (preg_match($pattern, $class)) {
                    return false;
                }
            }

            return true;
        });
    }

    private function saveClasses(array $classes): void
    {
        global $wpdb;

        $table_name = TailwindSafelist::getTableName();

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            // Clear the table
            $wpdb->query("TRUNCATE TABLE $table_name");

            // Insert all classes with post_id 0 (global scan)
            foreach ($classes as $class) {
                $wpdb->insert($table_name, [
                    'class_name' => $class,
                    'post_id' => 0,
                ]);
            }
        }

        // Save to file
        $classes_base64 = base64_encode(implode(' ', $classes));
        $file_path = TailwindSafelist::getOutputPath();
        file_put_contents($file_path, $classes_base64);
    }
}
