<?php

declare(strict_types=1);

namespace SugarCraft\Top;

/**
 * Package root for candy-top, the terminal system monitor.
 *
 * Mirrors aristocratos/btop concept — a btop-style TUI dashboard for CPU,
 * memory, network, disk, GPU, container, VM and process telemetry over the
 * SugarCraft TUI libraries. The running program is {@see App} (launched by
 * `bin/candy-top`); this class keeps the package-level defaults only.
 */
final class Top
{
    /**
     * Default data refresh interval in milliseconds — btop's `update_ms`
     * default (2000), the same value {@see Config\Schema} ships.
     */
    public const DEFAULT_TICK_MS = 2000;

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
