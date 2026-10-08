<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Menu;

use SugarCraft\Bits\Input\TextEdit;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;

/**
 * btop's two-line options-menu row, as a convention helper so every
 * consumer's options screen lands on the same geometry.
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
