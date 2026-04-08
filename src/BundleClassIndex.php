<?php

namespace CopiaDigital\TailwindSafelist;

/**
 * Builds a set of class names that are already present in the theme's
 * static codebase — either:
 *   1. The compiled CSS bundle (`public/build/*.css`, via the Vite manifest)
 *   2. The template/source files (Blade views, app PHP, JS, etc.)
 *
 * Source-file scanning is the source of truth: it works in `yarn dev`
 * (where Vite serves CSS from memory and the on-disk bundle is stale)
 * and in `yarn build` alike. The compiled-bundle scan is kept as a
 * useful supplement — it picks up safelist patterns and dependency
 * classes that aren't literally written in templates.
 *
 * The CssGenerator uses the merged set to skip classes that are
 * already in the main build, so `dynamic.css` only ever contains the
 * *additional* classes that came from CMS content.
 */
class BundleClassIndex
{
    /**
     * Walk the given directories and extract every class name found in
     * source files (matching the supplied extensions).
     *
     * @param array<int,string> $dirs       Absolute directory paths.
     * @param array<int,string> $extensions File extensions to scan.
     * @return array<string,bool>
     */
    public static function buildFromTemplates(array $dirs, array $extensions): array
    {
        $classes = [];
        $extSet = array_flip($extensions);

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                $ext = $file->getExtension();
                // Normalise blade.php → php; we already match php below.
                if (!isset($extSet[$ext])) {
                    continue;
                }
                self::extractFromSource((string) $file->getPathname(), $classes);
            }
        }

        return $classes;
    }

    /**
     * Extract class names from a single source file. Looks for both
     * `class="…"` / `class='…'` attributes and Blade's `@class([…])`
     * directive.
     */
    private static function extractFromSource(string $path, array &$classes): void
    {
        $content = (string) file_get_contents($path);
        if ($content === '') {
            return;
        }

        // class="..." / class='...'
        if (preg_match_all('/class=["\']([^"\']+)["\']/', $content, $m)) {
            foreach ($m[1] as $str) {
                foreach (preg_split('/\s+/', $str) as $cls) {
                    $cls = trim($cls);
                    if ($cls !== '') {
                        $classes[$cls] = true;
                    }
                }
            }
        }

        // @class([ 'foo' => $bar, 'baz' ])
        if (preg_match_all('/@class\(\[([^\]]+)\]\)/', $content, $m)) {
            foreach ($m[1] as $body) {
                if (preg_match_all('/["\']([^"\']+)["\']/', $body, $q)) {
                    foreach ($q[1] as $str) {
                        foreach (preg_split('/\s+/', $str) as $cls) {
                            $cls = trim($cls);
                            if ($cls !== '') {
                                $classes[$cls] = true;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Extract every class name found in the bundle's CSS files.
     *
     * @return array<string,bool> Map keyed by class name for O(1) lookup.
     */
    public static function build(string $manifestPath): array
    {
        if (!is_file($manifestPath)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return [];
        }

        $assetsDir = dirname($manifestPath);
        $classes = [];

        foreach ($manifest as $entry) {
            if (!is_array($entry) || empty($entry['file'])) {
                continue;
            }
            $file = $entry['file'];
            if (!str_ends_with($file, '.css')) {
                continue;
            }

            $cssPath = $assetsDir . '/' . $file;
            if (!is_file($cssPath)) {
                continue;
            }

            self::extractInto($cssPath, $classes);
        }

        return $classes;
    }

    /**
     * Parse a CSS file and add every class selector it finds (unescaped)
     * into the supplied set.
     */
    private static function extractInto(string $cssPath, array &$classes): void
    {
        $css = (string) file_get_contents($cssPath);

        // Strip CSS comments — keeps the regex honest.
        $css = preg_replace('!/\*.*?\*/!s', '', $css);

        // Match class selectors. Tailwind escapes specials with `\`, e.g.
        // `.md\:tw-flex`, `.hover\:tw-bg-primary\/90:hover`. The pattern
        // accepts either an escape sequence (`\` + any char) or a normal
        // class character (`[\w-]`), and stops at the first unescaped
        // delimiter (`:`, `{`, `,`, space, `.`, `>`, `[`, `+`, `~`).
        if (!preg_match_all('/\.((?:\\\\.|[\w-])+)/', $css, $matches)) {
            return;
        }

        foreach ($matches[1] as $escaped) {
            // Unescape: drop the leading backslash from any `\X` pair.
            $name = preg_replace('/\\\\(.)/', '$1', $escaped);
            if ($name !== '') {
                $classes[$name] = true;
            }
        }
    }
}
