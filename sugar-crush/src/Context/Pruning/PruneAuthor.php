<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * Who changed the {@see ContextLedger}: a deterministic strategy, or the
 * harness itself writing a step summary ({@see CompressionBlock}). The model's
 * own `Prune`/`Compress` calls and the user's commands arrive later (3.B) as
 * further cases.
 */
enum PruneAuthor: string
{
    /** A {@see PruningStrategy} proposed it. */
    case Strategy = 'strategy';

    /** The engine wrote it at the over-budget emergency (roadmap 2.4-1). */
    case Harness = 'harness';
}
