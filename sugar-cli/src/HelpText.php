<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Core\Util\Width;
use SugarCraft\Cli\Internal\SafeText;
use SugarCraft\Cli\Internal\WidthGuard;
use SugarCraft\Cli\Internal\WidthProbe;

/**
 * Build a fang-style `--help` page from structured input. Each
 * supplied section becomes a labelled block of two-column
 * `KEY  description` rows (like git's `--help` output).
 *
 * WHY this is not a fang port: fang drives its layout from cobra's typed
 * flag metadata (required/optional/default markers); there is no parsed
 * command tree here, so this surface lays out plain key/description
 * pairs — every such affordance is text the caller supplies, not
 * something parsed. Mirrors charmbracelet/fang's help screen in shape
 * only.
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
     * than `$width` still overflows. Note that a title's cell width can grow
     * under uppercasing (`ß` becomes `SS` in UTF-8); it is measured after the
     * growth but never re-wrapped. Hosts that own their typography can opt out
     * of the uppercasing entirely with `titleCase: false`, or supply their own
     * locale mapping as a callable.
     *
     * @param string $usage  one-line synopsis, e.g. `myapp [flags] <file>`
     * @param array<string, array<string, string>> $sections
     *        section title => entry => description. Section order
     *        is preserved. A section whose entry map is empty renders no
     *        heading at all.
     * @param int|AutoWidth|null $width  cell width to wrap to; null = never
     *                     wrap. Omitted (or `AutoWidth::Auto`) resolves to the
     *                     terminal width — the exported `COLUMNS` where the
     *                     environment advertises one, else the historical
     *                     80-cell fallback (see {@see WidthProbe}).
     * @param string $usageLabel  heading over the synopsis, uppercased like a
     *                     section title; default `USAGE`. Pass a translated
     *                     string for localized help, or `''` to drop the
     *                     heading line and print the synopsis alone.
     * @param bool|callable $titleCase  styling of the usage label and every
     *                     section title. `true` (default) uppercases with
     *                     `mb_strtoupper` exactly as before. `false` prints
     *                     titles exactly as given — the right answer for
     *                     locales where uppercasing is a lossy transform:
     *                     German `ß` grows to `SS` (and its capital `ẞ` is
     *                     not what readers expect), Turkish dotted `ı`/`İ`
     *                     collide, and Greek loses breathings. A callable
     *                     replaces the mapping entirely and receives (and
     *                     must return) the sanitized title string.
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function render(
        string $usage,
        array $sections,
        string $description = '',
        ?Theme $theme = null,
        int|AutoWidth|null $width = AutoWidth::Auto,
        string $usageLabel = 'USAGE',
        bool|callable $titleCase = true,
    ): string {
        $width = WidthProbe::resolve($width);
        self::assertWidth($width);
        $theme ??= Theme::detect();
        $blocks = [];
        // Caller-supplied usage/description/titles/rows/label are interpolated
        // raw into the help page — neutralize escape/control injection on each.
        if ($usage !== '') {
            $label = SafeText::line($usageLabel);
            $label = $label === '' ? '' : self::applyTitleCase($label, $titleCase);
            $synopsis = self::indentLines(
                self::wrap(SafeText::line($usage), self::shrink($width, self::clampedIndent($width, 2))),
                self::clampedIndent($width, 2),
            );
            $blocks[] = $label === '' ? $synopsis : $theme->accent->render($label) . "\n" . $synopsis;
        }
        if ($description !== '') {
            $blocks[] = implode("\n", self::wrap(SafeText::line($description), $width));
        }
        foreach ($sections as $title => $rows) {
            $renderedRows = self::renderRows($rows, $theme, $width);
            if ($renderedRows === '') {
                // An empty section would leave a heading with nothing under
                // it (and a dangling separator in the join) — skip the block.
                continue;
            }
            // mb_strtoupper (via applyTitleCase), not strtoupper: the byte-wise
            // ASCII version left non-ASCII titles (`café`) half-lowercase
            // beside their siblings.
            $blocks[] = $theme->accent->render(self::applyTitleCase(SafeText::line((string) $title), $titleCase)) . "\n"
                      . $renderedRows;
        }
        return implode("\n\n", $blocks);
    }

    /**
     * Apply the caller's title-case policy to one sanitized title.
     *
     * The three arms exist because {@see mb_strtoupper()} is lossy in both
     * directions: it grows German `ß` to `SS` (a wider string than the
     * caller authored, and a different word to a German reader for
     * `Straße`→`STRASSE`-style cases) and it leaves Turkish dotted/dotless
     * `ı`/`İ` pairs mismatched under a generic Latin mapping. A host that
     * owns its typography passes `titleCase: false` (as-given) or a
     * callable (locale table); the default keeps the fleet-wide shouty
     * heading look byte-identical.
     */
    private static function applyTitleCase(string $title, bool|callable $titleCase): string
    {
        if ($titleCase === false) {
            return $title;
        }
        if ($titleCase === true) {
            return mb_strtoupper($title, 'UTF-8');
        }
        return (string) $titleCase($title);
    }

    /**
     * Sanitize — and optionally re-wrap — an already-laid-out multi-line page.
     *
     * The multi-line-preserving counterpart of {@see render()}: pages
     * whose embedded newlines ARE the layout (a translated CLI help screen,
     * for instance) cannot ride {@see render()}, which flattens every caller
     * string through {@see SafeText::line()} and would collapse the whole
     * page onto one row. Here each authored row survives: escape sequences
     * and control bytes are stripped per {@see SafeText::page()}, and with
     * an explicit `$width` any row too long for it is word-wrapped
     * cell-aware (via {@see Width::wrap()}) in place while short rows come
     * back byte-for-byte unchanged.
     *
     * Unlike the rest of the class, `$width` defaults to null — never
     * wrap — on purpose. {@see render()} builds columns and must know its
     * canvas; a page arrives already laid out by its author, and silently
     * re-wrapping it to a terminal probed mid-pipe (the historical 80-cell
     * fallback) would re-flow content nobody asked to re-flow. Callers who
     * want terminal fitting pass {@see AutoWidth::Auto} or a width
     * explicitly.
     *
     * @param string $page the full page, rows separated by "\n" (CRLF is
     *                     normalized to "\n" — the CR is a stripped control)
     * @param int|AutoWidth|null $width  cell width to wrap over-long rows to;
     *                     null (the default) = never wrap, the page is
     *                     returned exactly as sanitized
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function renderPage(string $page, int|AutoWidth|null $width = null): string
    {
        $width = WidthProbe::resolve($width);
        WidthGuard::assert('HelpText::renderPage', $width, 'leave the page exactly as authored');

        $clean = SafeText::page($page);
        if ($width === null) {
            return $clean;
        }

        $rows = explode("\n", $clean);

        return implode("\n", array_map(static fn (string $row): string => implode("\n", self::wrap($row, $width)), $rows));
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
     * @param int|AutoWidth|null $width  cell width to wrap to; null = never
     *                     wrap. Omitted (or `AutoWidth::Auto`) resolves to the
     *                     terminal width, falling back to 80 cells — see
     *                     {@see render()} and {@see WidthProbe}.
     *
     * @throws \InvalidArgumentException when `$width` is less than 1
     */
    public static function renderRows(array $rows, ?Theme $theme = null, int|AutoWidth|null $width = AutoWidth::Auto): string
    {
        $width = WidthProbe::resolve($width);
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
                    $indent = self::clampedIndent($width, self::STACKED_INDENT);
                    $out[] = self::indentLines(
                        self::wrap($desc, self::shrink($width, $indent)),
                        $indent,
                    );
                }
                continue;
            }
            $lines = self::wrap($desc, self::shrink($width, $descColumn));
            $first = array_shift($lines);
            $out[] = '  ' . $theme->prompt->render(Width::padRight($key, $maxKey)) . '  ' . $first;
            if ($lines !== []) {
                $out[] = self::indentLines($lines, self::clampedIndent($width, $descColumn));
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

    /**
     * A continuation indent that can never consume the whole canvas: at most
     * `$indent` cells, but squeezed below the width when the window is
     * narrower than the margin (null width = no wrapping, no clamp).
     */
    private static function clampedIndent(?int $width, int $indent): int
    {
        return $width === null ? $indent : min($indent, max(1, $width - 1));
    }

    /**
     * Refuse a width below one cell, harmonized with Section via
     * {@see WidthGuard}.
     */
    private static function assertWidth(?int $width): void
    {
        WidthGuard::assert('HelpText', $width, 'disable wrapping');
    }
}
