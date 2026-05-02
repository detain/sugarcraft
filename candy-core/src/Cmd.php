<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Msg\QuitMsg;
use CandyCore\Core\Util\Ansi;

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

    /**
     * Ask the terminal where the cursor currently is. The reply will
     * be parsed by the input reader and dispatched as a
     * {@see \CandyCore\Core\Msg\CursorPositionMsg}. Mirrors
     * `tea.RequestCursorPosition`.
     */
    public static function requestCursorPosition(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestCursorPosition());
    }

    /**
     * Ask the terminal for its current default foreground colour. The
     * reply (OSC 10) becomes a {@see \CandyCore\Core\Msg\ForegroundColorMsg}.
     */
    public static function requestForegroundColor(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestForegroundColor());
    }

    /**
     * Ask the terminal for its current default background colour. The
     * reply (OSC 11) becomes a {@see \CandyCore\Core\Msg\BackgroundColorMsg}.
     * Pair with {@see \CandyCore\Core\Msg\BackgroundColorMsg::isDark()}
     * to pick a contrasting theme.
     */
    public static function requestBackgroundColor(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestBackgroundColor());
    }

    /**
     * Ask the terminal for its current cursor colour. The reply
     * (OSC 12) becomes a {@see \CandyCore\Core\Msg\CursorColorMsg}.
     */
    public static function requestCursorColor(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestCursorColor());
    }

    /**
     * Ask the terminal to identify itself (XTVERSION). The DCS reply
     * becomes a {@see \CandyCore\Core\Msg\TerminalVersionMsg} carrying
     * the human-readable terminal-name + version string.
     */
    public static function requestTerminalVersion(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestTerminalVersion());
    }

    /**
     * Ask the terminal whether a given mode is currently set (DECRQM).
     * The reply becomes a {@see \CandyCore\Core\Msg\ModeReportMsg}.
     * Pass `private: true` (default) for DEC private modes, false for
     * ANSI modes.
     */
    public static function requestMode(int $mode, bool $private = true): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestMode($mode, $private));
    }

    private function __construct() {}
}
