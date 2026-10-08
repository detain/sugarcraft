<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source;

/**
 * Where a panel's data comes from: one sample per data tick.
 *
 * Same shape as every collector in {@see \SugarCraft\Top\Collect} —
 * `sample()` returns the snapshot AND the source to ask next time, so a
 * delta-based reader (cpu jiffies, net counters) carries its baseline
 * forward without mutating. Panels hold their Source; the App runs the
 * sample inside a Cmd (never in update() or view()) and hands the result
 * back as a {@see \SugarCraft\Top\Msg\SampledMsg}.
 *
 * Real collectors plug in via {@see CollectorSource}; {@see Fake} sources
 * produce deterministic snapshots for tests, demos and VHS tapes.
 */
interface Source
{
    /** @return array{0: object, 1: Source} [snapshot, next source] */
    public function sample(): array;
}
