<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

use SugarCraft\Style\Style;

/**
 * The shared SGR palette every readline prompt paints with (finding 036).
 *
 * Before this seam the same literals ('1;36', '7', '31', '90', '1;32')
 * were retyped as property defaults across five carriers, so one
 * palette tweak meant five edits and a mis-typed copy failed silently
 * (the copy IS the color). One constant per visible slot, referenced by
 * every prompt, makes the palette a single reviewable surface.
 *
 * Prompt style slots hold raw SGR code strings (they feed Ansi's
 * `ESC[n m` wrapper directly). The `with*Style()` setters accept
 * `Style|string` so hosts can compose colors with sugar-style; a
 * content-level Style collapses to its code string via {@see code()}.
 */
final class Styles
{
    /** Prompt label: bold cyan. */
    public const LABEL = '1;36';

    /** Active cursor cell / selected row highlight: reverse video. */
    public const CURSOR = '7';

    /** Validator rejection line: red. */
    public const ERROR = '31';

    /** Completion preview and inline tail hint: bright black. */
    public const COMPLETION = '90';

    /** Confirmation's highlighted answer bracket: bold green. */
    public const SELECTED = '1;32';

    /**
     * Collapse a `Style|string` style into the raw SGR code string a
     * prompt slot can paint with.
     *
     * A string rides verbatim (the legacy spelling). A Style is
     * probe-rendered around a marker byte and must come back as the
     * exact `ESC[codes m marker ESC[0m` sandwich: only content-level
     * decoration (colors, bold, reverse, ...) has a code-string form —
     * padding, margins, borders, width/height fit, transforms and
     * hyperlinks are layout behavior that cannot live inside a single
     * inline code slot, so a Style carrying any of them fails loudly
     * instead of silently dropping the decoration.
     *
     * @throws \InvalidArgumentException if the Style is not collapsible
     */
    public static function code(Style|string $style): string
    {
        if (is_string($style)) {
            return $style;
        }
        $probe = $style->render('X');
        if ($probe === 'X') {
            // Decoration-free style: no codes to emit.
            return '';
        }
        if (\preg_match('/^\x1b\[([0-9;]*)mX\x1b\[0m$/', $probe, $m) === 1) {
            return $m[1];
        }
        throw new \InvalidArgumentException(
            'SugarCraft\Readline\Styles: this Style carries layout (padding, margin, border,'
            . ' width/height, transform or hyperlink) and cannot collapse into a raw SGR code slot;'
            . ' pass only content-level decoration (colors, bold, reverse, ...).'
        );
    }

    /** Constants-only holder; code() is the whole API. */
    private function __construct()
    {
    }
}
