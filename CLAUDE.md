# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/claude-code) when working with this repository.

## Project Overview

WP Tailwind Safelist is a WordPress/Acorn package that automatically extracts CSS class names from WordPress content and generates a Tailwind CSS safelist file. This ensures dynamically-added classes (from ACF fields, Gutenberg blocks, etc.) are included in the production CSS build.

## Architecture

```
src/
├── Admin.php                    # Admin bar button & AJAX handler
├── Scanner.php                  # Core scanning logic for extracting classes
├── TailwindSafelist.php         # Main plugin class
├── TailwindSafelistServiceProvider.php  # Acorn service provider
└── Commands/
    ├── ScanCommand.php          # `wp acorn tailwind:scan` command
    └── UpdateDbCommand.php      # `wp acorn tailwind:update-db` command

config/
└── tailwind-safelist.php        # Default configuration
```

## Key Components

### Scanner.php
The core class that extracts CSS classes from:
- Post content (all post types)
- ACF fields (flexible content, repeaters, groups, nested fields)
- ACF Options pages
- Contact Form 7 forms
- Widgets

### Admin.php
Adds a "Re-process Tailwind" button to the WordPress admin bar (development only). When clicked:
1. Scans all content via AJAX
2. Saves classes to database and `tailwind-safelist.txt`

### TailwindSafelistServiceProvider.php
Registers the package with Acorn, including commands and configuration.

## Development Commands

```bash
# Scan all content and update safelist
wp acorn tailwind:scan

# Create/update the database table
wp acorn tailwind:update-db

# Scan with template files included
wp acorn tailwind:scan --include-templates
```

## Configuration

The config file (`config/tailwind-safelist.php`) controls:
- `output_path` - Where to save the safelist file
- `exclude_patterns` - Regex patterns for classes to ignore
- `class_field_patterns` - ACF field name patterns that contain CSS classes

## Output Format

The safelist is saved as a base64-encoded string in `tailwind-safelist.txt`. The theme's `tailwind.config.js` decodes this and adds classes to the safelist array.

## Testing Changes

After making changes:
1. Update the package in a test theme: `composer update copiadigital/wp-tailwind-safelist`
2. Clear Acorn cache: `wp acorn optimize:clear`
3. Test the scan: `wp acorn tailwind:scan`
4. Test the admin bar button in development environment
