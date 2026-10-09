<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

/**
 * Process-global holding pen for live-render preview frames (W2.4).
 *
 * A MediaProgress frame lands here from the pump instead of the transcript:
 * the Renderer's running-tool row peeks the slot on every paint, so the
 * progressive preview replaces itself in place - single-slot, never append.
 *
 * LEAK LAW: every slot is cleared when its tool call settles
 * (replaceToolRunningPlaceholder) and on session resets. Nothing in here is
 * ever serialized, persisted, or replayed from the session store; the bytes
 * exist only as long as the render is in flight.
 */
final class PreviewSlots
{
    /**
     * Leak-law backstop (W2.4): settle clears each slot, and a frame for an
     * abandoned call (a turn that died mid-render) can never accumulate -
     * the map holds at most this many newest slots.
     */
    private const MAX_SLOTS = 8;

    /** @var array<string, array{frameB64: ?string, progress: ?float, eta: ?float}> */
    private static array $slots = [];

    private function __construct()
    {
    }

    public static function set(string $toolCallId, ?string $frameB64, ?float $progress, ?float $eta): void
    {
        if ($toolCallId === '') {
            return;
        }
        self::$slots[$toolCallId] = ['frameB64' => $frameB64, 'progress' => $progress, 'eta' => $eta];
        if (\count(self::$slots) > self::MAX_SLOTS) {
            array_shift(self::$slots);
        }
    }

    /**
     * @return ?array{frameB64: ?string, progress: ?float, eta: ?float}
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
