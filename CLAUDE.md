# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/claude-code) when working with this repository.

## Project Overview

WP Tailwind Safelist is a WordPress/Acorn package that automatically extracts CSS class names from WordPress content and generates a Tailwind CSS safelist file. This ensures dynamically-added classes (from ACF fields, Gutenberg blocks, etc.) are included in the production CSS build.

**Key Feature:** Fully self-contained with bundled standalone yarn binaries and WP-CLI - no Node.js required on the server.

## Architecture

```
wp-tailwind-safelist/
├── src/
│   ├── Admin.php                    # Admin bar button & AJAX handler (all environments)
│   ├── Scanner.php                  # Core scanning logic for extracting classes
│   ├── TailwindSafelist.php         # Main plugin class
│   ├── TailwindSafelistServiceProvider.php  # Acorn service provider
│   └── Commands/
│       ├── BuildCommand.php         # `wp acorn tailwind:build` command
│       ├── ScanCommand.php          # `wp acorn tailwind:scan` command
│       └── UpdateDbCommand.php      # `wp acorn tailwind:update-db` command
├── config/
│   └── tailwind-safelist.php        # Default configuration
├── yarn-linux                       # Standalone yarn binary for Linux (74MB)
├── yarn-macos                       # Standalone yarn binary for macOS (65MB)
└── wp-cli.phar                      # Bundled WP-CLI (7MB)
```

## Key Components

### Admin.php
Adds a "Re-process Tailwind" button to the WordPress admin bar. When clicked:
1. Scans all content via AJAX
2. Saves classes to database and `tailwind-safelist.txt`
3. Triggers build via WP-CLI (`php wp-cli.phar acorn tailwind:build`)

**Security:**
- Available in ALL environments (dev, staging, production)
- Restricted to administrators only (`manage_options` capability)
- Protected by nonce verification

### Scanner.php
The core class that extracts CSS classes from:
- Post content (all post types)
- ACF fields (flexible content, repeaters, groups, nested fields)
- ACF Options pages
- Contact Form 7 forms
- Widgets

### BuildCommand.php
CLI command that:
1. Detects OS (Linux or macOS)
2. Locates the appropriate standalone yarn binary
3. Executes `yarn build` in the theme directory

### TailwindSafelistServiceProvider.php
Registers the package with Acorn, including commands and configuration.

## CLI Commands

```bash
# Scan all content and trigger build
wp acorn tailwind:scan

# Scan without building
wp acorn tailwind:scan --no-build

# Build only (uses standalone yarn binary)
wp acorn tailwind:build

# Development build
wp acorn tailwind:build --dev

# Create/update the database table
wp acorn tailwind:update-db

# Scan with template files included
wp acorn tailwind:scan --include-templates
```

## How the Build Process Works

```
Admin button click OR `wp acorn tailwind:scan`
    │
    ▼
Scanner extracts classes from all content
    │
    ▼
Classes saved to DB and tailwind-safelist.txt
    │
    ▼
Admin.php calls: php wp-cli.phar acorn tailwind:build --allow-root
    │
    ▼
BuildCommand detects OS via PHP_OS_FAMILY
    │
    ▼
Executes: ./yarn-linux build (or ./yarn-macos build)
    │
    ▼
Tailwind CSS rebuilt with safelist classes
```

## Configuration

The config file (`config/tailwind-safelist.php`) controls:
- `output_path` - Where to save the safelist file
- `exclude_patterns` - Regex patterns for classes to ignore
- `class_field_patterns` - ACF field name patterns that contain CSS classes
- `build_command` - Custom build command (optional, overrides default)

## Output Format

The safelist is saved as a base64-encoded string in `tailwind-safelist.txt`. The theme's `tailwind.config.js` decodes this and adds classes to the safelist array.

## Security Model

| Protection | Implementation |
|------------|----------------|
| Authorization | `current_user_can('manage_options')` - Admins only |
| CSRF | `check_ajax_referer()` - Nonce verification |
| Command injection | `escapeshellarg()` on all shell arguments |

## Testing Changes

After making changes:
1. Update the package in a test theme: `composer update copiadigital/wp-tailwind-safelist`
2. Clear Acorn cache: `wp acorn optimize:clear`
3. Test the scan: `wp acorn tailwind:scan`
4. Test the admin bar button (should work in any environment as admin)
5. Verify build runs successfully

## Environment Support

| Environment | How it works |
|-------------|--------------|
| Docker | WP-CLI runs inside PHP container, executes yarn-linux |
| Local (macOS) | Directly executes yarn-macos |
| Staging/Production | No Node.js needed - uses bundled yarn-linux |

## Permission Handling

The build process automatically fixes file permissions to prevent `EACCES` errors when builds run from different contexts (PHP container, node container, host machine).

### How it works

Both `Admin.php` and `BuildCommand.php` include a `fixBuildPermissions()` method that:

1. Runs **before** the yarn build to ensure existing files can be deleted/overwritten
2. Runs **after** the yarn build to ensure new files can be modified by subsequent builds

```php
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
```

### Why world-writable is safe here

The `public/build` directory only contains:
- Compiled CSS files
- Compiled JS files
- Source maps
- Build manifest

These are all generated assets with no sensitive data, so world-writable permissions are acceptable.

## Known Issues & Fixes

### WP-CLI Path Escaping (Fixed in v1.1.0)

**Issue:** When `getWpCliPath()` returned `php /path/to/wp-cli.phar`, the entire string was wrapped in `escapeshellarg()`, causing the shell to look for a command literally named `"php /path/to/wp-cli.phar"`.

**Fix:** The phar path is now escaped separately:
```php
// Before (broken):
return 'php ' . $wpCliPhar;
// ... then later:
escapeshellarg($wpCliPath) // Wraps entire string = broken

// After (fixed):
return 'php ' . escapeshellarg($wpCliPhar);
// ... then later:
$wpCliPath // Already properly escaped
```

### Permission Errors in Docker (Fixed in v1.1.0)

**Issue:** When the PHP container ran `yarn build`, files were created as `root`. Subsequent builds from different users/containers failed with `EACCES: permission denied`.

**Fix:** Added `fixBuildPermissions()` that runs `chmod -R a+w` on the build directory before and after builds.
