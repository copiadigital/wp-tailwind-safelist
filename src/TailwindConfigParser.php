<?php

namespace CopiaDigital\TailwindSafelist;

/**
 * Tiny PHP parser for `tailwind.config.js`.
 *
 * Extracts the subset of tokens that the PHP CssGenerator cares about
 * (prefix, screens, spacing, colors, fontSize, borderRadius, width).
 * Only handles simple `'key': 'value'` string maps — anything more
 * exotic (functions, references, nested objects) is silently ignored.
 *
 * The intent is "single source of truth": authors edit
 * tailwind.config.js as usual and the plugin picks up new tokens
 * automatically without a Node round-trip.
 */
class TailwindConfigParser
{
    /**
     * Parse a tailwind.config.js file into the CssGenerator config shape.
     *
     * @return array{
     *     prefix?: string,
     *     screens?: array<string,string>,
     *     spacing?: array<string,string>,
     *     colors?: array<string,string>,
     *     font_sizes?: array<string,string>,
     *     radii?: array<string,string>,
     * }
     */
    public static function parse(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $src = (string) file_get_contents($path);
        $src = self::stripComments($src);

        $out = [];

        if (preg_match('/\bprefix\s*:\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) {
            $out['prefix'] = $m[1];
        }

        $screens = self::extractStringMap($src, 'screens');
        if ($screens) {
            $out['screens'] = $screens;
        }

        // spacing lives at theme.spacing
        $spacing = self::extractStringMap($src, 'spacing');

        // extend block: colors, fontSize, borderRadius, width
        $extendBody = self::extractBlock($src, 'extend');
        if ($extendBody !== null) {
            $colors = self::extractStringMap($extendBody, 'colors');
            $fontSizes = self::extractStringMap($extendBody, 'fontSize');
            $radii = self::extractStringMap($extendBody, 'borderRadius');
            $widths = self::extractStringMap($extendBody, 'width');

            if ($colors) $out['colors'] = $colors;
            if ($fontSizes) $out['font_sizes'] = $fontSizes;
            if ($radii) $out['radii'] = $radii;

            // Tailwind's `theme.extend.width` adds named width tokens
            // (e.g. col-1..col-12). The CssGenerator resolves `w-*`
            // through its spacing scale, so merge widths into spacing.
            // Use array_replace (NOT array_merge) — array_merge would
            // reindex numeric string keys like '20' to 0,1,2,…
            if ($widths) {
                $spacing = array_replace($spacing, $widths);
            }
        }

        if ($spacing) {
            $out['spacing'] = $spacing;
        }

        return $out;
    }

    /**
     * Strip JS line + block comments. Naive but adequate for config files.
     */
    private static function stripComments(string $src): string
    {
        $src = preg_replace('!/\*.*?\*/!s', '', $src);
        $src = preg_replace('!(^|[^:])//[^\n]*!', '$1', $src);
        return $src;
    }

    /**
     * Extract the body of `key: { ... }` (without the surrounding braces),
     * with proper brace matching. Returns null if not found.
     */
    private static function extractBlock(string $src, string $key): ?string
    {
        $pattern = '/\b' . preg_quote($key, '/') . '\s*:\s*\{/';
        if (!preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($src);
        $i = $start;

        while ($i < $len && $depth > 0) {
            $c = $src[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start);
                }
            }
            $i++;
        }

        return null;
    }

    /**
     * Extract a `key: { 'a': 'x', 'b': 'y' }` block as a flat string map.
     * Only captures string-string entries; nested objects/arrays are skipped.
     */
    private static function extractStringMap(string $src, string $key): array
    {
        $body = self::extractBlock($src, $key);
        if ($body === null) {
            return [];
        }

        // Strip nested object/array bodies so we don't pick up their keys.
        $clean = self::stripNested($body);

        $out = [];
        // Matches: 'key': 'value' | "key": "value" | key: 'value'
        preg_match_all(
            '/[\'"]?([A-Za-z_0-9][\w\-\/]*)[\'"]?\s*:\s*[\'"]([^\'"]*)[\'"]/',
            $clean,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $out[$m[1]] = $m[2];
        }

        return $out;
    }

    /**
     * Remove the contents of any nested `{...}` or `[...]` so that
     * extractStringMap only sees the top-level entries of the current block.
     */
    private static function stripNested(string $body): string
    {
        $out = '';
        $depth = 0;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $c = $body[$i];
            if ($c === '{' || $c === '[') {
                $depth++;
                continue;
            }
            if ($c === '}' || $c === ']') {
                $depth--;
                continue;
            }
            if ($depth === 0) {
                $out .= $c;
            }
        }
        return $out;
    }
}
