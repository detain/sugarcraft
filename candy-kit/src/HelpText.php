<?php

declare(strict_types=1);

namespace CandyCore\Kit;

use CandyCore\Sprinkles\Style;

/**
 * Build a fang-style `--help` page from structured input. Each
 * supplied section becomes a labelled block of two-column
 * `KEY  description` rows (like git's `--help` output).
 *
 * Mirrors charmbracelet/fang's HelpText surface — used by CLIs that
 * want a polished, branded help screen without rolling their own.
 */
final class HelpText
{
    /**
     * Render the full help screen.
     *
     * @param string $usage  one-line synopsis, e.g. `myapp [flags] <file>`
     * @param array<string, array<string, string>> $sections
     *        section title => entry => description. Section order
     *        is preserved.
     */
    public static function render(
        string $usage,
        array $sections,
        string $description = '',
        ?Theme $theme = null,
    ): string {
        $theme ??= Theme::ansi();
        $blocks = [];
        if ($usage !== '') {
            $blocks[] = $theme->accent->render('USAGE') . "\n  " . $usage;
        }
        if ($description !== '') {
            $blocks[] = $description;
        }
        foreach ($sections as $title => $rows) {
            $blocks[] = $theme->accent->render(strtoupper($title)) . "\n"
                      . self::renderRows($rows, $theme);
        }
        return implode("\n\n", $blocks);
    }

    /**
     * Render a single two-column block.
     *
     * @param array<string, string> $rows
     */
    public static function renderRows(array $rows, ?Theme $theme = null): string
    {
        if ($rows === []) {
            return '';
        }
        $theme ??= Theme::ansi();
        $maxKey = 0;
        foreach (array_keys($rows) as $k) {
            if (mb_strlen($k, 'UTF-8') > $maxKey) {
                $maxKey = mb_strlen($k, 'UTF-8');
            }
        }
        $lines = [];
        foreach ($rows as $key => $desc) {
            $padded = $key . str_repeat(' ', max(0, $maxKey - mb_strlen($key, 'UTF-8')));
            $lines[] = '  ' . $theme->prompt->render($padded) . '  ' . $desc;
        }
        return implode("\n", $lines);
    }
}
