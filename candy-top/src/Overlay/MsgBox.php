<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Top\Input\KeyName;
use SugarCraft\Top\Lang;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Surface;

/**
 * btop's `Menu::msgBox`: a centred box of content lines over one `Ok`
 * button ({@see OK}) or a `Yes` / `No` pair ({@see YES_NO}) — the shape of
 * the size-error box, the signal confirmation and the signal failure box
 * ({@see Menus}).
 *
 * Input (btop msgBox::input): escape / backspace / q / the No button
 * dismiss; the Ok/Yes button (or `o` on an Ok box, `y` on a Yes/No box)
 * accepts; enter / space pick the selected button; right / tab and
 * left / shift_tab move the selection on a Yes/No box. Accepting runs the
 * box's Cmd ({@see confirm()}) and closes it.
 *
 * Mirrors aristocratos/btop Menu::msgBox (src/btop_menu.cpp:906-993).
 */
final class MsgBox implements Overlay
{
    public const OK = 0;

    public const YES_NO = 1;

    /** btop Menu::process: any menu needs at least 50x20. */
    public const MIN_COLS = 50;

    public const MIN_ROWS = 20;

    /**
     * @param \Closure(Ink): list<string> $content SGR-carrying lines, each starting from the theme base
     * @param ?\Closure                   $yes     Cmd run when the box is accepted
     */
    private function __construct(
        public readonly int $width,
        public readonly int $type,
        public readonly string $title,
        private readonly \Closure $content,
        public readonly int $selected = 0,
        private readonly ?\Closure $yes = null,
        private readonly bool $alwaysShown = false,
    ) {
    }

    /**
     * An `Ok` box. `$alwaysShown` skips btop's 50x20 rule — the size-error
     * box itself must show even on a terminal too small for any menu.
     *
     * @param \Closure(Ink): list<string> $content
     */
    public static function ok(int $width, string $title, \Closure $content, bool $alwaysShown = false): self
    {
        return new self($width, self::OK, $title, $content, 0, null, $alwaysShown);
    }

    /**
     * A `Yes` / `No` box; `$yes` runs on Yes. btop starts with Yes selected.
     *
     * @param \Closure(Ink): list<string> $content
     */
    public static function confirm(int $width, string $title, \Closure $content, ?\Closure $yes): self
    {
        return new self($width, self::YES_NO, $title, $content, 0, $yes);
    }

    /** @return list<string> */
    public function lines(Ink $ink): array
    {
        return array_values(($this->content)($ink));
    }

    public function capturesInput(): bool
    {
        return true;
    }

    public function minSize(): array
    {
        return $this->alwaysShown ? [0, 0] : [self::MIN_COLS, self::MIN_ROWS];
    }

    public function update(Msg $msg, OverlayContext $context): OverlayResult
    {
        $key = KeyName::mapped($msg, $this->buttons($context));
        $upper = strtoupper($key);
        // Bound to a name rather than inlined as a ternary: OverlayResult::close()
        // shares its name with libc close(2), so candy-core's descriptor census
        // reads its argument, and it has (deliberately) no word for a ternary.
        $confirm = $this->selected === 0 ? $this->yes : null;

        return match (true) {
            $key === '' => OverlayResult::keep($this),
            in_array($key, ['escape', 'backspace', 'q', 'button2'], true) => OverlayResult::close(),
            $key === 'button1' || ($this->type === self::OK && $upper === 'O') => OverlayResult::close($this->yes),
            in_array($key, ['enter', 'space'], true) => OverlayResult::close($confirm),
            $this->type === self::OK => OverlayResult::keep($this),
            $upper === 'Y' => OverlayResult::close($this->yes),
            $upper === 'N' => OverlayResult::close(),
            in_array($key, ['right', 'tab'], true) => OverlayResult::keep($this->withSelected($this->selected + 1 > 1 ? 0 : 1)),
            in_array($key, ['left', 'shift_tab'], true) => OverlayResult::keep($this->withSelected($this->selected - 1 < 0 ? 1 : 0)),
            default => OverlayResult::keep($this),
        };
    }

    public function withSelected(int $selected): self
    {
        return new self($this->width, $this->type, $this->title, $this->content, $selected, $this->yes, $this->alwaysShown);
    }

