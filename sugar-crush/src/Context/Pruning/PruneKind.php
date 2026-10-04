<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * What a {@see PruneEntry} does to the tool result it names when the
 * {@see ContextProjector} builds a request (roadmap 2.2-1, DCP §13.2 B).
 *
 * The output placeholder shipped with the age rule; the input rewrites arrive
 * with the path-keyed strategies (2.3) and act on the ASSISTANT row's call,
 * leaving the result as it is. The distilled form is the model's own `Prune`
 * call with a distillation (3.B-3).
 */
enum PruneKind: string
{
    /**
     * The result's content is replaced by a one-line placeholder naming the
     * tool and its main argument ({@see PrunedOutputPlaceholder}).
     */
    case Output = 'output';

    /**
     * The call's `content` argument is elided ({@see PrunedInputPlaceholder}):
     * a later write or read of the same file supersedes what this write sent.
     */
    case WriteContent = 'write-content';

    /**
     * The call's arguments are blanked but for its main one
     * ({@see PrunedInputPlaceholder}): the call failed long enough ago that
     * what it tried matters less than that it failed.
     */
    case Input = 'input';

    /**
     * The result's content is replaced by the text the model wrote for it
     * ({@see PruneEntry::$distillation}) under a one-line header naming the
     * tool and its main argument ({@see PrunedOutputPlaceholder::distilled()}).
     */
    case Distilled = 'distilled';

    /** Whether this kind rewrites the call's arguments rather than its result. */
    public function rewritesInput(): bool
    {
        return $this === self::WriteContent || $this === self::Input;
    }
}
