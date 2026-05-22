<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Internal sentinel produced by {@see Cmd::sequence()}. The Program
 * unpacks the inner Cmds and runs them strictly in order — each
 * Cmd's Msg is dispatched (and processed by `update()`) before the
 * next Cmd starts. Contrast with {@see BatchMsg} which runs Cmds
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
