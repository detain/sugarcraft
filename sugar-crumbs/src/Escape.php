<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

/**
 * Separator escaping for navigation item titles.
 */
final class Escape
{
    private const SEPARATOR = ' > ';

    /**
     * Escape a title so it can safely appear in a breadcrumb render.
     * If the title contains the separator, replace it with the escape sequence.
     */
    public static function title(string $title): string
    {
        return \str_replace(self::SEPARATOR, '\\' . self::SEPARATOR, $title);
    }

    /**
     * Unescape a title that was escaped with title().
     */
    public static function unescape(string $title): string
    {
        return \str_replace('\\' . self::SEPARATOR, self::SEPARATOR, $title);
    }
}
