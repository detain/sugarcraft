<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use SugarCraft\Vt\Mode\MouseEncoding;

/**
 * Applies DEC private mode set/reset (CSI ?N h / CSI ?N l) to a {@see ScreenHandler}.
 *
 * Recognised modes (and their `Mode` field):
 *
 * - `7`    DECAWM auto-wrap        → `autoWrap`
 * - `25`   cursor visibility       → {@see ScreenHandler::setCursorVisible()} (single write path)
 * - `1001` X10 highlight tracking  → `mouseHighlights`
 * - `1000` X11 mouse (button only) → `mouseAny`
 * - `1002` cell-motion mouse       → `mouseCellMotion`
 * - `1003` any-motion mouse        → `mouseExtended`
 * - `1005` UTF-8 coordinate form   → `mouseEncoding Utf8`
 * - `1006` SGR coordinate form     → `mouseEncoding Sgr`
 * - `1015` URXVT coordinate form   → `mouseEncoding Urxvt`
 *
 * 1005/1006/1015 select HOW reports encode their coordinates and never
 * whether tracking happens at all (xterm ctlseqs "Extended mouse
 * coordinates"), so they write the one shared {@see MouseEncoding} slot —
 * last setter wins, reset falls back to `MouseEncoding::Default`. Historically
 * they were folded onto `mouseHighlights`, conflating DEC 1001 (X10 highlight
 * TRACKING, a genuinely different reporting behaviour) with pure encoders;
 * each mode now owns its own observable state (ESC-1).
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
            25 => $h->setCursorVisible($set),
            1001 => $h->mode = $h->mode->withMouseHighlights($set),
            1000 => $h->mode = $h->mode->withMouseAny($set),
            1002 => $h->mode = $h->mode->withMouseCellMotion($set),
            1003 => $h->mode = $h->mode->withMouseExtended($set),
            1005 => $h->mode = $h->mode->withMouseEncoding($set ? MouseEncoding::Utf8 : MouseEncoding::Default),
            1006 => $h->mode = $h->mode->withMouseEncoding($set ? MouseEncoding::Sgr : MouseEncoding::Default),
            1015 => $h->mode = $h->mode->withMouseEncoding($set ? MouseEncoding::Urxvt : MouseEncoding::Default),
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
}
