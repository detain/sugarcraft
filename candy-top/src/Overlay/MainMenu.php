<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Top\Input\KeyName;
use SugarCraft\Top\Msg\QuitRequestMsg;
use SugarCraft\Top\View\Banner;
use SugarCraft\Top\View\Surface;

/**
 * btop's main menu: the banner over three block-letter entries — Options,
 * Help, Quit.
 *
 * Keys: escape / q / m or a click outside the entries close it; down /
 * tab / j / wheel-down and up / shift_tab / k / wheel-up move the
 * selection (wrapping); enter / space — or clicking the selected entry —
 * open it: Options and Help open ON TOP (btop `Switch`, the main menu
 * stays underneath and comes back reset when they close), Quit quits.
 * Clicking another entry selects it.
 *
 * The entries are btop's own box-drawing artwork (menu_normal /
 * menu_selected), so they read in English whatever the locale.
 *
 * Mirrors aristocratos/btop Menu::mainMenu (src/btop_menu.cpp:1219-1296).
 */
final class MainMenu implements Overlay
{
    public const OPTIONS = 0;

    public const HELP = 1;

    public const QUIT = 2;

    public const NORMAL = [
        [
            '┌─┐┌─┐┌┬┐┬┌─┐┌┐┌┌─┐',
            '│ │├─┘ │ ││ ││││└─┐',
            '└─┘┴   ┴ ┴└─┘┘└┘└─┘',
        ],
        [
            '┬ ┬┌─┐┬  ┌─┐',
            '├─┤├┤ │  ├─┘',
            '┴ ┴└─┘┴─┘┴  ',
        ],
        [
            '┌─┐ ┬ ┬ ┬┌┬┐',
            '│─┼┐│ │ │ │ ',
            '└─┘└└─┘ ┴ ┴ ',
        ],
    ];

    public const SELECTED = [
        [
            '╔═╗╔═╗╔╦╗╦╔═╗╔╗╔╔═╗',
            '║ ║╠═╝ ║ ║║ ║║║║╚═╗',
            '╚═╝╩   ╩ ╩╚═╝╝╚╝╚═╝',
        ],
        [
            '╦ ╦╔═╗╦  ╔═╗',
            '╠═╣╠╣ ║  ╠═╝',
            '╩ ╩╚═╝╩═╝╩  ',
        ],
        [
            '╔═╗ ╦ ╦ ╦╔╦╗ ',
            '║═╬╗║ ║ ║ ║  ',
            '╚═╝╚╚═╝ ╩ ╩  ',
        ],
    ];

    /** btop menu_width. */
    public const WIDTHS = [19, 12, 12];

    /** btop colors_normal: "#CC", "#AA", "#80" (grey shorthand). */
    private const NORMAL_GREYS = [0xCC, 0xAA, 0x80];

    private function __construct(
        public readonly int $selected = 0,
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

    public function minSize(): array
    {
        return [80, 24];
    }

    /** btop `y = Term::height / 2 - 10` (1-based banner row). */
    public static function top(int $rows): int
    {
        return intdiv($rows, 2) - 10;
    }

    /**
     * btop's mouse_mappings button_0..2 (0-based [x, y, w, h]).
     *
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function buttons(int $cols, int $rows): array
    {
        $map = [];
        $cy = self::top($rows) + 7;
        foreach (self::WIDTHS as $i => $w) {
            $map['button_' . $i] = [intdiv($cols, 2) - intdiv($w, 2) - 1, $cy - 1, $w, 3];
            $cy += 3;
        }

        return $map;
    }

    public function update(Msg $msg, OverlayContext $context): OverlayResult
    {
        $key = KeyName::mapped($msg, self::buttons($context->cols, $context->rows));
        if (in_array($key, ['escape', 'q', 'm', 'mouse_click'], true)) {
            return OverlayResult::close();
        }
        if (str_starts_with($key, 'button_')) {
            $pick = (int) substr($key, 7);

            return $pick === $this->selected ? $this->enter() : OverlayResult::keep(new self($pick));
        }
        if (in_array($key, ['enter', 'space'], true)) {
            return $this->enter();
        }
        if (in_array($key, ['down', 'tab', 'mouse_scroll_down', 'j'], true)) {
            return OverlayResult::keep(new self($this->selected + 1 > 2 ? 0 : $this->selected + 1));
        }
        if (in_array($key, ['up', 'shift_tab', 'mouse_scroll_up', 'k'], true)) {
            return OverlayResult::keep(new self($this->selected - 1 < 0 ? 2 : $this->selected - 1));
        }

        return OverlayResult::keep($this);
    }

    /** btop MainEntering: Options / Help switch on top (the menu returns reset), Quit quits. */
    private function enter(): OverlayResult
    {
        return match ($this->selected) {
            self::OPTIONS => new OverlayResult(self::new(), null, Menus::options()),
            self::HELP => new OverlayResult(self::new(), null, Menus::help()),
            // The App answers with its quit Cmd (config save first).
            default => OverlayResult::close(static fn (): Msg => new QuitRequestMsg()),
        };
    }

    public function paint(Surface $surface, OverlayContext $c): void
    {
        $ink = $c->ink;
        $tty = $c->tty();
        $y = self::top($c->rows);
        Banner::paint($surface, $y - 1, $ink, $tty);
        $profile = $ink->profile();
        $cy = $y + 7;
        foreach (self::WIDTHS as $i => $w) {
            $art = !$tty && $i === $this->selected ? self::SELECTED[$i] : self::NORMAL[$i];
            foreach ($art as $ic => $line) {
                if ($tty) {
                    $sgr = $i === $this->selected ? $ink->fg('hi_fg') : $ink->fg('main_fg');
                } elseif ($i === $this->selected) {
                    $sgr = Color::hex(Banner::COLORS[$ic * 2])->toFg($profile);
                } else {
                    $grey = self::NORMAL_GREYS[$ic];
                    $sgr = Color::rgb($grey, $grey, $grey)->toFg($profile);
                }
                MenuDraw::at($surface, $cy++, intdiv($c->cols, 2) - intdiv($w, 2), $line, $sgr . MenuDraw::BOLD);
            }
        }
    }
}
