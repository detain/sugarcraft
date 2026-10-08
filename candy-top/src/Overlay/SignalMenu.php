<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Core\Msg;
use SugarCraft\Top\Collect\ProcessControl;
use SugarCraft\Top\Input\KeyName;
use SugarCraft\Top\Lang;
use SugarCraft\Top\View\Surface;

/**
 * btop's signal chooser (`s` on a selected process): a 78x19 `signals` box
 * with a 5-column grid of signals 1-31 (16, SIGSTKFLT, skipped) and a
 * typed-number field.
 *
 * Keys: digits type a number (two at most, capped at 64); backspace drops
 * a digit; arrows (or h/j/k/l) walk the grid the way btop does (up from
 * row one wraps to the bottom row, down from the last wraps to 1, 16 is
 * jumped); enter / space send the selected signal; clicking a signal
 * selects it and clicking it again sends; escape / q abort.
 *
 * Sending happens inside the returned Cmd through {@see ProcessControl};
 * a failure (or a pid below 1, btop's ESRCH) opens the failure box
 * ({@see Menus::signalReturn()}) through an
 * {@see \SugarCraft\Top\Msg\OpenOverlayMsg}.
 *
 * Mirrors aristocratos/btop Menu::signalChoose (src/btop_menu.cpp:1001-1115).
 */
final class SignalMenu implements Overlay
{
    private function __construct(
        public readonly int $pid,
        public readonly string $name,
        private readonly ProcessControl $control,
        public readonly int $selected = -1,
    ) {
    }

    public static function new(int $pid, string $name, ProcessControl $control): self
    {
        return new self($pid, $name, $control);
    }

    public function capturesInput(): bool
    {
        return true;
    }

    public function minSize(): array
    {
        return [80, 24];
    }

    /**
     * btop's 1-based origin: [x, y] with the box at (x + 2, y).
     *
     * @return array{0: int, 1: int}
     */
    public static function origin(int $cols, int $rows): array
    {
        return [intdiv($cols, 2) - 40, intdiv($rows, 2) - 9];
    }

    /**
     * btop's mouse_mappings: button_<n> per grid cell, plus the `enter` and
     * `escape` hint rows (0-based [x, y, w, h]).
     *
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function buttons(int $cols, int $rows): array
    {
        [$x, $y] = self::origin($cols, $rows);
        $map = [];
        foreach (self::cells($x, $y) as $n => [$cy, $cx]) {
            $map['button_' . $n] = [$cx - 1, $cy - 1, 15, 1];
        }
        $map['enter'] = [$x - 1, $y + 14, 73, 1];
        $map['escape'] = [$x - 1, $y + 15, 73, 1];

        return $map;
    }

    /**
     * Grid positions, 1-based: signal => [line, col].
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function cells(int $x, int $y): array
    {
        $out = [];
        $cy = $y + 5;
        $cx = $x + 4;
        $i = 0;
        for ($n = 1; $n < count(Signals::NAMES); $n++) {
            if ($n === 16) {
                continue;
            }
            if ($i++ % 5 === 0) {
                $cy++;
                $cx = $x + 4;
            }
            $out[$n] = [$cy, $cx];
            $cx += 15;
        }

        return $out;
    }

    public function update(Msg $msg, OverlayContext $context): OverlayResult
    {
        $key = KeyName::mapped($msg, self::buttons($context->cols, $context->rows));
        $s = $this->selected;
        if (in_array($key, ['escape', 'q'], true)) {
            return OverlayResult::close();
        }
        if (str_starts_with($key, 'button_')) {
            $pick = (int) substr($key, 7);

            return $pick === $s ? $this->send($pick) : OverlayResult::keep($this->with($pick));
        }
        if (in_array($key, ['enter', 'space'], true) && $s >= 0) {
            return $this->send($s);
        }
        if (strlen($key) === 1 && ctype_digit($key) && $s < 10) {
            return OverlayResult::keep($this->with(min((int) ($s < 1 ? $key : $s . $key), 64)));
        }
        if ($key === 'backspace' && $s !== -1) {
            return OverlayResult::keep($this->with($s < 10 ? -1 : intdiv($s, 10)));
        }
        if (in_array($key, ['up', 'k'], true) && $s !== 16) {
            if ($s === 1) {
                $s = 31;
            } elseif ($s < 6) {
                $s += 25;
            } else {
                $offset = $s > 16;
                $s -= 5;
                if ($s <= 16 && $offset) {
                    $s--;
                }
            }

            return OverlayResult::keep($this->with($s));
        }
        if (in_array($key, ['down', 'j'], true)) {
            if ($s === 31) {
                $s = 1;
            } elseif ($s < 1 || $s === 16) {
                $s = 1;
            } elseif ($s > 26) {
                $s -= 25;
            } else {
                $offset = $s < 16;
                $s += 5;
                if ($s >= 16 && $offset) {
                    $s++;
                }
                if ($s > 31) {
                    $s = 31;
                }
            }

            return OverlayResult::keep($this->with($s));
        }
        if (in_array($key, ['left', 'h'], true) && $s > 0 && $s !== 16) {
            if (--$s < 1) {
                $s = 31;
            } elseif ($s === 16) {
                $s--;
            }

            return OverlayResult::keep($this->with($s));
        }
        if (in_array($key, ['right', 'l'], true) && $s <= 31 && $s !== 16) {
            if (++$s > 31) {
                $s = 1;
            } elseif ($s === 16) {
                $s++;
            }

            return OverlayResult::keep($this->with($s));
        }

        return OverlayResult::keep($this);
    }

    private function with(int $selected): self
    {
        return new self($this->pid, $this->name, $this->control, $selected);
    }

    /** btop ChooseEntering: close, sending in a Cmd; failure reopens as the error box. */
    private function send(int $signal): OverlayResult
    {
        return OverlayResult::close(Menus::sendSignal($this->control, $this->pid, $signal));
    }

