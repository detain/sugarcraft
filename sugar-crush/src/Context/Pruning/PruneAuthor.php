<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * Who changed the {@see ContextLedger}: a deterministic strategy, or the
 * harness itself writing a step summary ({@see CompressionBlock}), the
 * person at a command (`/sweep`, roadmap 3.B-2), or the model through its own
 * `Prune` call (roadmap 3.B-3).
 */
enum PruneAuthor: string
{
    /** A {@see PruningStrategy} proposed it. */
    case Strategy = 'strategy';

    /** The engine wrote it at the over-budget emergency (roadmap 2.4-1). */
    case Harness = 'harness';

    /** The person asked for it (`/sweep`). */
    case User = 'user';

    /** The model asked for it, through its `Prune` tool (roadmap 3.B-3). */
    case Model = 'model';
}
