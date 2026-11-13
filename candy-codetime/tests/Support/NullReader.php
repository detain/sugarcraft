<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests\Support;

use SugarCraft\CodeTime\Storage\HeartbeatReader;

/**
 * The two-line in-memory reader for view-layer tests.
 *
 * These tests hand the Dashboard a source and never read through it: they
 * pass prebuilt Stats, or they only pin that navigation preserves model
 * fields across an empty reload. A shared /tmp Store was therefore both
 * pointless and pollutable (finding candy-codetime-074) - stray day files
 * planted by any other process could answer loadRange(). This fake keeps
 * every fixture hermetic and the suite faster for it.
 */
final class NullReader implements HeartbeatReader
{
    /** @return list<\SugarCraft\CodeTime\Heartbeat> */
    public function loadRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [];
    }

    public function invalidate(): void
    {
    }
}
