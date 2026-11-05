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
    public const GLYPH_WARN    = '⚠';
    public const GLYPH_INFO    = 'ℹ';
    public const GLYPH_PROMPT  = '?';

    /**
     * @param int|AutoWidth|null $width  cell budget for the whole line; the
     *                     message (never the glyph) is cut to fit with the
     *                     house `…` ellipsis. Omitted (or `AutoWidth::Auto`)
     *                     resolves to the terminal width, falling back to 80
     *                     cells — see {@see WidthProbe}. `null` disables the
     *                     clamp: the message renders in full.
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
            $message = Clip::text($message, $resolved - Width::string($prefix));
        }
        return $prefix . $message;
    }
}
