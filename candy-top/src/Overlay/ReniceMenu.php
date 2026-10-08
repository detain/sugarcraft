<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Core\Msg;
use SugarCraft\Top\Collect\ProcessControl;
use SugarCraft\Top\Input\KeyName;
use SugarCraft\Top\Lang;
use SugarCraft\Top\View\Surface;

/**
 * btop's renice menu (`N` on a selected process): a 50x13 `renice` box
 * with a nice-value field starting at 0.
 *
 * Keys: digits (and a leading `-`) type a value; backspace edits it
 * (emptying the field keeps the last value it parsed to, as btop does);
 * up / k and down / j step by 1 and left / h, right / l by 5, wrapping
 * inside -20..19 (a step clears the typed text); enter / space apply it;
 * escape / q abort. A typed value is applied as typed (btop's stoi; the
 * kernel clamps it).
 *
 * The renice runs inside the returned Cmd through {@see ProcessControl}.
 * Deviation: btop ignores a failed set_priority ("TODO: show error
 * message"); here a failure opens the failure box.
 *
 * Mirrors aristocratos/btop Menu::reniceMenu (src/btop_menu.cpp:1796-1890).
 */
final class ReniceMenu implements Overlay
{
    private function __construct(
        public readonly int $pid,
        public readonly string $name,
        private readonly ProcessControl $control,
        public readonly int $nice = 0,
        public readonly string $edit = '',
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
        return [MsgBox::MIN_COLS, MsgBox::MIN_ROWS];
    }

    /**
     * btop's 1-based origin: [x, y] with the box at (x + 2, y).
     *
     * @return array{0: int, 1: int}
     */
    public static function origin(int $cols, int $rows): array
    {
        return [intdiv($cols, 2) - 25, intdiv($rows, 2) - 6];
    }

    /** The value enter would apply: the typed text when there is one, else the stepped value. */
    public function value(): int
    {
        if ($this->edit === '') {
            return $this->nice;
        }

        // btop stoi(): no digits or a value outside int throws, and the
        // catch makes it 0 — PHP's (int) would saturate instead.
        if (preg_match('/^-?\d+$/', $this->edit) !== 1) {
            return 0;
        }
        $value = (float) $this->edit;

        return $value < -2_147_483_648 || $value > 2_147_483_647 ? 0 : (int) $this->edit;
    }

    public function update(Msg $msg, OverlayContext $context): OverlayResult
    {
        $key = KeyName::of($msg);
        if (in_array($key, ['escape', 'q'], true)) {
            return OverlayResult::close();
        }
        if (in_array($key, ['enter', 'space'], true)) {
            return OverlayResult::close($this->pid > 0 ? Menus::renice($this->control, $this->pid, $this->value()) : null);
        }
        // btop re-parses the field on every redraw (`selected_nice =
        // stoi(nice_edit)` while it is non-empty), so the stepped value
        // follows the typing and backspacing to empty keeps the last parse.
        if (strlen($key) === 1 && (ctype_digit($key) || ($key === '-' && $this->edit === ''))) {
            return OverlayResult::keep($this->edited($this->edit . $key));
        }
        if ($key === 'backspace' && $this->edit !== '') {
            return OverlayResult::keep($this->edited(substr($this->edit, 0, -1)));
        }
        $nice = $this->value();

        return match (true) {
            in_array($key, ['up', 'k'], true) => OverlayResult::keep($this->with($nice + 1 > 19 ? -20 : $nice + 1, '')),
            in_array($key, ['down', 'j'], true) => OverlayResult::keep($this->with($nice - 1 < -20 ? 19 : $nice - 1, '')),
            in_array($key, ['left', 'h'], true) => OverlayResult::keep($this->with($nice - 5 < -20 ? $nice + 35 : $nice - 5, '')),
            in_array($key, ['right', 'l'], true) => OverlayResult::keep($this->with($nice + 5 > 19 ? $nice - 35 : $nice + 5, '')),
            default => OverlayResult::keep($this),
        };
    }

    /** `$edit` typed, the stepped value re-parsed from it unless it is empty. */
    private function edited(string $edit): self
    {
        $typed = $this->with($this->nice, $edit);

        return $edit === '' ? $typed : $this->with($typed->value(), $edit);
    }

    private function with(int $nice, string $edit): self
    {
        return new self($this->pid, $this->name, $this->control, $nice, $edit);
    }

    public function paint(Surface $surface, OverlayContext $c): void
    {
        $ink = $c->ink;
        [$x, $y] = self::origin($c->cols, $c->rows);
        $bold = MenuDraw::BOLD;
        $main = $ink->fg('main_fg');
        $hi = $ink->fg('hi_fg');
        MenuDraw::box($surface, $c, $x + 2, $y, 50, 13, Lang::t('overlay.title.renice'));
        $clip = MenuDraw::inside($x + 2, $y, 50, 13);
        MenuDraw::at(
            $surface,
            $y + 2,
            $x + 3,
            $ink->fg('title') . $bold . MenuDraw::cjust(Lang::t('overlay.renice.prompt', ['pid' => $this->pid, 'name' => MenuDraw::cut(MenuDraw::clean($this->name), 15)]), 48),
            '',
            $clip,
        );
        MenuDraw::at(
            $surface,
            $y + 4,
            $x + 3,
            $main . MenuDraw::UNBOLD . MenuDraw::rjust(Lang::t('overlay.renice.enter'), 30) . $hi
                . ($this->edit === '' ? (string) $this->nice : $this->edit) . $main . MenuDraw::BLINK . '█' . MenuDraw::UNBLINK,
            '',
            $clip,
        );
        $hints = [
            ['↑ ↓', 'overlay.renice.hint.step'],
            ['← →', 'overlay.renice.hint.step5'],
            ['0-9', 'overlay.signal.hint.manual'],
            ['ENTER', 'overlay.renice.hint.set'],
            [Lang::t('overlay.hint.escape_short'), 'overlay.signal.hint.abort'],
        ];
        foreach ($hints as $i => [$keys, $text]) {
            MenuDraw::at($surface, $y + 7 + $i, $x + 3, $bold . $hi . MenuDraw::rjust($keys, 20) . $main . MenuDraw::UNBOLD . ' | ' . Lang::t($text), '', $clip);
        }
    }
}
