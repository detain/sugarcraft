<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

use SugarCraft\Core\Util\Width;
use SugarCraft\Kit\Internal\SafeText;

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
     * Narrowest description column the side-by-side layout will wrap into.
     * Below it (a very wide key column or a very narrow terminal) each
     * description moves onto its own lines under the key, indented by
     * {@see STACKED_INDENT}, instead of being chopped into a ragged
     * one-or-two-word column.
     */
    public const MIN_DESCRIPTION_WIDTH = 16;

    /** Left margin of a description stacked under its key. */
    public const STACKED_INDENT = 6;

    /**
     * Render the full help screen.
     *
     * With a non-null `$width`, the usage synopsis, the description
     * paragraph and every row description are word-wrapped (cell-aware,
     * via {@see Width::wrap()}) so their lines fit in `$width` cells; see
     * {@see renderRows()} for how wrapped rows keep their alignment. Section
     * titles and row keys are never wrapped or cut — a flag name split over
     * two lines would no longer be the flag — so one that is itself wider
     * than `$width` still overflows.
     *
     * @param string $usage  one-line synopsis, e.g. `myapp [flags] <file>`
     * @param array<string, array<string, string>> $sections
     *        section title => entry => description. Section order
     *        is preserved.
     * @param ?int $width  cell width to wrap to; null = never wrap
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function render(
        string $usage,
        array $sections,
        string $description = '',
        ?Theme $theme = null,
        ?int $width = 80,
    ): string {
        self::assertWidth($width);
        $theme ??= Theme::detect();
        $blocks = [];
        // Caller-supplied usage/description/titles/rows are interpolated raw
        // into the help page — neutralize escape/control injection on each.
        if ($usage !== '') {
            $blocks[] = $theme->accent->render('USAGE') . "\n"
                      . self::indentLines(self::wrap(SafeText::line($usage), self::shrink($width, 2)), 2);
        }
        if ($description !== '') {
            $blocks[] = implode("\n", self::wrap(SafeText::line($description), $width));
        }
        foreach ($sections as $title => $rows) {
            // mb_strtoupper, not strtoupper: the byte-wise ASCII version left
            // non-ASCII titles (`café`) half-lowercase beside their siblings.
            $blocks[] = $theme->accent->render(mb_strtoupper(SafeText::line((string) $title), 'UTF-8')) . "\n"
                      . self::renderRows($rows, $theme, $width);
        }
        return implode("\n\n", $blocks);
    }

    /**
     * Render a single two-column block.
     *
     * Each row is `  KEY  description`, keys padded to the widest key so
     * every description starts in the same column. With a non-null `$width`
     * a description too long for the room right of that column wraps, and
     * each continuation line is indented to the description column, so the
     * two-column alignment survives the wrap. When that room is narrower
     * than {@see MIN_DESCRIPTION_WIDTH}, the rows switch to a stacked layout:
     * the key on its own line, the description wrapped beneath it at
     * {@see STACKED_INDENT}. Descriptions that already fit are emitted
     * byte-for-byte as they would be unwrapped.
     *
     * @param array<string, string> $rows
     * @param ?int $width  cell width to wrap to; null = never wrap
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function renderRows(array $rows, ?Theme $theme = null, ?int $width = 80): string
    {
        self::assertWidth($width);
        if ($rows === []) {
            return '';
        }
        $theme ??= Theme::detect();
        // Neutralize escape/control injection in caller keys + descriptions up
        // front, so both the alignment measurement and the render operate on
        // the sanitized strings. (array keys may be int-coerced — cast back.)
        $keys  = array_map(static fn ($k): string => SafeText::line((string) $k), array_keys($rows));
        $descs = array_map(static fn (string $d): string => SafeText::line($d), array_values($rows));
        $maxKey = array_reduce($keys, static fn (int $max, string $k): int
            => max($max, Width::string($k)), 0);

        // 2-cell left margin + key column + 2-cell gutter.
        $descColumn = 2 + $maxKey + 2;
        $stacked = $width !== null && $width - $descColumn < self::MIN_DESCRIPTION_WIDTH;

        $out = [];
        foreach ($keys as $i => $key) {
            $desc = $descs[$i];
            if ($stacked) {
                $out[] = '  ' . $theme->prompt->render($key);
                if ($desc !== '') {
                    $out[] = self::indentLines(
                        self::wrap($desc, self::shrink($width, self::STACKED_INDENT)),
                        self::STACKED_INDENT,
                    );
                }
                continue;
            }
            $lines = self::wrap($desc, self::shrink($width, $descColumn));
            $first = array_shift($lines);
            $out[] = '  ' . $theme->prompt->render(Width::padRight($key, $maxKey)) . '  ' . $first;
            if ($lines !== []) {
                $out[] = self::indentLines($lines, $descColumn);
            }
        }
        return implode("\n", $out);
    }

    /**
     * Word-wrap $text to $width cells. Text that already fits (or a null
     * width) comes back as its single unchanged line, so wrapping never
     * perturbs output that did not need it.
     *
     * @return list<string>
     */
    private static function wrap(string $text, ?int $width): array
    {
        if ($width === null || Width::string($text) <= $width) {
            return [$text];
        }
        return explode("\n", Width::wrap($text, $width));
    }

    /**
     * @param list<string> $lines
     */
    private static function indentLines(array $lines, int $indent): string
    {
        $pad = str_repeat(' ', $indent);
        return implode("\n", array_map(static fn (string $l): string => $pad . $l, $lines));
    }

    /** $width minus a left margin, never below one cell; null stays null. */
    private static function shrink(?int $width, int $margin): ?int
    {
        return $width === null ? null : max(1, $width - $margin);
    }

    private static function assertWidth(?int $width): void
    {
        if ($width !== null && $width < 1) {
            throw new \InvalidArgumentException(
                'HelpText width must be at least 1 cell (or null to disable wrapping); got ' . $width . '.'
            );
        }
    }
}
