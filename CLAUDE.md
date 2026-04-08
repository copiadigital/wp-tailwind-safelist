# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/claude-code) when working with this repository.

## Project Overview

WP Tailwind Safelist is a WordPress/Acorn package that automatically extracts CSS class names from WordPress content (Gutenberg, ACF, CF7, widgets) and generates a `dynamic.css` file directly from them using a **PHP-based Tailwind CSS generator**.

**No Node, no yarn, no shell-out.** The package used to bundle standalone yarn binaries to invoke a real Tailwind build on servers without Node. That approach broke on staging/CI (Ubuntu/glibc mismatches), so it has been replaced with a pure-PHP utility-class compiler.

## Architecture

```
wp-tailwind-safelist/
├── src/
│   ├── Admin.php                    # Admin bar button + AJAX handler
│   ├── Scanner.php                  # Extracts classes from CMS content
│   ├── CssGenerator.php             # PHP Tailwind → CSS compiler (new)
│   ├── TailwindSafelist.php         # save_post hook + path helpers
│   ├── TailwindSafelistServiceProvider.php
│   └── Commands/
│       ├── BuildCommand.php         # `wp acorn tailwind:build` — runs the generator
│       ├── ScanCommand.php          # `wp acorn tailwind:scan`
│       └── UpdateDbCommand.php      # `wp acorn tailwind:update-db`
└── config/
    └── tailwind-safelist.php        # Config + CSS generator theme tokens
```

## Key Components

### CssGenerator.php
Pure PHP Tailwind utility-class compiler. Handles:
- Class prefix (`tw-` etc.)
- Negative values (`-mt-20`, `-mt-[20px]`)
- Arbitrary values (`mb-[1rem]`, `bg-[#ff0000]`, `text-[14px]`)
- Fractions (`w-1/2`)
- Alpha syntax (`bg-primary/50` → `color-mix(...)`)
- Responsive variants (`md:`, `lg:` → `@media`)
- Pseudo variants (`hover:`, `focus:`, `disabled:`, `file:`, `before:`, …)
- Spacing/colors/font-sizes/radii driven by config (mirrors `tailwind.config.js`)

Anything it doesn't recognise is silently skipped — those classes should already be in the main Tailwind build that ships with the theme.

### Scanner.php
Unchanged scanning logic. After scanning, `saveClasses()` writes:
1. `tailwind-safelist.txt` (base64, legacy — kept for local `yarn dev` workflows)
2. `public/dynamic.css` (the new generated CSS, enqueued in production)

### Admin.php
The "Re-process Tailwind" admin-bar button now runs everything in-process:
1. Scan content
2. Persist classes to DB + safelist file
3. Generate `dynamic.css` via `CssGenerator`

No more `exec()`, no more `wp-cli.phar`, no more yarn binary detection.

### BuildCommand.php
`wp acorn tailwind:build` loads the latest classes (DB → falls back to safelist file) and writes `dynamic.css` via `CssGenerator`. Useful for CLI/CI re-builds without re-scanning.

## CLI Commands

```bash
wp acorn tailwind:scan              # Scan content + write dynamic.css
wp acorn tailwind:scan --no-build   # Scan only, skip CSS generation step
wp acorn tailwind:build             # Re-generate dynamic.css from current safelist
wp acorn tailwind:update-db
```

## How the Build Process Works

```
Admin button click  OR  `wp acorn tailwind:scan`
    │
    ▼
Scanner extracts classes from content (posts, ACF, CF7, widgets)
    │
    ▼
Classes saved to DB + tailwind-safelist.txt
    │
    ▼
CssGenerator compiles classes → public/dynamic.css
    │
    ▼
Theme enqueues dynamic.css alongside the main built CSS
```

## Configuration

`config/tailwind-safelist.php` exposes a `css` block that mirrors the theme's `tailwind.config.js` — `prefix`, `screens`, `spacing`, `colors`, `font_sizes`, `radii`. Whenever the theme adds a new spacing/color token, mirror it here so server-generated styles match.

## Output

The plugin writes:
- `public/dynamic.css` — generated CSS, enqueue this on staging/production
- `tailwind-safelist.txt` — base64 class list, still consumed by local `yarn dev` via `tailwind.config.js`

## Security Model

| Protection | Implementation |
|------------|----------------|
| Authorization | `current_user_can('manage_options')` |
| CSRF | `check_ajax_referer()` |

Note: there is no longer any `exec()` / shell-out, so command injection is no longer a concern.
