# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-01-28

### Fixed

- **WP-CLI path escaping bug** - Fixed issue where `escapeshellarg()` was incorrectly wrapping the entire `php /path/to/wp-cli.phar` string, causing shell execution to fail with "command not found". The phar path is now escaped separately from the `php` command.

- **Permission errors in Docker/multi-user environments** - Fixed `EACCES: permission denied` errors that occurred when builds ran from different contexts (PHP container, node container, host machine). The package now automatically sets world-writable permissions on the `public/build` directory before and after builds.

### Added

- **Cross-platform permission handling** - Added `fixBuildPermissions()` method to both `Admin.php` and `BuildCommand.php` that ensures build artifacts can be overwritten regardless of which user runs the build.

- **Universal server support** - The permission fix works on all server configurations:
  - Debian/Ubuntu (`www-data`)
  - RHEL/CentOS (`apache`)
  - Amazon Linux (`ec2-user`)
  - Nginx (`nginx`)
  - macOS (`_www`)
  - Docker (`root`)

### Changed

- `getWpCliPath()` in `Admin.php` now returns a pre-escaped command string, with the phar path properly escaped using `escapeshellarg()`.

- `executeYarnBuild()` in `Admin.php` no longer calls `escapeshellarg()` on the WP-CLI path since it's already escaped.

## [1.0.0] - 2026-01-27

### Added

- Initial release
- Admin bar "Re-process Tailwind" button for all environments
- CLI commands: `tailwind:scan`, `tailwind:build`, `tailwind:update-db`
- Bundled standalone yarn binaries (Linux and macOS)
- Bundled WP-CLI phar for triggering builds
- Scanner for extracting CSS classes from:
  - All post types
  - ACF fields (flexible content, repeaters, groups, nested fields)
  - ACF Options pages
  - Contact Form 7 forms
  - Widgets
  - Blade templates (optional)
- Database storage for class tracking
- Base64-encoded safelist file output
- Configurable exclude patterns and class field patterns
