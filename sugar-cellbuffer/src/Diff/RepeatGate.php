<?php

declare(strict_types=1);

/**
 * CB-30 façade: RepeatGate moved to src/Support (byte-cost policy is a
 * support concern shared by the encoder and any future REP-aware writer).
 * The old-path file keeps `SugarCraft\CellBuffer\Diff\RepeatGate` alive —
 * PSR-4 autoload lands here and the alias resolves to the canonical
 * Support class, preserving consumer import bytes at sugar-image's
 * OutputOptimizer (its `use ...Diff\RepeatGate;` line).
 *
 * @deprecated use SugarCraft\CellBuffer\Support\RepeatGate
 */

class_alias(
    \SugarCraft\CellBuffer\Support\RepeatGate::class,
    'SugarCraft\CellBuffer\Diff\RepeatGate',
);
