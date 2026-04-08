<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto Scan on Save
    |--------------------------------------------------------------------------
    |
    | When enabled, automatically scans content when posts are saved.
    | Disabled by default — use the admin bar button or CLI command instead.
    |
    */

    'auto_scan_on_save' => false,

    /*
    |--------------------------------------------------------------------------
    | CSS Output Path
    |--------------------------------------------------------------------------
    |
    | The generated dynamic CSS file. The plugin auto-enqueues this on the
    | frontend when it exists.
    |
    */

    'css_output_path' => get_stylesheet_directory() . '/public/dynamic.css',

    /*
    |--------------------------------------------------------------------------
    | Tailwind Config Path
    |--------------------------------------------------------------------------
    |
    | Path to the theme's tailwind.config.js. The plugin parses this file
    | for design tokens (prefix, screens, spacing, colors, fontSize,
    | borderRadius, width) so that adding a token in tailwind.config.js
    | is enough — no need to mirror it here.
    |
    */

    'tailwind_config_path' => get_stylesheet_directory() . '/tailwind.config.js',

    /*
    |--------------------------------------------------------------------------
    | Bundle Dedup
    |--------------------------------------------------------------------------
    |
    | When `dedupe_against_bundle` is true, the plugin reads the Vite
    | manifest, extracts every class selector already present in the
    | compiled CSS bundle, and skips those classes when generating
    | dynamic.css. This keeps dynamic.css limited to the classes that
    | came from CMS content and weren't already in the main build.
    |
    */

    'dedupe_against_bundle' => true,
    'manifest_path' => get_stylesheet_directory() . '/public/build/manifest.json',

    /*
    | Source directories scanned for class names. Acts as the source of
    | truth for "what's already in the main build" — works in both
    | `yarn dev` (where the on-disk bundle is stale) and `yarn build`.
    */
    'template_paths' => [
        get_stylesheet_directory() . '/resources',
        get_stylesheet_directory() . '/app',
        get_stylesheet_directory() . '/modules',
    ],
    'template_extensions' => ['php', 'js', 'html', 'scss', 'css'],

    /*
    |--------------------------------------------------------------------------
    | Exclude Patterns
    |--------------------------------------------------------------------------
    */

    'exclude_patterns' => [
        '/^wp-/',
        '/^wpcf7/',
        '/^acf-/',
        '/^block-/',
        '/^is-/',
        '/^has-/',
        '/^alignwide$/',
        '/^alignfull$/',
        '/^alignleft$/',
        '/^alignright$/',
        '/^aligncenter$/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Class Field Patterns (ACF)
    |--------------------------------------------------------------------------
    */

    'class_field_patterns' => [
        'class', 'classes', 'className',
        'css_class', 'css_classes', 'custom_class',
        'additional_class', 'wrapper_class', 'container_class',
        'section_class', 'style', 'styles', 'tailwind',
    ],

    /*
    |--------------------------------------------------------------------------
    | CSS Generator Overrides
    |--------------------------------------------------------------------------
    |
    | Tokens defined here override / supplement what is parsed from
    | tailwind.config.js. Leave empty unless you need to add tokens that
    | aren't expressible in tailwind.config.js (e.g. extra pseudo
    | variants) or you want to override individual values.
    |
    | Shape:
    |   'css' => [
    |       'prefix'      => 'tw-',
    |       'screens'     => [...],
    |       'spacing'     => [...],
    |       'colors'      => [...],
    |       'font_sizes'  => [...],
    |       'radii'       => [...],
    |   ],
    |
    */

    'css' => [],

];
