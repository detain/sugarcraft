<?php

declare(strict_types=1);

namespace SugarCraft\Top;

/**
 * Placeholder root for the candy-top terminal system monitor.
 *
 * Mirrors aristocratos/btop concept — a btop-style TUI dashboard for
 * CPU, memory, network, disk, and process telemetry, to be built on the
 * SugarCraft charting libraries. This class is the scaffold entry point;
 * the real Model/Cmd architecture arrives with the first implementation
 * phase (see plan_top.md in the SugarCraft monorepo).
 */
final class Top
{
    /**
     * Default refresh interval in milliseconds, echoing btop's 1s tick.
     */
    public const DEFAULT_TICK_MS = 1000;

    private function __construct(
        public readonly int $tickMs,
    ) {
    }

    /**
     * Factory mirroring upstream default construction.
     */
    public static function new(int $tickMs = self::DEFAULT_TICK_MS): self
    {
        return new self(max(1, $tickMs));
    }
}
