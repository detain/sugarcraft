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

    /**
     * Schedule $produce to run after $seconds elapses on the loop. Whatever
     * Msg it returns is dispatched into update(); return null to skip the
     * dispatch.
     *
     * @param \Closure():?Msg $produce
     */
    public static function tick(float $seconds, \Closure $produce): \Closure
    {
        return static fn(): Msg => new TickRequest($seconds, $produce);
    }

    /**
     * Write raw escape-sequence bytes to the Program's output stream.
     * The renderer's diff state is *not* invalidated, so callers should
     * use this for cursor-area-neutral effects (window title, OSC
     * inline images, etc.) and call `Renderer::reset()` themselves if
     * they intentionally clobber the painted region.
     *
     * Mirrors Bubble Tea v2's `tea.Raw`.
     */
    public static function raw(string $bytes): \Closure
    {
        return static fn(): Msg => new RawMsg($bytes);
    }

    /**
     * Print a line above the Program's region. Convenient for non-
     * alt-screen "inline" programs that want to log progress without
     * disturbing their rendered view. Mirrors `tea.Println`.
     */
    public static function println(string $text): \Closure
    {
        return static fn(): Msg => new PrintMsg($text);
    }

    private function __construct() {}
}
