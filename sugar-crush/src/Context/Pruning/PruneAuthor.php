<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * Who changed the {@see ContextLedger}: a deterministic strategy, or the
 * harness itself writing a step summary ({@see CompressionBlock}), or the
 * person at a command (`/sweep`, roadmap 3.B-2). The model's own
 * `Prune`/`Compress` calls arrive later (3.B-3/3.B-4) as a further case.
 */
enum PruneAuthor: string
{
    /** A {@see PruningStrategy} proposed it. */
    case Strategy = 'strategy';

    /** The engine wrote it at the over-budget emergency (roadmap 2.4-1). */
    case Harness = 'harness';

    /** The person asked for it (`/sweep`). */
    case User = 'user';
}
