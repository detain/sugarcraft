<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

/**
 * Applies DEC private mode set/reset (CSI ?N h / CSI ?N l) to a {@see ScreenHandler}.
 *
 * Recognised modes (and their `Mode` field):
 *
 * - `7`    DECAWM auto-wrap        → `autoWrap`
 * - `25`   cursor visibility       → `cursorVisible`
 * - `1001` X10 mouse (button only) → `mouseHighlights`
 * - `1000` X11 mouse (button only) → `mouseAny`
 * - `1002` cell-motion mouse       → `mouseCellMotion`
 * - `1003` any-motion mouse        → `mouseExtended`
 * - `1005` highlight reporting     → `mouseHighlights`
 * - `1006` SGR mouse coordinates   → `mouseSgr`
 * - `1015` URXVT mouse encoding    → `mouseHighlights`
 * - `47`   alt-screen (no save)    → `altScreenVariant ALT_NO_SAVE`, swaps Buffer only
 * - `1047` alt-screen (no save)    → `altScreenVariant ALT_NO_SAVE`, swaps Buffer only
 * - `1048` alt-screen (cursor save) → `altScreenVariant ALT_CURSOR_ONLY`, saves cursor only
 * - `1049` alt-screen + full save  → `altScreenVariant ALT_FULL`, swaps Buffer + saves cursor/sgr
 * - `2004` bracketed paste         → `bracketedPaste`
 * - `2026` synchronized output     → `syncUpdate`
 *
 * Other DEC modes are ignored. Standard (non-private, no `?` prefix) modes
 * are not handled — the parser routes them through the same `h`/`l`
 * dispatch but `ScreenHandler` only forwards the private-prefixed form.
 */
final class ModeHandler
{
    /**
     * @param list<int> $params
     */
    public function apply(array $params, bool $set, ScreenHandler $handler): void
    {
        foreach ($params as $p) {
            if ($p <= 0) {
                continue;
            }
            $this->applyOne($p, $set, $handler);
        }
    }

    private function applyOne(int $mode, bool $set, ScreenHandler $h): void
    {
        match ($mode) {
            6 => $h->mode = $h->mode->withOriginMode($set),
            7 => $h->mode = $h->mode->withAutoWrap($set),
            25 => $this->setCursorVisible($set, $h),
            1001 => $h->mode = $h->mode->withMouseHighlights($set),
            1000 => $h->mode = $h->mode->withMouseAny($set),
            1002 => $h->mode = $h->mode->withMouseCellMotion($set),
            1003 => $h->mode = $h->mode->withMouseExtended($set),
            1005 => $h->mode = $h->mode->withMouseHighlights($set),
            1006 => $h->mode = $h->mode->withMouseSgr($set),
            1015 => $h->mode = $h->mode->withMouseHighlights($set),
            1004 => $h->mode = $h->mode->withReportFocusEvents($set),
            47 => $set ? $h->enterAltScreenNoSave() : $h->leaveAltScreenNoSave(),
            1047 => $set ? $h->enterAltScreenNoSave() : $h->leaveAltScreenNoSave(),
            1048 => $set ? $h->enterAltScreenCursorOnly() : $h->leaveAltScreenCursorOnly(),
            1049 => $set ? $h->enterAltScreen() : $h->leaveAltScreen(),
            2004 => $h->mode = $h->mode->withBracketedPaste($set),
            2026 => $h->mode = $h->mode->withSyncUpdate($set),
            default => null,
        };
    }

    private function setCursorVisible(bool $set, ScreenHandler $h): void
    {
        $h->mode = $h->mode->withCursorVisible($set);
        $h->cursor = $h->cursor->withVisible($set);
    }
}
