<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Menu;

use SugarCraft\Widgets\Input\TextEdit;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * Options-menu row geometry as a convention helper, so every consumer's
 * options screen lands on the same shape. Two modes:
 *
 *  - {@see render()} — btop's two-line row (label centred over value);
 *  - {@see renderRow()} — candy-top's one-line row: label left-aligned,
 *    value right-aligned against the control glyphs, one row per option.
 *
 * Mirrors btop `Menu::optionsMenu` (src/btop_menu.cpp:1687-1700). Relative to
 * the row origin (btop's `x + 1`):
 *
 * ```
 * line 1: cjust(name, nameCol)                       bold; selected_bg/fg when selected
 * line 2: "  " + cjust(value, valueCol) + "  "       editing → TextEdit::view(valueCol - 1)
 *          ^1 ←                     ↵^valueCol  ^valueCol+2 → / ↵
 * ```
 *
 * With the defaults (29/25) that is btop's exact layout: `←` at column 1,
 * `→` at 27, `↵` at 27 — or at 25 when the arrows are showing, which in btop
 * is the int option case (ints are both 2D and editable) so the two glyphs
 * do not collide. Arrows only draw on the selected row while not editing;
 * `↵` only on the selected row of an editable option.
 *
 * btop never resets SGR between the two lines, so `selected_bg`/`selected_fg`
 * bleed into the value line; that is reproduced on purpose — the selected
 * option reads as one two-row highlighted block.
 *
 * While editing, the TextEdit's caret closes with SGR 24 (underline off), so
 * a selected style that itself sets underline loses it for the rest of the
 * value line after the caret — the same thing btop's `Fx::uul` does to an
 * underlined theme. Kept for parity rather than re-asserting the style.
 *
 * Centering follows btop `cjust`: the odd spare column goes on the LEFT
 * (`ceil(gap/2)`), the opposite of {@see Width::padCenter()}. Over-wide text
 * is truncated to the column, measured in display cells (btop's `ulen`
 * counts codepoints, which mis-centres CJK).
 */
final class OptionRow
{
    public const NAME_COL = 29;
    public const VALUE_COL = 25;

    public const LEFT = '←';
    public const RIGHT = '→';
    public const ENTER = '↵';
    /** btop's tty_mode substitute for {@see ENTER}. */
    public const ENTER_TTY = 'E';

    private function __construct()
    {
    }

    /**
     * Render the name line and value line joined by "\n".
     *
     * @param string|TextEdit $value plain value, or the live editor; while
     *                               $editing a TextEdit renders its
     *                               underline-caret view in valueCol - 1 cells.
     * @param bool  $hasArrows btop is2D/isBrowsable — show `←`/`→`.
     * @param bool  $editable  btop isEditable — show the `↵` (or `E`) glyph.
     * @param ?Style $title    unselected name colour (btop `title`).
     * @param ?Style $main     unselected value colour (btop `main_fg`).
     */
    public static function render(
        string $name,
        string|TextEdit $value,
        bool $selected,
        bool $editing,
        bool $hasArrows,
        int $nameCol = self::NAME_COL,
        int $valueCol = self::VALUE_COL,
        ?Style $selBg = null,
        ?Style $selFg = null,
        bool $editable = false,
        bool $tty = false,
        ?Style $title = null,
        ?Style $main = null,
    ): string {
        $nameCol = max(0, $nameCol);
        $valueCol = max(2, $valueCol);
        $editing = $editing && $selected;

        $sel = ($selFg ?? Style::new())->inherit($selBg ?? Style::new());
        $nameStyle = ($selected ? $sel : ($title ?? Style::new()))->bold();
        $valueStyle = $selected ? $sel : ($main ?? Style::new());

        $line1 = $nameStyle->render(self::cjust($name, $nameCol));

        $showArrows = $selected && $hasArrows && !$editing;
        $showEnter = $selected && $editable;
        $enter = $tty ? self::ENTER_TTY : self::ENTER;
        // btop shifts ↵ two columns left whenever → occupies its slot.
        $enterInValue = $showEnter && $showArrows;

        $prefix = [[' ', false], [$showArrows ? self::LEFT : ' ', $showArrows]];
        $suffix = [
            [$showArrows ? self::RIGHT : ($showEnter && !$enterInValue ? $enter : ' '), $showArrows || ($showEnter && !$enterInValue)],
            [' ', false],
        ];

        if ($editing && $value instanceof TextEdit) {
            $middle = [[self::cjust($value->view($valueCol - 1), $valueCol), false]];
        } else {
            $plain = $value instanceof TextEdit ? $value->text : $value;
            $middle = self::cells(self::cjust($plain, $valueCol));
            if ($enterInValue) {
                $middle = self::overlay($middle, $valueCol - 2, $enter);
            }
        }

        return $line1 . "\n" . self::paint(array_merge($prefix, $middle, $suffix), $valueStyle);
    }

    /** Widest value the one-line row shows before clipping (btop's value column). */
    public const VALUE_MAX = 25;

    /** Cells always kept for the label in one-line mode (6 keeps "Presets" legible). */
    private const ROW_LABEL_MIN = 6;

    /**
     * One-line row: `← label … value ↵ →` on a single row of exactly $field
     * cells, so the whole selected row reads as one highlighted block.
     *
     * ```
     * col 0        blank margin
     * col 1        ← (selected 2D row, not editing)
     * col 2        blank gutter
     * cols 3..     label left-aligned; value right-aligned ending at field-5
     * col F-4      ↵ when the arrows show (editable + 2D), else blank
     * col F-3      blank
     * col F-2      → when 2D; ↵ when editable without arrows; else blank
     * col F-1      blank margin
     * ```
     *
     * The label and the value share the band cols 3..F-5 (field - 7 cells):
     * the label keeps its full width when it fits next to the value, else it
     * clamps to {@see ROW_LABEL_MIN} cells before the value clips. While
     * editing, the TextEdit's caret view rides inline in the value band
     * (clipped to its slot) and the arrows hide — every slot stays one row.
     *
     * @param string|TextEdit $value plain value, or the live editor.
     * @param bool $hasArrows btop is2D/isBrowsable — show `←`/`→`.
     * @param bool $editable  btop isEditable — show the `↵` (or `E`) glyph.
     * @param ?Style $title   unselected label colour (btop `title`).
     * @param ?Style $main    unselected value colour (btop `main_fg`).
     */
    public static function renderRow(
        string $name,
        string|TextEdit $value,
        bool $selected,
        bool $editing,
        bool $hasArrows,
        int $field = self::NAME_COL,
        ?Style $selBg = null,
        ?Style $selFg = null,
        bool $editable = false,
        bool $tty = false,
        ?Style $title = null,
        ?Style $main = null,
    ): string {
        $field = max(16, $field);
        $band = $field - 7;
        $editing = $editing && $selected;

        $sel = ($selFg ?? Style::new())->inherit($selBg ?? Style::new());
        $nameStyle = ($selected ? $sel : ($title ?? Style::new()))->bold();
        $valueStyle = $selected ? $sel : ($main ?? Style::new());

        $showArrows = $selected && $hasArrows && !$editing;
        $showEnter = $selected && $editable;
        $enter = $tty ? self::ENTER_TTY : self::ENTER;

        $lwFull = Width::string($name);
        if ($editing && $value instanceof TextEdit) {
            $lw = min($lwFull, $band - 8);
            $vw = $band - 2 - $lw;
            $shown = self::rjustWidth($value->view(max(1, $vw - 1)), $vw);
        } else {
            $plain = $value instanceof TextEdit ? $value->text : $value;
            $vwFull = min(self::VALUE_MAX, Width::string($plain));
            $lw = min($lwFull, max(self::ROW_LABEL_MIN, $band - 2 - $vwFull));
            $vw = max(1, min($vwFull, $band - 2 - $lw));
            $shown = self::rjustWidth(Width::truncateAnsi($plain, $vw), $vw);
        }
        $label = self::ljustWidth(Width::truncateAnsi($name, min($lw, $band - $vw)), $band - $vw);

        return $valueStyle->render(' ')
            . ($showArrows ? $valueStyle->bold()->render(self::LEFT) : $valueStyle->render(' '))
            . $valueStyle->render(' ')
            . $nameStyle->render($label)
            . $valueStyle->render($shown)
            . ($showArrows && $showEnter ? $valueStyle->bold()->render($enter) : $valueStyle->render(' '))
            . $valueStyle->render(' ')
            . ($showArrows ? $valueStyle->bold()->render(self::RIGHT) : ($showEnter ? $valueStyle->bold()->render($enter) : $valueStyle->render(' ')))
            . $valueStyle->render(' ');
    }

    /** Left-align $text in $width cells (ANSI preserved, over-wide already cut). */
    private static function ljustWidth(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(0, $width - Width::string($text)));
    }

    /** Right-align $text in $width cells (ANSI preserved, over-wide already cut). */
    private static function rjustWidth(string $text, int $width): string
    {
        return str_repeat(' ', max(0, $width - Width::string($text))) . $text;
    }

    /**
     * btop `cjust(str, x, utf=true)`: truncate to $width cells, else centre
     * with the odd column on the left. ANSI in $s is preserved and not counted.
     */
    private static function cjust(string $s, int $width): string
    {
        $w = Width::string($s);
        if ($w > $width) {
            $s = Width::truncateAnsi($s, $width);
            $w = Width::string($s);
        }
        $gap = max(0, $width - $w);
        $left = intdiv($gap + 1, 2);
        return str_repeat(' ', $left) . $s . str_repeat(' ', $gap - $left);
    }

    /**
     * Split plain text into [cluster, glyph?] cells.
     *
     * @return list<array{string, bool}>
     */
    private static function cells(string $s): array
    {
        $out = [];
        $len = \strlen($s);
        for ($i = 0; $i < $len;) {
            $g = Width::nextCluster($s, $i);
            $out[] = [$g, false];
            $i += \strlen($g);
        }
        return $out;
    }

    /**
     * Overwrite the display column $col with $glyph, as btop's absolute
     * `Mv::to` overprint does. A wide cluster straddling $col is replaced by
     * spaces around the glyph so every other column keeps its position.
     *
     * @param list<array{string, bool}> $cells
     * @return list<array{string, bool}>
     */
    private static function overlay(array $cells, int $col, string $glyph): array
    {
        $out = [];
        $x = 0;
        foreach ($cells as $cell) {
            $w = Width::string($cell[0]);
            if ($w > 0 && $col >= $x && $col < $x + $w) {
                for ($k = 0; $k < $w; $k++) {
                    $out[] = $x + $k === $col ? [$glyph, true] : [' ', false];
                }
            } else {
                $out[] = $cell;
            }
            $x += $w;
        }
        return $out;
    }

    /**
     * Group consecutive cells by glyph-ness and render each run; glyphs are
     * bold (btop emits `Fx::b` before every arrow / enter glyph).
     *
     * @param list<array{string, bool}> $cells
     */
    private static function paint(array $cells, Style $base): string
    {
        $out = '';
        $run = '';
        $runBold = null;
        foreach ($cells as [$g, $bold]) {
            if ($runBold !== null && $bold !== $runBold) {
                $out .= ($runBold ? $base->bold() : $base)->render($run);
                $run = '';
            }
            $run .= $g;
            $runBold = $bold;
        }
        if ($run !== '') {
            $out .= ($runBold ? $base->bold() : $base)->render($run);
        }
        return $out;
    }
}
