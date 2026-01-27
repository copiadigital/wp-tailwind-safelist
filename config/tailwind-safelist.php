<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto Scan on Save
    |--------------------------------------------------------------------------
    |
    | When enabled, automatically scans content when posts are saved.
    | Disabled by default - use the admin bar button or CLI command instead.
    |
    */

    'auto_scan_on_save' => false,

    /*
    |--------------------------------------------------------------------------
    | Output Path
    |--------------------------------------------------------------------------
    |
    | The path where the safelist file will be saved. This file contains
    | base64-encoded class names that should be read by your Tailwind config.
    |
    */

    'output_path' => get_stylesheet_directory() . '/tailwind-safelist.txt',

    /*
    |--------------------------------------------------------------------------
    | Exclude Patterns
    |--------------------------------------------------------------------------
    |
    | Regular expression patterns for classes to exclude from the safelist.
    | WordPress and plugin-specific classes that aren't Tailwind classes.
    |
    */

    'exclude_patterns' => [
        '/^wp-/',           // WordPress classes
        '/^wpcf7/',         // Contact Form 7 classes
        '/^acf-/',          // ACF classes
        '/^block-/',        // Block editor classes
        '/^is-/',           // State classes
        '/^has-/',          // Has-* classes (unless you use them in Tailwind)
        '/^alignwide$/',
        '/^alignfull$/',
        '/^alignleft$/',
        '/^alignright$/',
        '/^aligncenter$/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Class Field Patterns
    |--------------------------------------------------------------------------
    |
    | Field name patterns that indicate the field contains CSS class names.
    | When scanning ACF fields, if a field name contains any of these patterns,
    | the entire field value will be treated as class names.
    |
    */

    'class_field_patterns' => [
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Build Command (Development Only)
    |--------------------------------------------------------------------------
    |
    | Shell command to run after updating the safelist. This is executed
    | directly when clicking the admin bar button.
    |
    | For Docker environments, use the docker exec command:
    | 'docker exec wp_base-node-1 yarn build'
    |
    | For local development without Docker:
    | 'cd ' . get_stylesheet_directory() . ' && yarn build'
    |
    | Set to null to disable automatic builds.
    |
    */

    'build_command' => null,

    /*
    |--------------------------------------------------------------------------
    | Build Trigger File (Development Only)
    |--------------------------------------------------------------------------
    |
    | Alternative to build_command. Path to a file that will be touched when
    | the safelist is updated. A file watcher can monitor this to trigger builds.
    | Only used if build_command is null.
    |
    | Example watcher command:
    | while inotifywait -e modify /path/to/.tailwind-build-trigger; do yarn build; done
    |
    */

    'build_trigger_file' => null,

];