    public function paint(Surface $surface, OverlayContext $c): void
    {
        $ink = $c->ink;
        [$x, $y] = self::origin($c->cols, $c->rows);
        $bold = MenuDraw::BOLD;
        $main = $ink->fg('main_fg');
        $hi = $ink->fg('hi_fg');
        MenuDraw::box($surface, $c, $x + 2, $y, 78, 19, Lang::t('overlay.title.signals'));
        $clip = MenuDraw::inside($x + 2, $y, 78, 19);
        MenuDraw::at(
            $surface,
            $y + 2,
            $x + 3,
            $ink->fg('title') . $bold . MenuDraw::cjust(Lang::t('overlay.signal.prompt', ['pid' => $this->pid, 'name' => MenuDraw::cut(MenuDraw::clean($this->name), 30)]), 76),
            '',
            $clip,
        );
        MenuDraw::at(
            $surface,
            $y + 4,
            $x + 3,
            $main . MenuDraw::UNBOLD . MenuDraw::rjust(Lang::t('overlay.signal.enter'), 48) . $hi
                . ($this->selected >= 0 ? (string) $this->selected : '') . $main . MenuDraw::BLINK . '█' . MenuDraw::UNBLINK,
            '',
            $clip,
        );
        foreach (self::cells($x, $y) as $n => [$cy, $cx]) {
            $num = MenuDraw::ljust((string) $n, 3);
            $label = MenuDraw::ljust('(' . Signals::NAMES[$n] . ')', 12);
            $text = $n === $this->selected
                ? $ink->bg('selected_bg') . $ink->fg('selected_fg') . $bold . $num . $label
                : $hi . $num . $main . $label;
            MenuDraw::at($surface, $cy, $cx, $text, '', $clip);
        }
        $hints = [
            ['↑ ↓ ← →', 'overlay.signal.hint.choose'],
            ['0-9', 'overlay.signal.hint.manual'],
            ['ENTER', 'overlay.signal.hint.send'],
            [Lang::t('overlay.hint.escape'), 'overlay.signal.hint.abort'],
        ];
        foreach ($hints as $i => [$keys, $text]) {
            MenuDraw::at($surface, $y + 13 + $i, $x + 3, $bold . $hi . MenuDraw::rjust($keys, 33) . $main . MenuDraw::UNBOLD . ' | ' . Lang::t($text), '', $clip);
        }
    }
}