    /**
     * btop's msgBox geometry, 1-based: [x, y, height].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function geometry(OverlayContext $c, int $lines): array
    {
        $height = $lines + 7;

        return [intdiv($c->cols, 2) - intdiv($this->width, 2), intdiv($c->rows, 2) - intdiv($height, 2), $height];
    }

    /**
     * btop's mouse_mappings button1 / button2 (0-based [x, y, w, h]).
     *
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public function buttons(OverlayContext $c): array
    {
        $n = count($this->lines($c->ink));
        [$x, $y, $height] = $this->geometry($c, $n);
        $col = $x + 1 + $this->buttonOffset();
        $line = $y + $height - 4;
        $map = ['button1' => [$col - 1, $line - 1, 12 + ($this->type > 0 ? 1 : 0), 3]];
        if ($this->type > 0) {
            $map['button2'] = [$col + 14, $line - 1, 12, 3];
        }

        return $map;
    }

    public function paint(Surface $surface, OverlayContext $c): void
    {
        $ink = $c->ink;
        $lines = $this->lines($ink);
        [$x, $y, $height] = $this->geometry($c, count($lines));
        MenuDraw::box($surface, $c, $x, $y, $this->width, $height, $this->title);
        foreach ($lines as $i => $line) {
            $len = Width::string((string) preg_replace('/\x1b\[[0-9;:]*m/', '', $line));
            MenuDraw::at($surface, $y + 2 + $i, $x + 1 + max(0, intdiv($this->width, 2) - intdiv($len, 2) - 1), $line);
        }

        // btop msgBox::operator(): one bold run; the first label un-bolds
        // the rest when it is not selected, so the second button is never bold.
        $border = $c->border();
        $first = $this->selected === 0 ? $ink->fg('hi_fg') : $ink->fg('div_line');
        $col = $x + 1 + $this->buttonOffset();
        $line = $y + count($lines) + 3;
        $label = $this->type === self::OK ? Lang::t('menu.msgbox.ok') : Lang::t('menu.msgbox.yes');
        $bold = $this->selected === 0;
        self::button($surface, $line, $col, $label, $this->type === self::OK ? 10 : 11, $first, MenuDraw::BOLD, $bold ? $ink->fg('title') . MenuDraw::BOLD : $ink->fg('main_fg'), $bold ? MenuDraw::BOLD : '', $border);
        if ($this->type > 0) {
            $second = $this->selected === 1 ? $ink->fg('hi_fg') : $ink->fg('div_line');
            $labelSgr = $this->selected === 1 ? $ink->fg('title') : $ink->fg('main_fg');
            self::button($surface, $line, $col + 15, Lang::t('menu.msgbox.no'), 10, $second, '', $labelSgr, '', $border);
        }
    }

    /** btop: `width / 2 - (boxtype == 0 ? 6 : 14)`. */
    private function buttonOffset(): int
    {
        return intdiv($this->width, 2) - ($this->type === self::OK ? 6 : 14);
    }

    /**
     * btop's button_left + label + button_right at 1-based (`$line`, `$col`):
     * a 3-row rounded frame `$cells + 2` wide around a centred label.
     */
    private static function button(
        Surface $s,
        int $line,
        int $col,
        string $label,
        int $cells,
        string $frame,
        string $leftBold,
        string $labelSgr,
        string $rightBold,
        \SugarCraft\Sprinkles\Border $border,
    ): void {
        $h = str_repeat($border->top, 6);
        $left = $frame . $leftBold;
        $right = $frame . $rightBold;
        MenuDraw::at($s, $line, $col, $left . $border->topLeft . $h);
        MenuDraw::at($s, $line + 2, $col, $left . $border->bottomLeft . $h);
        MenuDraw::at($s, $line + 1, $col, $left . $border->left);
        MenuDraw::at($s, $line + 1, $col + 1, $labelSgr . MenuDraw::cjust($label, $cells));
        MenuDraw::at($s, $line + 1, $col + 1 + $cells, $right . $border->right);
        MenuDraw::at($s, $line, $col + $cells - 5, $right . $h . $border->topRight);
        MenuDraw::at($s, $line + 2, $col + $cells - 5, $right . $h . $border->bottomRight);
    }
}
