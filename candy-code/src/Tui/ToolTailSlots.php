<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

/**
 * Process-global holding pen for live shell tails (C-TOOL-3.a).
 *
 * WIRED (C-TOOL-3.b): a ToolProgress frame lands here from the turn's event
 * fold - both the live pump arm and the settled-queue twin - instead of the
 * transcript, and the Renderer's running-tool row peeks the slot on every
 * paint, so the progressive tail replaces itself in place - single-slot,
 * never append. (The mirror PreviewSlots is wired at Chat.php the same way;
 * replaceToolRunningPlaceholder now clears this store beside that one.)
 *
 * OUT-OF-ORDER FRAMES ARE DROPPED. Each frame carries the call's sequence
 * number from 1, and the slot keeps only the newest sequence it has seen: a
 * frame that arrives late (or belongs to an earlier write of a recycled id)
 * can never repaint the row backwards.
 *
 * LEAK LAW: every slot is cleared when its tool call settles
 * (replaceToolRunningPlaceholder); the MAX_SLOTS cap bounds anything else.
 * Nothing in here is ever serialized, persisted, or replayed from the
 * session store; the bytes exist only as long as the render is in flight.
 */
final class ToolTailSlots
{
    /**
     * Leak-law backstop (C-TOOL-3.a, mirroring PreviewSlots' W2.4 cap): the
     * settle-clear wired in C-TOOL-3.b empties slots as calls finish; the cap
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
