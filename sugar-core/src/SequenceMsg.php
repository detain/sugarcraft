<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Internal sentinel produced by {@see Cmd::sequence()}. The Program
 * unpacks the inner Cmds and runs them strictly in order, one Cmd per
 * loop tick — each Cmd's Msg is dispatched (and processed by
 * `update()`) before the next Cmd starts, and the sequence never
 * completes inside a single dispatch. Contrast with {@see BatchMsg} which runs Cmds
 * concurrently.
 *
 * Models never see this directly — the runtime intercepts it during
 * dispatch.
 */
final class SequenceMsg implements Msg
{
    /** @param list<\Closure> $cmds */
    public function __construct(public readonly array $cmds)
    {
    }
}
