<?php

return [

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

];
