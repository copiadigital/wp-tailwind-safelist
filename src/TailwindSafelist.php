<?php

namespace CopiaDigital\TailwindSafelist;

use Illuminate\Contracts\Foundation\Application;

class TailwindSafelist
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;

        // Hook into save_post for all post types (including CF7)
        add_action('save_post', [$this, 'handlePostSave'], 99, 2);

        // Hook into Contact Form 7 save action
        add_action('wpcf7_save_contact_form', [$this, 'handleCf7Save'], 10, 1);
    }

    /**
     * Handle post save for all post types.
     */
    public function handlePostSave(int $post_id, \WP_Post $post): void
    {
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        $content = $this->getRenderedContent($post);

        // For CF7, also get the form template
        if ($post->post_type === 'wpcf7_contact_form') {
            $formContent = get_post_meta($post_id, '_form', true);
            if ($formContent) {
                $content .= ' ' . $formContent;
            }
        }

        $classes = $this->getClasses($content);
        $classes = $this->filterClasses($classes);
        $updated = $this->updatePostMeta($post_id, $classes);

        if (!$updated) {
            return;
        }

        $classes = $this->updateAndFetchDbTable($post_id, $classes);
        $this->buildAssets($classes);
    }

    /**
     * Handle Contact Form 7 form save.
     */
    public function handleCf7Save($contactForm): void
    {
        $post_id = $contactForm->id();
        $post = get_post($post_id);

        if (!$post) {
            return;
        }

        // Get form content
        $content = $contactForm->prop('form') ?? '';

        // Also check mail templates for classes
        $mail = $contactForm->prop('mail');
        if (is_array($mail) && isset($mail['body'])) {
            $content .= ' ' . $mail['body'];
        }

        $classes = $this->getClasses($content);
        $classes = $this->filterClasses($classes);
        $updated = $this->updatePostMeta($post_id, $classes);

        if (!$updated) {
            return;
        }

        $classes = $this->updateAndFetchDbTable($post_id, $classes);
        $this->buildAssets($classes);
    }

    private function getRenderedContent(\WP_Post $post): string
    {
        $post_content = $post->post_content;
        $blocks = parse_blocks($post_content);

        // not a gutenberg page, just return post content
        if (empty($blocks) || !isset($blocks[0]['blockName'])) {
            return $post_content;
        }

        $html = '';

        // Extract classes from block attributes (className, align, etc.)
        // This is safer than trying to render blocks which can cause ACF init issues
        $this->extractBlockClasses($blocks, $html);

        // Also include the raw post content to catch inline classes
        $html .= $post_content;

        return $html;
    }

    /**
     * Extract classes from block attributes without rendering.
     * This avoids ACF initialization issues.
     */
    private function extractBlockClasses(array $blocks, string &$html): void
    {
        foreach ($blocks as $block) {
            // Extract className attribute (common in Gutenberg blocks)
            if (!empty($block['attrs']['className'])) {
                $html .= ' class="' . $block['attrs']['className'] . '"';
            }

            // Extract align attribute
            if (!empty($block['attrs']['align'])) {
                $html .= ' class="align' . $block['attrs']['align'] . '"';
            }

            // Extract backgroundColor/textColor
            if (!empty($block['attrs']['backgroundColor'])) {
                $html .= ' class="has-' . $block['attrs']['backgroundColor'] . '-background-color"';
            }
            if (!empty($block['attrs']['textColor'])) {
                $html .= ' class="has-' . $block['attrs']['textColor'] . '-color"';
            }

            // For ACF blocks, extract classes from the data array
            if (!empty($block['attrs']['data'])) {
                $this->extractClassesFromData($block['attrs']['data'], $html);
            }

            // Process nested blocks
            if (!empty($block['innerBlocks'])) {
                $this->extractBlockClasses($block['innerBlocks'], $html);
            }
        }
    }

    /**
     * Recursively extract class-like values from ACF block data.
     */
    private function extractClassesFromData(array $data, string &$html): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->extractClassesFromData($value, $html);
            } elseif (is_string($value)) {
                // Look for fields that might contain class names
                if (str_contains($key, 'class') || str_contains($key, 'style') || $key === 'className') {
                    $html .= ' class="' . $value . '"';
                }
            }
        }
    }

    private function getClasses(string $renderedContent): array
    {
        preg_match_all(
            '/(class="{1}([^"]+ ?)+?"{1}|class=\'{1}([^\']+ ?)+?\'{1})/',
            $renderedContent,
            $class_strings
        );

        $class_strings[2] = array_filter($class_strings[2]);
        $class_strings[3] = array_filter($class_strings[3]);

        if (empty($class_strings[2]) && empty($class_strings[3])) {
            return [];
        }

        $classes = [];
        array_map(function ($string) use (&$classes) {
            $classes = array_unique([...$classes, ...explode(' ', $string)]);
        }, [...$class_strings[2], ...$class_strings[3]]);

        asort($classes);

        return array_values($classes);
    }

    /**
     * Filter out non-tailwind classes.
     */
    private function filterClasses(array $classes): array
    {
        $excludePatterns = config('tailwind-safelist.exclude_patterns', [
            '/^wp-/',
            '/^wpcf7/',
        ]);

        return array_filter(
            array_map(function ($class) use ($excludePatterns) {
                $class = trim($class);

                // Skip empty strings
                if (empty($class)) {
                    return false;
                }

                // Check exclude patterns
                foreach ($excludePatterns as $pattern) {
                    if (preg_match($pattern, $class)) {
                        return false;
                    }
                }

                return $class;
            }, $classes)
        );
    }

    private function updatePostMeta(int $post_id, array $classes): bool
    {
        $base64_classes_after = base64_encode(implode(' ', $classes));
        $pm_result = get_post_meta($post_id, 'tailwind_safelist_classes');
        $base64_classes_before = ($pm_result ? $pm_result[0] : '');

        if ($base64_classes_before === $base64_classes_after) {
            return false;
        }

        if (update_post_meta($post_id, 'tailwind_safelist_classes', $base64_classes_after)) {
            return true;
        }

        if (add_post_meta($post_id, 'tailwind_safelist_classes', $base64_classes_after)) {
            return true;
        }

        return false;
    }

    private function updateAndFetchDbTable(int $post_id, array $classes): array
    {
        global $wpdb;

        $table_name = $this->getTableName();

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return $classes;
        }

        $wpdb->delete($table_name, ['post_id' => $post_id]);

        foreach ($classes as $class) {
            $wpdb->insert($table_name, [
                'class_name' => $class,
                'post_id' => $post_id,
            ]);
        }

        $results = $wpdb->get_results("SELECT DISTINCT class_name FROM $table_name");

        if (!empty($results)) {
            $classes = array_map(fn($obj) => $obj->class_name, $results);
        }

        asort($classes);

        return array_values($classes);
    }

    private function buildAssets(array $classes): void
    {
        $classes_base64 = base64_encode(implode(' ', $classes));
        $file_path = $this->getOutputPath();

        file_put_contents($file_path, $classes_base64);
    }

    /**
     * Get the database table name.
     */
    public static function getTableName(): string
    {
        global $wpdb;
        return "{$wpdb->base_prefix}tailwind_safelist";
    }

    /**
     * Get the output file path.
     */
    public static function getOutputPath(): string
    {
        return config('tailwind-safelist.output_path', get_stylesheet_directory() . '/tailwind-safelist.txt');
    }
}
