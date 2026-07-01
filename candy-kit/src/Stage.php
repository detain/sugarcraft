<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

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
     * Counts are clamped to the range `[0, total]` (i.e. negative values
     * become `0`, and `current > total` is capped at `total`).
     */
    public static function step(
        int $current,
        int $total,
        string $message,
        ?Theme $theme = null,
        string $glyph = self::GLYPH_ARROW,
    ): string {
        $theme  ??= Theme::ansi();
        $current = max(0, $current);
        $total   = max(0, $total);
        if ($total > 0) {
            $current = min($current, $total);
        }
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

    /**
     * Render a sub-step with an inline progress bar.
     *
     * Output: `  ├─ message ████░░░░ 40%`
     *
     * The bar is drawn with 10 segments (█ for filled, ░ for empty).
     * Both $current and $total are clamped: negatives become 0, and
     * values exceeding $total are capped at $total.
     *
     * @param string $message  descriptive label for the step
     * @param int    $current  completed units
     * @param int    $total    total units; pass 0 to show a indeterminate spinner
     * @param bool   $isLast   use the corner glyph (`└─`) for the terminal step
     * @param int    $indent   left margin in cells
     */
    public static function subStepWithProgress(
        string $message,
        int $current,
        int $total,
        ?Theme $theme = null,
        bool $isLast = false,
        int $indent = 2,
    ): string {
        $theme   ??= Theme::ansi();
        $current  = max(0, $current);
        $total    = max(0, $total);
        if ($total > 0) {
            $current = min($current, $total);
        }

        $bar = '';
        $pct = '';
        if ($total > 0) {
            $filled = (int) round(10 * $current / $total);
            $bar = $theme->accent->render(str_repeat('█', $filled))
               . $theme->muted->render(str_repeat('░', 10 - $filled));
            $pct = $theme->muted->render(sprintf(' %3d%%', (int) round(100 * $current / $total)));
        } else {
            // Indeterminate: cycle through a rotating spinner glyph
            static $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
            $frame = $spinnerFrames[(int) (microtime(false) * 10) % 10];
            $bar = $theme->accent->render($frame);
        }

        $glyph = $isLast ? '└─' : '├─';
        $pad   = str_repeat(' ', max(0, $indent));

        return $pad . $theme->muted->render($glyph) . ' '
             . $message . ' '
             . $bar . $pct;
    }
}
