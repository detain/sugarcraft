<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

/**
 * Process-global holding pen for live shell tails (C-TOOL-3.a).
 *
 * NOT YET WIRED: as of this commit the store is built and tested but has no
 * production callers. C-TOOL-3.b wires it: a ToolProgress frame will land here
 * from the turn's event fold instead of the transcript, and the Renderer's
 * running-tool row will peek the slot on every paint, so the progressive tail
 * replaces itself in place - single-slot, never append. (Today the ToolProgress
 * folds only recordEvent, per the in-tree "paints no live tail this wave" note;
 * the mirror PreviewSlots is already wired at Chat.php - this class is not.)
 *
 * OUT-OF-ORDER FRAMES ARE DROPPED. Each frame carries the call's sequence
 * number from 1, and the slot keeps only the newest sequence it has seen: a
 * frame that arrives late (or belongs to an earlier write of a recycled id)
 * can never repaint the row backwards.
 *
 * LEAK LAW (for C-TOOL-3.b's wiring): every slot is cleared when its tool call
 * settles (replaceToolRunningPlaceholder) and on session resets. Nothing in
 * here is ever serialized, persisted, or replayed from the session store; the
 * bytes exist only as long as the render is in flight.
 */
final class ToolTailSlots
{
    /**
     * Leak-law backstop (C-TOOL-3.a, mirroring PreviewSlots' W2.4 cap): the
     * settle-clear C-TOOL-3.b will wire empties slots as calls finish; the cap
     * itself holds regardless, so a frame for an abandoned call (a turn that
     * died mid-render) can never accumulate - the map holds at most this many
     * newest slots.
     */
    private const MAX_SLOTS = 8;

    /** @var array<string, array{tail: string, truncated: bool, seq: int}> */
    private static array $slots = [];

    private function __construct()
    {
    }

    public static function set(string $toolCallId, string $tail, bool $truncated, int $seq): void
    {
        if ($toolCallId === '') {
            return;
        }
        $current = self::$slots[$toolCallId] ?? null;
        if ($current !== null && $current['seq'] >= $seq) {
            return;
        }
        self::$slots[$toolCallId] = ['tail' => $tail, 'truncated' => $truncated, 'seq' => $seq];
        if (\count(self::$slots) > self::MAX_SLOTS) {
            array_shift(self::$slots);
        }
    }

    /**
     * @return ?array{tail: string, truncated: bool, seq: int}
     */
    public static function peek(string $toolCallId): ?array
    {
        return self::$slots[$toolCallId] ?? null;
    }

    public static function clear(string $toolCallId): void
    {
        unset(self::$slots[$toolCallId]);
    }

    public static function clearAll(): void
    {
        self::$slots = [];
    }

    public static function count(): int
    {
        return count(self::$slots);
    }
}
