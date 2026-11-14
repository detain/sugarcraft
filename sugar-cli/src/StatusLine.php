<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Cli\Internal\Clip;
use SugarCraft\Cli\Internal\SafeText;
use SugarCraft\Cli\Internal\WidthGuard;
use SugarCraft\Cli\Internal\WidthProbe;
use SugarCraft\Core\Util\Width;

/**
 * Render a single styled "status line" — a glyph + space + message.
 * Shaped after the success / error / warn / info lines common in Go
 * CLI tooling so the four severities (plus the prompt) all look
 * consistent within one program.
 *
 * Only the leading glyph is styled; the
 * message itself stays plain, exactly as callers hand it over.
 */
final class StatusLine
{
    public const GLYPH_SUCCESS = '✓';
    public const GLYPH_ERROR   = '✗';
    // WHY the cell budget may lie (016): ⚠ (U+26A0) and ℹ (U+2139) are
    // East-Asian-Ambiguous — sugar-core's width table counts them as 1 cell,
    // which matches modern western terminals, but emoji-substitution fonts and
    // some EA locales paint them 2 cells wide. The clamp therefore cannot
    // promise the visible fit on those hosts; the glyphs are deliberately NOT
    // swapped for wider-but-unambiguous lookalikes (an opt-in ASCII set is
    // the owner-gated alternative, see docs/research/libraries/sugar-cli-research.md).
    public const GLYPH_WARN    = '⚠';
    public const GLYPH_INFO    = 'ℹ';
    public const GLYPH_PROMPT  = '?';

    /**
     * @param int|AutoWidth|null $width  cell budget for the whole line; the
     *                     message (never the glyph) is cut to fit with the
     *                     house `…` ellipsis. A budget narrower than the
     *                     glyph plus its separator drops the separator: the
     *                     line degrades to the bare styled glyph so it always
     *                     fits. Omitted (or `AutoWidth::Auto`)
     *                     resolves to the terminal width, falling back to 80
     *                     cells — see {@see WidthProbe}. `null` disables the
     *                     clamp: the message renders in full.
     *                     Pass a custom budget as the named argument
     *                     `width:` — positionally it forces spelling out the
     *                     theme parameter too.
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function success(string $message, ?Theme $theme = null, int|AutoWidth|null $width = AutoWidth::Auto): string
    {
        return self::format(self::GLYPH_SUCCESS, $message, ($theme ?? Theme::detect())->success, $width);
    }

    /** @param int|AutoWidth|null $width  see {@see success()}. */
    public static function error(string $message, ?Theme $theme = null, int|AutoWidth|null $width = AutoWidth::Auto): string
    {
        return self::format(self::GLYPH_ERROR, $message, ($theme ?? Theme::detect())->error, $width);
    }

    /** @param int|AutoWidth|null $width  see {@see success()}. */
    public static function warn(string $message, ?Theme $theme = null, int|AutoWidth|null $width = AutoWidth::Auto): string
    {
        return self::format(self::GLYPH_WARN, $message, ($theme ?? Theme::detect())->warn, $width);
    }

    /** @param int|AutoWidth|null $width  see {@see success()}. */
    public static function info(string $message, ?Theme $theme = null, int|AutoWidth|null $width = AutoWidth::Auto): string
    {
        return self::format(self::GLYPH_INFO, $message, ($theme ?? Theme::detect())->info, $width);
    }

    /** @param int|AutoWidth|null $width  see {@see success()}. */
    public static function prompt(string $message, ?Theme $theme = null, int|AutoWidth|null $width = AutoWidth::Auto): string
    {
        return self::format(self::GLYPH_PROMPT, $message, ($theme ?? Theme::detect())->prompt, $width);
    }

    /**
     * Apply $style to the leading glyph; the message stays plain.
     *
     * The caller message is neutralized via {@see SafeText::line()} before it
     * reaches the terminal — it is interpolated raw into styled output, so an
     * embedded escape sequence or control byte would otherwise inject.
     *
     * The clamp measures the rendered prefix (cell-aware, so SGR around the
     * glyph costs nothing) and cuts only the message: a terminal narrower
     * than the line no longer hard-wraps it to column 0 mid-word.
     *
     * @throws \InvalidArgumentException when the resolved $width is less than 1
     */
    private static function format(string $glyph, string $message, \SugarCraft\Style\Style $style, int|AutoWidth|null $width): string
    {
        $resolved = WidthProbe::resolve($width);
        WidthGuard::assert('StatusLine', $resolved, 'render the full message');
        $prefix  = $style->render($glyph) . ' ';
        $message = SafeText::line($message);
        if ($resolved !== null) {
            if ($resolved < Width::string($prefix)) {
                // Even the separator space would overflow the cap. The
                // "glyph is never clipped" invariant wins: emit the styled
                // glyph bare, so the line always fits the budget.
                return $style->render($glyph);
            }
            $message = Clip::text($message, $resolved - Width::string($prefix));
        }
        return $prefix . $message;
    }
}
