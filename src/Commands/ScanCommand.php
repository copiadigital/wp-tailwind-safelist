<?php

namespace CopiaDigital\TailwindSafelist\Commands;

use CopiaDigital\TailwindSafelist\Scanner;
use CopiaDigital\TailwindSafelist\TailwindSafelist;
use Illuminate\Console\Command;

class ScanCommand extends Command
{
    protected $signature = 'tailwind:scan
                            {--post-types=* : Specific post types to scan}
                            {--include-templates : Include scanning Blade templates (skipped by default)}
                            {--no-build : Skip the yarn build step after scanning}';

    protected $description = 'Scan all content (posts, pages, CF7 forms, ACF fields, etc.) and rebuild the Tailwind safelist.';

    public function handle(): int
    {
        $this->info('Scanning all content for Tailwind classes...');

        $scanner = new Scanner();
        $includeTemplates = $this->option('include-templates');

        $allClasses = [];

        // 1. Scan all post types
        $postTypes = $this->option('post-types');
        if (empty($postTypes)) {
            $postTypes = get_post_types(['public' => true], 'names');
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
                $allClasses = array_merge($allClasses, $scanner->extractClasses($post->post_content));

                if (function_exists('get_fields')) {
                    try {
                        $acfFields = get_fields($post->ID);
                        if (is_array($acfFields) && !empty($acfFields)) {
                            $allClasses = array_merge($allClasses, $scanner->extractClassesFromAcfFields($acfFields));
                        }
                    } catch (\Throwable $e) {
                        // Skip
                    }
                }

                $meta = get_post_meta($post->ID);
                foreach ($meta as $key => $values) {
                    if (str_starts_with($key, '_')) {
                        continue;
                    }
                    foreach ($values as $value) {
                        if (is_string($value)) {
                            $allClasses = array_merge($allClasses, $scanner->extractClasses($value));
                        } elseif (is_serialized($value)) {
                            $unserialized = maybe_unserialize($value);
                            $allClasses = array_merge($allClasses, $scanner->extractClassesFromArray($unserialized));
                        }
                    }
                }

                if ($postType === 'wpcf7_contact_form') {
                    $cf7Content = get_post_meta($post->ID, '_form', true);
                    if ($cf7Content) {
                        $allClasses = array_merge($allClasses, $scanner->extractClasses($cf7Content));
                    }
                }
            }

            $this->line(sprintf('  - %s: %d items scanned', $postType, count($posts)));
        }

        // 2. Scan ACF options pages
        $this->line('Scanning ACF options pages...');
        $allClasses = array_merge($allClasses, $scanner->scanAcfOptions());

        // 3. Scan widgets
        $this->line('Scanning widgets...');
        $allClasses = array_merge($allClasses, $scanner->scanWidgets());

        // 4. Scan templates if requested
        if ($includeTemplates) {
            $this->line('Scanning Blade templates...');
            $allClasses = array_merge($allClasses, $scanner->scanTemplates());
        }

        // Remove duplicates and filter
        $allClasses = array_unique($allClasses);
        $allClasses = $scanner->filterClasses($allClasses);
        asort($allClasses);
        $allClasses = array_values($allClasses);

        // Save
        $scanner->saveClasses($allClasses);

        $this->info(sprintf('Found %d unique classes.', count($allClasses)));
        $this->info('Safelist saved to ' . basename(TailwindSafelist::getOutputPath()));

        // Trigger yarn build unless skipped
        if (!$this->option('no-build')) {
            $this->line('');
            $this->info('Triggering yarn build...');
            $this->call('tailwind:build');
        }

        return self::SUCCESS;
    }
}
