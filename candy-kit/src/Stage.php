<?php

declare(strict_types=1);

namespace CandyCore\Kit;

/**
 * Render a numbered "stage" line — an arrow / marker glyph + step
 * number + message. Designed for build-script style output where
 * each major action gets its own line:
 *
 *   ▸ 1/5 building dependencies
 *   ▸ 2/5 running tests
 *   ▸ 3/5 packaging release
 *
 * Glyph and divider are configurable. Theme-driven colour: the
 * marker uses `accent`; the count fragment uses `muted`.
 */
final class Stage
{
    public const GLYPH_ARROW   = '▸';
    public const GLYPH_BULLET  = '•';
    public const GLYPH_HASH    = '#';

    /**
     * Render a stage line. Pass `total = 0` to omit the `/total` suffix.
     */
    public static function step(
        int $current,
        int $total,
        string $message,
        ?Theme $theme = null,
        string $glyph = self::GLYPH_ARROW,
    ): string {
        $theme ??= Theme::ansi();
        $count = $total > 0
            ? $current . '/' . $total
            : (string) $current;
        return $theme->accent->render($glyph)
             . ' ' . $theme->muted->render($count)
             . ' ' . $message;
    }

    /**
     * Render a tree-style sub-step that visually nests under the
     * preceding {@see step()} line.
     *
     * @param bool $isLast  use the corner glyph (`└─`) for the
     *                      terminal step in a sequence; defaults
     *                      to the tee (`├─`).
     */
    public static function subStep(
        string $message,
        ?Theme $theme = null,
        bool $isLast = false,
        int $indent = 2,
    ): string {
        $theme ??= Theme::ansi();
        $glyph = $isLast ? '└─' : '├─';
        $pad   = str_repeat(' ', max(0, $indent));
        return $pad . $theme->muted->render($glyph) . ' ' . $message;
    }
}
