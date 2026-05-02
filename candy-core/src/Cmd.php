<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Msg\QuitMsg;

/**
 * Helper Cmd factories. A Cmd is `Closure(): ?Msg` — an asynchronously
 * executed piece of work whose returned Msg (if any) is fed back into
 * the program loop.
 */
final class Cmd
{
    /** Quit the program when executed. */
    public static function quit(): \Closure
    {
        return static fn(): Msg => new QuitMsg();
    }

    /**
     * Combine several Cmds into one. The runtime executes them concurrently;
     * each returned Msg is dispatched independently. `null` entries are
     * silently dropped so callers can write `Cmd::batch($maybeCmd, $other)`.
     */
    public static function batch(?\Closure ...$cmds): \Closure
    {
        /** @var list<\Closure> $filtered */
        $filtered = array_values(array_filter($cmds, static fn($c) => $c !== null));
        return static function () use ($filtered): ?Msg {
            // The Program inspects this sentinel and explodes it into
            // separate dispatches. See Program::runCmd().
            return new BatchMsg($filtered);
        };
    }

    /**
     * Wrap a raw Msg in a Cmd so it can be returned from update() chains
     * that need to inject a synchronous follow-up event.
     */
    public static function send(Msg $msg): \Closure
    {
        return static fn(): Msg => $msg;
    }

    private function __construct() {}
}
