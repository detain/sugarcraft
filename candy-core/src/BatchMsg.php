<?php

declare(strict_types=1);

namespace CandyCore\Core;

/**
 * Internal marker the Program uses to fan a {@see Cmd::batch()} out into
 * individual Cmd executions. Not part of the public Msg surface.
 *
 * @internal
 */
final class BatchMsg implements Msg
{
    /** @param list<\Closure> $cmds */
    public function __construct(public readonly array $cmds) {}
}
