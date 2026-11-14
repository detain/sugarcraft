<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Cli\Internal\Clip;
use SugarCraft\Cli\Internal\SafeText;
use SugarCraft\Cli\Internal\WidthGuard;
use SugarCraft\Cli\Internal\WidthProbe;
use SugarCraft\Core\Util\Width;

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

    /** Spinner frames for the indeterminate progress path, in cycle order. */
    public const SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /**
     * Cell count of the determinate progress bar (filled + empty segments).
     * Its own number from {@see SPINNER_FRAMES} (finding 029): the two tens
     * rhyme, but nothing couples them — retuning bar granularity must not
     * silently retime the spinner, or vice versa.
     */
    public const BAR_SEGMENTS = 10;

    /**
     * Render a stage line. Pass `total = 0` to omit the `/total` suffix.
     * Counts are clamped to the range `[0, total]` (i.e. negative values
     * become `0`, and `current > total` is capped at `total`).
     *
     * Message-first, like {@see subStep()} and {@see subStepWithProgress()},
     * so the whole step family shares one argument order (finding 008).
     *
     * @param string $message  the step text, sanitized and clipped to fit.
     * @param int $current     1-based step counter.
     * @param int $total       step total; 0 omits the `/total` suffix.
     * @param string $glyph  leading marker, sanitized like the message (it is
     *                       caller-supplied display text) but never clipped —
     *                       a glyph wider than the budget overflows with the
     *                       message dropped first.
     * @param int|AutoWidth|null $width  cell budget for the whole line; the
     *                       message is cut to fit with the house `…` ellipsis.
     *                       Omitted (or `AutoWidth::Auto`) resolves to the
     *                       terminal width, falling back to 80 cells; `null`
     *                       disables the clamp (see {@see WidthProbe}).
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function step(
        string $message,
        int $current,
        int $total,
        ?Theme $theme = null,
        string $glyph = self::GLYPH_ARROW,
        int|AutoWidth|null $width = AutoWidth::Auto,
    ): string {
        $theme   ??= Theme::detect();
        $current  = max(0, $current);
        $total    = max(0, $total);
        if ($total > 0) {
            $current = min($current, $total);
        }
        $count = $total > 0
            ? $current . '/' . $total
            : (string) $current;
        $prefix = $theme->accent->render(SafeText::line($glyph))
              . ' ' . $theme->muted->render($count) . ' ';
        return self::clipped($prefix, SafeText::line($message), $width, 'Stage::step');
    }

    /**
     * Render a tree-style sub-step that visually nests under the
     * preceding {@see step()} line.
     *
     * @param bool $isLast  use the corner glyph (`└─`) for the
     *                      terminal step in a sequence; defaults
     *                      to the tee (`├─`).
     * @param int|AutoWidth|null $width  cell budget for the whole line; the
     *                      message is cut to fit — see {@see step()}.
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function subStep(
        string $message,
        ?Theme $theme = null,
        bool $isLast = false,
        int $indent = 2,
        int|AutoWidth|null $width = AutoWidth::Auto,
    ): string {
        $theme ??= Theme::detect();
        $glyph = $isLast ? '└─' : '├─';
        $pad   = str_repeat(' ', max(0, $indent));
        return self::clipped($pad . $theme->muted->render($glyph) . ' ', SafeText::line($message), $width, 'Stage::subStep');
    }

    /**
     * Render a sub-step with an inline progress bar.
     *
     * Output: `  ├─ message ████░░░░ 40%`
     *
     * The bar is drawn with `$segments` cells (█ for filled, ░ for empty,
     * default `Stage::BAR_SEGMENTS`). Both $current and $total are clamped:
     * negatives become 0, and values exceeding $total are capped at $total.
     * When the width cannot fit prefix + bar + one message cell, the bar
     * suffix is dropped and the line degrades to the {@see subStep()} shape
     * so the message keeps its budget — the line never exceeds the cap and
     * never prints furniture over an empty message (finding 013).
     *
     * @param string $message  descriptive label for the step
     * @param int    $current  completed units
     * @param int    $total    total units; pass 0 to show an indeterminate spinner
     * @param bool   $isLast   use the corner glyph (`└─`) for the terminal step
     * @param int    $indent   left margin in cells
     * @param int|AutoWidth|null $width  cell budget for the whole line; the
     *                       message is cut to fit between the tree prefix and
     *                       the bar — see {@see step()}.
     * @param int|null $frame  spinner frame index for the indeterminate path
     *                       (`total = 0`), normalized modulo the frame count
     *                       (negative indices cycle backwards). `null` — the
     *                       default — derives the frame from the wall clock so
     *                       repeated renders animate; pass an explicit index
     *                       whenever the output byte must be deterministic
     *                       (golden files, tests, recorded sessions). Ignored
     *                       by the determinate bar (`total > 0`).
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function subStepWithProgress(
        string $message,
        int $current,
        int $total,
        ?Theme $theme = null,
        bool $isLast = false,
        int $indent = 2,
        int|AutoWidth|null $width = AutoWidth::Auto,
        ?int $frame = null,
        int $segments = self::BAR_SEGMENTS,
    ): string {
        $theme    ??= Theme::detect();
        $current   = max(0, $current);
        $total     = max(0, $total);
        if ($segments < 1) {
            throw new \InvalidArgumentException('Stage::subStepWithProgress(): $segments must be at least 1.');
        }
        if ($total > 0) {
            $current = min($current, $total);
        }

        $bar = '';
        $pct = '';
        if ($total > 0) {
            $filled = (int) round($segments * $current / $total);
            $bar = $theme->accent->render(str_repeat('█', $filled))
               . $theme->muted->render(str_repeat('░', $segments - $filled));
            $pct = $theme->muted->render(sprintf(' %3d%%', (int) round(100 * $current / $total)));
        } else {
            // Indeterminate: cycle through a rotating spinner glyph. With no
            // explicit frame, microtime(true) — the float seconds — supplies
            // one: `* 10` samples the clock at ten steps a second, then the
            // modulo is the FRAME count (finding 029: bar cells and spinner
            // frames are separate numbers that merely rhyme). (microtime(false)
            // would return the string "0.xxxxxx sssss" and raise a non-numeric
            // warning in the arithmetic — fatal under failOnWarning.) Callers
            // who need the byte deterministic pass $frame.
            $frames = count(self::SPINNER_FRAMES);
            $index = $frame === null
                ? (int) (microtime(true) * 10) % $frames
                : (($frame % $frames) + $frames) % $frames;
            $bar = $theme->accent->render(self::SPINNER_FRAMES[$index]);
        }

        $glyph = $isLast ? '└─' : '├─';
        $pad   = str_repeat(' ', max(0, $indent));

        $prefix = $pad . $theme->muted->render($glyph) . ' ';
        $suffix = ' ' . $bar . $pct;

        // Finding 013: if the budget cannot hold prefix + bar + one message
        // cell, the bar is dropped entirely and the line degrades to the
        // subStep shape — the message keeps its budget and the line never
        // exceeds the cap, instead of printing furniture over an empty label.
        $resolved = WidthProbe::resolve($width);
        if ($resolved !== null) {
            // Finding 030: with a cap, the suffix is measured once here and
            // handed to clipped(); without a cap clipped() ignores the
            // reservation, so nothing is measured at all (the old code paid
            // a full Width::string($suffix) scan that was always discarded).
            $suffixCells = Width::string($suffix);
            if ($resolved < Width::string($prefix) + $suffixCells + 1) {
                return self::clipped($prefix, SafeText::line($message), $width, 'Stage::subStepWithProgress');
            }
            return self::clipped($prefix, SafeText::line($message), $width, 'Stage::subStepWithProgress', $suffixCells) . $suffix;
        }

        return self::clipped($prefix, SafeText::line($message), $width, 'Stage::subStepWithProgress') . $suffix;
    }

    /**
     * Lay `$prefix` + message (+ optional `$suffix` cells reserved) into the
     * width budget: the message alone is cut with the house ellipsis, the
     * tree furniture never is. Null width renders the message in full; a
     * budget that cannot fit even one message cell drops it entirely.
     *
     * @throws \InvalidArgumentException when the resolved width is less than 1
     */
    private static function clipped(
        string $prefix,
        string $message,
        int|AutoWidth|null $width,
        string $presenter,
        int $suffixCells = 0,
    ): string {
        $resolved = WidthProbe::resolve($width);
        WidthGuard::assert($presenter, $resolved, 'render the full message');
        if ($resolved !== null) {
            $message = Clip::text($message, $resolved - Width::string($prefix) - $suffixCells);
        }
        return $prefix . $message;
    }
}
