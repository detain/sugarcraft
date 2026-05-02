<?php

declare(strict_types=1);

namespace CandyCore\Kit;

/**
 * Render a single styled "status line" — a glyph + space + message.
 * Mirrors the typical fang/charm CLI presentation so success / error
 * / warn / info messages all look consistent.
 */
final class StatusLine
{
    public const GLYPH_SUCCESS = '✓';
    public const GLYPH_ERROR   = '✗';
    public const GLYPH_WARN    = '⚠';
    public const GLYPH_INFO    = 'ℹ';
    public const GLYPH_PROMPT  = '?';

    public static function success(string $message, ?Theme $theme = null): string
    {
        return self::format(self::GLYPH_SUCCESS, $message, ($theme ?? Theme::ansi())->success);
    }

    public static function error(string $message, ?Theme $theme = null): string
    {
        return self::format(self::GLYPH_ERROR, $message, ($theme ?? Theme::ansi())->error);
    }

    public static function warn(string $message, ?Theme $theme = null): string
    {
        return self::format(self::GLYPH_WARN, $message, ($theme ?? Theme::ansi())->warn);
    }

    public static function info(string $message, ?Theme $theme = null): string
    {
        return self::format(self::GLYPH_INFO, $message, ($theme ?? Theme::ansi())->info);
    }

    public static function prompt(string $message, ?Theme $theme = null): string
    {
        return self::format(self::GLYPH_PROMPT, $message, ($theme ?? Theme::ansi())->prompt);
    }

    /** Apply $style to the leading glyph + space; the message stays plain. */
    private static function format(string $glyph, string $message, \CandyCore\Sprinkles\Style $style): string
    {
        return $style->render($glyph) . ' ' . $message;
    }
}
