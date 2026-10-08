<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Core\Msg;
use SugarCraft\Top\Input\KeyName;
use SugarCraft\Top\Input\KeyTable;
use SugarCraft\Top\Lang;
use SugarCraft\Top\View\Banner;
use SugarCraft\Top\View\Surface;

/**
 * btop's help menu: the banner over a 78-wide `help` box listing every
 * key from {@see KeyTable}, paged when the terminal is too short.
 *
 * Keys: escape / q / h / backspace / space / enter or any click close it;
 * with more than one page, down / j / page_down / tab / wheel-down turn
 * forward and up / k / page_up / shift_tab / wheel-up back, wrapping.
 *
 * Deviation: btop draws the `↑ page n/N ↓` indicator one row BELOW the
 * box (`y + height + 6`, off-screen on a 24-row terminal); here it sits on
 * the box's bottom border.
 *
 * Mirrors aristocratos/btop Menu::helpMenu (src/btop_menu.cpp:1743-1794).
 */
final class HelpMenu implements Overlay
{
    public const WIDTH = 78;

    private function __construct(
        public readonly int $page = 0,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public function capturesInput(): bool
    {
        return true;
    }

    /** btop Menu::process: main, options, help and the signal chooser need 80x24. */
    public function minSize(): array
    {
        return [80, 24];
    }

    /**
     * btop's help geometry, 1-based: [x, y, height, pages].
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function geometry(int $cols, int $rows): array
    {
        $n = count(KeyTable::rows());
        $y = max(1, intdiv($rows, 2) - 4 - intdiv($n, 2));
        $x = intdiv($cols, 2) - 39;
        $height = min($rows - 6, $n + 3);
        $pages = $height > 3 ? (int) ceil($n / ($height - 3)) : 1;

        return [$x, $y, $height, max(1, $pages)];
    }

    public function update(Msg $msg, OverlayContext $context): OverlayResult
    {
        $key = KeyName::of($msg);
        $pages = self::geometry($context->cols, $context->rows)[3];
        if (in_array($key, ['escape', 'q', 'h', 'backspace', 'space', 'enter', 'mouse_click'], true)) {
            return OverlayResult::close();
        }
        if ($pages > 1 && in_array($key, ['down', 'j', 'page_down', 'tab', 'mouse_scroll_down'], true)) {
            return OverlayResult::keep(new self($this->page + 1 >= $pages ? 0 : $this->page + 1));
        }
        if ($pages > 1 && in_array($key, ['up', 'k', 'page_up', 'shift_tab', 'mouse_scroll_up'], true)) {
            return OverlayResult::keep(new self($this->page - 1 < 0 ? $pages - 1 : $this->page - 1));
        }

        return OverlayResult::keep($this);
    }

    public function paint(Surface $surface, OverlayContext $c): void
    {
        $ink = $c->ink;
        [$x, $y, $height, $pages] = self::geometry($c->cols, $c->rows);
        $page = min($this->page, $pages - 1);
        Banner::paint($surface, $y - 1, $ink, $c->tty());
        MenuDraw::box($surface, $c, $x, $y + 6, self::WIDTH, $height, Lang::t('overlay.title.help'));
        $clip = MenuDraw::inside($x, $y + 6, self::WIDTH, $height);
        $bold = MenuDraw::BOLD;
        if ($pages > 1) {
            [$open, $close] = $c->border()->embedJunctions(true);
            MenuDraw::at(
                $surface,
                $y + 5 + $height,
                $x + 2,
                $ink->fg('hi_fg') . $open . $bold . '↑' . $ink->fg('title') . ' '
                    . Lang::t('overlay.help.page', ['page' => $page + 1, 'pages' => $pages]) . ' '
                    . $ink->fg('hi_fg') . '↓' . MenuDraw::UNBOLD . $close,
            );
        }
        $cy = $y + 7;
        MenuDraw::at($surface, $cy++, $x + 1, $ink->fg('title') . $bold . MenuDraw::cjust(Lang::t('menu.help.key'), 20) . Lang::t('menu.help.description'), '', $clip);
        $rows = KeyTable::rows();
        $per = max(1, $height - 3);
        foreach (array_slice($rows, $per * $page, $per) as $row) {
            MenuDraw::at(
                $surface,
                $cy++,
                $x + 1,
                $ink->fg('hi_fg') . $bold . MenuDraw::cjust($row->keyLabel(), 20) . $ink->fg('main_fg') . MenuDraw::UNBOLD . $row->description(),
                '',
                $clip,
            );
        }
    }
}
