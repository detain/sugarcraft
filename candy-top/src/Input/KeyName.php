<?php

declare(strict_types=1);

namespace SugarCraft\Top\Input;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;

/**
 * btop's input vocabulary: the key / mouse-event names `Input::get()`
 * produces (src/btop_input.cpp Key_escapes + the `[<` mouse decoder), so
 * menus and the App match on the same strings btop's handlers compare
 * against — `escape`, `enter`, `page_down`, `shift_tab`, `f1`,
 * `mouse_click`, `mouse_scroll_up`, a plain character as itself.
 *
 * A ctrl/alt chord has no btop name ('' — btop clears multi-byte keys it
 * does not map), except `ctrl+c` handled by the App before anything else.
 */
final class KeyName
{
    private function __construct()
    {
    }

    /** btop's name for `$msg` ('' when btop has none). */
    public static function of(Msg $msg): string
    {
        return match (true) {
            $msg instanceof KeyMsg => self::key($msg),
            $msg instanceof MouseMsg => self::mouse($msg),
            default => '',
        };
    }

    /**
     * btop PR #1476's modified-arrow names (Key_escapes `[1;2D` shift_left,
     * `[1;4D` alt_shift_left, `[1;6D` ctrl_shift_left, the `C` rights and
     * `[1;6B` ctrl_shift_down) — the proc box width keys.
     */
    public const MODIFIED_ARROWS = [
        'shift_left', 'shift_right', 'alt_shift_left', 'alt_shift_right',
        'ctrl_shift_left', 'ctrl_shift_right', 'ctrl_shift_down',
    ];

    public static function key(KeyMsg $msg): string
    {
        $arrow = self::modifiedArrow($msg);
        if ($arrow !== null) {
            return $arrow;
        }
        if ($msg->ctrl || $msg->alt) {
            return '';
        }

        return match ($msg->type) {
            KeyType::Char => $msg->rune,
            KeyType::Up => 'up',
            KeyType::Down => 'down',
            KeyType::Left => 'left',
            KeyType::Right => 'right',
            KeyType::PageUp => 'page_up',
            KeyType::PageDown => 'page_down',
            KeyType::Home => 'home',
            KeyType::End => 'end',
            KeyType::Enter => 'enter',
            KeyType::Escape => 'escape',
            KeyType::Backspace => 'backspace',
            KeyType::Space => 'space',
            KeyType::Delete => 'delete',
            KeyType::Insert => 'insert',
            KeyType::Tab => $msg->shift ? 'shift_tab' : 'tab',
            KeyType::F1 => 'f1',
            KeyType::F2 => 'f2',
            default => '',
        };
    }

    /**
     * True for a modified arrow btop has no name for (shift+up, alt+left,
     * ...): btop's Input::get clears the unknown escape, so the key never
     * reaches a handler or the history.
     */
    public static function dropped(KeyMsg $msg): bool
    {
        return self::modifiedArrow($msg) === '';
    }

    /**
     * A modifier-carrying arrow: its btop name when it has one
     * ({@see MODIFIED_ARROWS}), else '' (btop clears an unmapped
     * multi-byte escape); null for anything that is not a modified arrow.
     */
    private static function modifiedArrow(KeyMsg $msg): ?string
    {
        $dir = match ($msg->type) {
            KeyType::Left => 'left',
            KeyType::Right => 'right',
            KeyType::Up => 'up',
            KeyType::Down => 'down',
            default => null,
        };
        if ($dir === null || (!$msg->shift && !$msg->alt && !$msg->ctrl)) {
            return null;
        }
        $prefix = ($msg->ctrl ? 'ctrl_' : '') . ($msg->alt ? 'alt_' : '') . ($msg->shift ? 'shift_' : '');
        $name = $prefix . $dir;

        return in_array($name, self::MODIFIED_ARROWS, true) ? $name : '';
    }

    /**
     * btop's mouse names: only BARE events have one (`[<0;` click, `[<32;`
     * drag, `[<64;`/`[<65;` wheel, release); right/middle buttons and any
     * modifier map to '' as btop clears them.
     */
    public static function mouse(MouseMsg $msg): string
    {
        if ($msg->shift || $msg->alt || $msg->ctrl) {
            return '';
        }

        return match (true) {
            $msg->action === MouseAction::Release => 'mouse_release',
            $msg->action === MouseAction::Press && $msg->button === MouseButton::Left => 'mouse_click',
            $msg->action === MouseAction::Press && $msg->button === MouseButton::WheelUp => 'mouse_scroll_up',
            $msg->action === MouseAction::Press && $msg->button === MouseButton::WheelDown => 'mouse_scroll_down',
            $msg->action === MouseAction::Motion && $msg->button === MouseButton::Left => 'mouse_drag',
            default => '',
        };
    }

    /**
     * btop Input::get's mapping pass: a `mouse_click` / `mouse_drag` inside
     * one of `$map`'s rectangles becomes that mapping's key; anything else
     * keeps its own name.
     *
     * @param array<string, array{0: int, 1: int, 2: int, 3: int}> $map key => [x, y, width, height], 0-based absolute
     */
    public static function mapped(Msg $msg, array $map): string
    {
        $name = self::of($msg);
        if (!$msg instanceof MouseMsg || ($name !== 'mouse_click' && $name !== 'mouse_drag')) {
            return $name;
        }
        $col = $msg->x - 1;
        $line = $msg->y - 1;
        foreach ($map as $key => [$x, $y, $w, $h]) {
            if ($col >= $x && $col < $x + $w && $line >= $y && $line < $y + $h) {
                return (string) $key;
            }
        }

        return $name;
    }
}
