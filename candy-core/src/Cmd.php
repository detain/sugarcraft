<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\Msg\InterruptMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\SuspendMsg;
use SugarCraft\Core\Util\Ansi;

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
     * Suspend the program (Ctrl-Z / SIGTSTP semantics). Tears down
     * the terminal, re-raises SIGTSTP on the process group, and on
     * SIGCONT restores the terminal and dispatches a `ResumeMsg`.
     * Mirrors Bubble Tea's `tea.Suspend`.
     */
    public static function suspend(): \Closure
    {
        return static fn(): Msg => new SuspendMsg();
    }

    /**
     * Quit cleanly with SIGINT-style "interrupt" semantics — same
     * teardown path as Ctrl-C but distinguishable from a graceful
     * `quit()` for downstream tooling. Mirrors `tea.Interrupt`.
     */
    public static function interrupt(): \Closure
    {
        return static fn(): Msg => new InterruptMsg();
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
        return static function () use ($filtered): Msg {
            // The Program inspects this sentinel and explodes it into
            // separate dispatches. See Program::runCmd().
            return new BatchMsg($filtered);
        };
    }

    /**
     * Run Cmds strictly in order: each returned Msg is dispatched (and
     * processed by the model's `update()`) before the next Cmd starts.
     * Use this when ordering matters — a `setForegroundColor()` should
     * land before the colour-dependent render that follows it.
     *
     * Mirrors Bubble Tea's `tea.Sequence`.
     */
    public static function sequence(?\Closure ...$cmds): \Closure
    {
        /** @var list<\Closure> $filtered */
        $filtered = array_values(array_filter($cmds, static fn($c) => $c !== null));
        return static fn(): Msg => new SequenceMsg($filtered);
    }

    /**
     * Wall-clock-aligned periodic tick. Unlike {@see tick()} which
     * uses an independent clock per Cmd, `every($d)` aligns to wall-
     * clock multiples of `$d` seconds — multiple `every($d, ...)`
     * Cmds with the same period share a single tick. Useful for
     * synchronised animations across components.
     *
     * @param \Closure(\DateTimeImmutable): ?Msg $produce
     */
    public static function every(float $seconds, \Closure $produce): \Closure
    {
        return static function () use ($seconds, $produce): Msg {
            // Compute the delay until the next wall-clock alignment.
            $now = microtime(true);
            $period = max(1e-3, $seconds);
            $next = ceil($now / $period) * $period;
            $delay = max(0.0, $next - $now);
            $deliver = static function () use ($produce): ?Msg {
                /** @var \DateTimeImmutable $now */
                $now = (new \DateTimeImmutable())->setTimestamp((int) microtime(true));
                return $produce($now);
            };
            return new TickRequest($delay, $deliver);
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
     * Wrap an async operation in a Cmd. The $factory should return a
     * React\Promise\PromiseInterface<?Msg>. The promise's resolved Msg (if any) will
     * be dispatched to update(). Rejected promises dispatch ExceptionMsg.
     *
     * @param \Closure(): \React\Promise\PromiseInterface<?Msg> $factory
     * @return \Closure(): AsyncCmd
     */
    public static function promise(\Closure $factory): \Closure
    {
        return static fn(): AsyncCmd => new AsyncCmd($factory());
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
     * `printf`-style companion to {@see println()} — formats `$fmt`
     * with `$args` and prints the result above the program region.
     * Mirrors `tea.Printf`.
     */
    public static function printf(string $fmt, mixed ...$args): \Closure
    {
        $text = sprintf($fmt, ...$args);
        return static fn(): Msg => new PrintMsg($text);
    }

    /**
     * Run an external command with the TTY released, then resume the
     * program. The runtime tears down raw mode + alt screen + cursor
     * hide before launching the child, and restores them once it
     * exits. The result is dispatched to the model as
     * {@see \SugarCraft\Core\Msg\ExecMsg}.
     *
     * `$command` may be a string (executed via the shell) or an argv
     * list (no shell, safer when arguments come from user input).
     * `$captureOutput=true` collects stdout / stderr; pass `false`
     * (default) to inherit the parent TTY so the user can interact
     * with `vi`, `less`, etc.
     *
     * `$onComplete` (optional) receives `(int $exit, string $out,
     * string $err, ?\Throwable $error)` and may return a Msg to
     * dispatch in place of (or after) the default ExecMsg.
     *
     * @param string|list<string> $command
     * @param ?\Closure(int, string, string, ?\Throwable): ?Msg $onComplete
     */
    public static function exec(
        string|array $command,
        bool $captureOutput = false,
        ?\Closure $onComplete = null,
    ): \Closure {
        return static fn(): Msg => new ExecRequest($command, $captureOutput, $onComplete);
    }

    /**
     * Ask the terminal where the cursor currently is. The reply will
     * be parsed by the input reader and dispatched as a
     * {@see \SugarCraft\Core\Msg\CursorPositionMsg}. Mirrors
     * `tea.RequestCursorPosition`.
     */
    public static function requestCursorPosition(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestCursorPosition());
    }

    /**
     * Ask the terminal for its current default foreground colour. The
     * reply (OSC 10) becomes a {@see \SugarCraft\Core\Msg\ForegroundColorMsg}.
     */
    public static function requestForegroundColor(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestForegroundColor());
    }

    /**
     * Ask the terminal for its current default background colour. The
     * reply (OSC 11) becomes a {@see \SugarCraft\Core\Msg\BackgroundColorMsg}.
     * Pair with {@see \SugarCraft\Core\Msg\BackgroundColorMsg::isDark()}
     * to pick a contrasting theme.
     */
    public static function requestBackgroundColor(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestBackgroundColor());
    }

    /**
     * Ask the terminal for its current cursor colour. The reply
     * (OSC 12) becomes a {@see \SugarCraft\Core\Msg\CursorColorMsg}.
     */
    public static function requestCursorColor(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestCursorColor());
    }

    /**
     * Ask the terminal to identify itself (XTVERSION). The DCS reply
     * becomes a {@see \SugarCraft\Core\Msg\TerminalVersionMsg} carrying
     * the human-readable terminal-name + version string.
     */
    public static function requestTerminalVersion(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestTerminalVersion());
    }

    /**
     * Ask the terminal whether a given mode is currently set (DECRQM).
     * The reply becomes a {@see \SugarCraft\Core\Msg\ModeReportMsg}.
     * Pass `private: true` (default) for DEC private modes, false for
     * ANSI modes.
     */
    public static function requestMode(int $mode, bool $private = true): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestMode($mode, $private));
    }

    /**
     * Copy `$text` to the terminal's clipboard via OSC 52. Default
     * selection is the system clipboard (`c`); pass `p` for X11
     * primary or `s` for secondary.
     */
    public static function setClipboard(string $text, string $selection = 'c'): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::setClipboard($text, $selection));
    }

    /**
     * Ask the terminal for the contents of the named selection. The
     * reply (OSC 52) becomes a {@see \SugarCraft\Core\Msg\ClipboardMsg}
     * with the decoded text.
     */
    public static function readClipboard(string $selection = 'c'): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::readClipboard($selection));
    }

    /**
     * Set the terminal window title via OSC 2. Pass `$icon: true` to
     * emit OSC 0 instead, which sets icon name + window title in
     * terminals that distinguish them (xterm, iTerm2).
     */
    public static function setWindowTitle(string $title, bool $icon = false): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::setWindowTitle($title, $icon));
    }

    /**
     * Tell the terminal the shell's current working directory via
     * OSC 7. Used by iTerm2 / Terminal.app / WezTerm for new-tab and
     * split-pane "same cwd" semantics.
     */
    public static function setWorkingDirectory(string $path, string $host = ''): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::setWorkingDirectory($path, $host));
    }

    /**
     * Set the terminal's taskbar-progress indicator (OSC 9;4). Pass
     * {@see ProgressBarState::Remove} to clear it. `$percent` is
     * clamped to 0-100 and only meaningful for Normal / Error /
     * Warning states.
     */
    public static function setProgressBar(ProgressBarState $state, int $percent = 0): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::setProgressBar($state, $percent));
    }

    /**
     * Push a Kitty progressive-keyboard flag layer. Use the bit
     * constants on `Msg\KeyboardEnhancementsMsg` (DISAMBIGUATE,
     * REPORT_EVENT_TYPES, REPORT_ALTERNATES, REPORT_ALL_AS_ESC,
     * REPORT_ASSOCIATED). Pair with {@see popKittyKeyboard()} on
     * teardown.
     */
    public static function pushKittyKeyboard(int $flags): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::pushKittyKeyboard($flags));
    }

    /** Pop `$n` Kitty keyboard flag layers. */
    public static function popKittyKeyboard(int $n = 1): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::popKittyKeyboard($n));
    }

    /**
     * Ask the terminal for the currently active Kitty keyboard
     * flags. The reply becomes a
     * {@see \SugarCraft\Core\Msg\KeyboardEnhancementsMsg}.
     */
    public static function requestKittyKeyboard(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::requestKittyKeyboard());
    }

    // ─── Stateful Cmd helpers ──────────────────────────────────────────
    //
    // Each of these emits an Ansi escape via RawMsg without touching the
    // renderer's diff state. Use them for one-shot terminal state
    // transitions outside the View-driven side-channel (which is the
    // preferred path for per-frame state).

    /** Switch into the alt screen (`CSI ?1049h`). */
    public static function enterAltScreen(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::altScreenEnter());
    }

    /** Leave the alt screen (`CSI ?1049l`). */
    public static function exitAltScreen(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::altScreenLeave());
    }

    /** Erase the entire screen and home the cursor. */
    public static function clearScreen(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::eraseScreen() . Ansi::cursorTo(1, 1));
    }

    /** Show the cursor (`CSI ?25h`). */
    public static function showCursor(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::cursorShow());
    }

    /** Hide the cursor (`CSI ?25l`). */
    public static function hideCursor(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::cursorHide());
    }

    /** Enable cell-motion mouse tracking (button-only motion + SGR encoding). */
    public static function enableMouseCellMotion(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::mouseCellMotionOn());
    }

    /** Enable all-motion mouse tracking (every move regardless of button state). */
    public static function enableMouseAllMotion(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::mouseAllMotionOn());
    }

    /**
     * Disable mouse tracking — clears both cell-motion and all-motion
     * modes. Safe to call when no tracking is active.
     */
    public static function disableMouse(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::mouseAllMotionOff() . Ansi::mouseCellMotionOff());
    }

    /** Enable focus-in/out reporting (`CSI ?1004h`). */
    public static function enableReportFocus(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::focusReportingOn());
    }

    public static function disableReportFocus(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::focusReportingOff());
    }

    /** Enable bracketed paste mode (`CSI ?2004h`). */
    public static function enableBracketedPaste(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::bracketedPasteOn());
    }

    public static function disableBracketedPaste(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::bracketedPasteOff());
    }

    /** Scroll the active region up `$n` lines. */
    public static function scrollUp(int $n = 1): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::scrollUp($n));
    }

    /** Scroll the active region down `$n` lines. */
    public static function scrollDown(int $n = 1): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::scrollDown($n));
    }

    /**
     * Set the terminal's default foreground colour (OSC 10). Persists
     * for the rest of the program — terminals don't auto-restore on
     * exit. Pair with {@see resetForegroundColor()} on teardown if
     * you need to roll back.
     */
    public static function setForegroundColor(int $r, int $g, int $b): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::setForegroundColor($r, $g, $b));
    }

    public static function setBackgroundColor(int $r, int $g, int $b): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::setBackgroundColor($r, $g, $b));
    }

    /**
     * Reset the terminal's default foreground to whatever the user's
     * profile dictates (`OSC 110`).
     */
    public static function resetForegroundColor(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::OSC . '110' . Ansi::ST);
    }

    public static function resetBackgroundColor(): \Closure
    {
        return static fn(): Msg => new RawMsg(Ansi::OSC . '111' . Ansi::ST);
    }

    private function __construct() {}
}
