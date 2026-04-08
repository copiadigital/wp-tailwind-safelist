# WP Tailwind Safelist

Automatically extracts CSS class names from WordPress content (Gutenberg blocks, ACF fields, CF7 forms, widgets) and **generates a `dynamic.css` file directly using a pure-PHP Tailwind compiler** — no Node, no yarn, no shell-out.

Built for [Sage](https://roots.io/sage) themes using [Acorn](https://roots.io/acorn).

## Why

The previous version of this package bundled standalone yarn binaries so it could trigger `yarn build` on servers without Node. That broke on staging and CI for glibc/Ubuntu reasons. This version replaces all of that with `CssGenerator.php`, which parses Tailwind utility class names directly and emits CSS.

## Requirements

- PHP ≥ 8.1
- [Acorn](https://roots.io/acorn) ≥ 4.0
- [Sage](https://roots.io/sage) ≥ 10.0

No Node.js required.

## Installation

```bash
composer require copiadigital/wp-tailwind-safelist
```

Register the service provider in your theme's `composer.json`:

```json
{
  "extra": {
    "acorn": {
      "providers": [
        "App\\Providers\\ThemeServiceProvider",
        "CopiaDigital\\TailwindSafelist\\TailwindSafelistServiceProvider"
      ]
    }
  }
}
```

Then:

```bash
composer dump-autoload
wp acorn optimize:clear
wp acorn tailwind:update-db
```

## Setup

### 1. Mirror your theme's design tokens in the package config

Publish the config:

```bash
wp acorn vendor:publish --provider="CopiaDigital\\TailwindSafelist\\TailwindSafelistServiceProvider"
```

Then edit `config/tailwind-safelist.php` and fill in the `css` block — `prefix`, `screens`, `spacing`, `colors`, `font_sizes`, `radii` — to match your `tailwind.config.js`. Whenever the theme adds a new spacing/color token, mirror it here so the generated CSS uses the same CSS variables.

### 2. Enqueue `dynamic.css` from your theme

```php
add_action('wp_enqueue_scripts', function () {
    $path = get_stylesheet_directory() . '/public/dynamic.css';
    if (file_exists($path)) {
        wp_enqueue_style(
            'tailwind-dynamic',
            get_stylesheet_directory_uri() . '/public/dynamic.css',
            ['sage/app.css'], // load AFTER your main bundle
            (string) filemtime($path)
        );
    }
});
```

### 3. Run the initial scan

```bash
wp acorn tailwind:scan
```

This produces `public/dynamic.css` containing CSS for every Tailwind utility found in your content.

## Usage

### Admin Bar Button

A **"Re-process Tailwind"** button appears in the WP admin bar for administrators. Clicking it scans all content and regenerates `public/dynamic.css` in-process. Works in any environment (no shell access required).

### CLI Commands

```bash
wp acorn tailwind:scan              # Scan content + regenerate dynamic.css
wp acorn tailwind:scan --no-build   # Scan only
wp acorn tailwind:build             # Regenerate dynamic.css from current safelist (no scan)
wp acorn tailwind:update-db
```

## What the generator supports

Out of the box, `CssGenerator` handles the utilities most commonly authored from the CMS:

- **Layout:** `flex`, `inline-flex`, `grid`, `inline-grid`, `block`, `inline-block`, `hidden`, `table*`, `contents`
- **Position:** `static`, `relative`, `absolute`, `fixed`, `sticky`, `top/right/bottom/left/inset[-x/-y]`
- **Spacing:** `m`, `mt/mr/mb/ml/mx/my`, `p`, `pt/pr/pb/pl/px/py` — with negatives, fractions, arbitrary `[Xpx]`, theme tokens
- **Sizing:** `w`, `h`, `min-w`, `max-w`, `min-h`, `max-h`, `size-*`
- **Flex/Grid:** `flex-row/col[-reverse]`, `flex-wrap`, `flex-1/auto/initial/none`, `grow`, `shrink`, `items-*`, `justify-*`, `self-*`, `content-*`, `gap[-x|-y]`, `grid-cols-N`, `grid-cols-[…]`, `col-span-N`, `row-span-N`, `col-start/end-N`, `order-N`
- **Typography:** `text-{size|color|[arb]}`, `text-left/center/right/justify`, `font-{thin..black|N}`, `italic`, `not-italic`, `leading-*`, `tracking-*`, `underline`, `no-underline`, `line-through`, `uppercase`, `lowercase`, `capitalize`, `truncate`, `whitespace-*`
- **Backgrounds & Borders:** `bg-{color|[arb]}`, `border`, `border-{0..N}`, `border-{t|r|b|l}-N`, `border-{color}`, `border-solid/dashed/dotted/none`, `rounded[-corner][-size]`, `rounded-full`, `shadow`
- **Effects:** `opacity-N`, `transition`, `duration-N`, `delay-N`, `transition-{colors|opacity|transform|all|none}`, `translate-{x|y}-*`, `rotate-*`, `scale-N`
- **States:** `hover:`, `focus:`, `focus-within:`, `focus-visible:`, `active:`, `visited:`, `disabled:`, `checked:`, `first:`, `last:`, `odd:`, `even:`, `file:`, `placeholder:`, `before:`, `after:`
- **Responsive:** `sm:`, `md:`, `lg:`, `xl:`, `2xl:` (configurable)
- **Misc:** `sr-only`, `not-sr-only`, `outline-none`, `cursor-*`, `overflow-*`, `visible`, `invisible`, `aspect-*`, `z-*`

Anything not recognised is silently skipped — those classes should already be in your main Tailwind build that ships with the theme.

### Arbitrary values

Tailwind's `[…]` syntax is supported everywhere a value is expected:

```
tw-mb-[1rem]            → margin-bottom: 1rem
-tw-mt-[20px]           → margin-top: -20px
tw-bg-[#ff0000]         → background-color: #ff0000
tw-text-[14px]          → font-size: 14px
tw-text-[#333]          → color: #333
tw-grid-cols-[1fr_2fr]  → grid-template-columns: 1fr 2fr
```

(Use `_` for spaces inside `[…]`, per Tailwind convention.)

## Configuration

```php
// config/tailwind-safelist.php

return [
    'output_path'     => get_stylesheet_directory() . '/tailwind-safelist.txt',
    'css_output_path' => get_stylesheet_directory() . '/public/dynamic.css',

    'exclude_patterns'     => [ /* ... */ ],
    'class_field_patterns' => [ /* ... */ ],

    'css' => [
        'prefix'  => 'tw-',
        'screens' => ['sm' => '576px', 'md' => '768px', /* ... */],
        'spacing' => ['10' => 'var(--spacing-10)', /* ... */],
        'colors'  => ['primary' => 'var(--color-primary)', /* ... */],
        'font_sizes' => ['sm' => 'var(--font-size-sm)', /* ... */],
        'radii'      => ['md' => 'var(--radius-md)', /* ... */],
    ],
];
```

## Security

- Capability check: `current_user_can('manage_options')`
- Nonce: `check_ajax_referer()`
- No `exec()` / shell-out anywhere — command injection is not a concern.

## License

MIT — see [LICENSE](LICENSE).
