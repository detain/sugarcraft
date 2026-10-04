<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * What a {@see PruneEntry} does to the tool result it names when the
 * {@see ContextProjector} builds a request (roadmap 2.2-1, DCP §13.2 B).
 *
 * Only the output placeholder ships with the age rule; the distilled form and
 * the input rewrites arrive with the strategies and the `Prune` tool that
 * produce them (2.3, 3.B-3), as further cases here.
 */
enum PruneKind: string
{
    /**
     * The result's content is replaced by a one-line placeholder naming the
     * tool and its main argument ({@see PrunedOutputPlaceholder}).
     */
    case Output = 'output';
}
