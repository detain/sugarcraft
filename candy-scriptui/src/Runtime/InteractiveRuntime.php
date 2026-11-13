<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\Tty;
use SugarCraft\ScriptUi\Lang;

/**
 * Single launch chokepoint for every interactive {@see \SugarCraft\Core\Program}
 * run in this CLI. It owns the two pipeline contract halves gum upholds:
 *
 * 1. KEYS ARE NEVER PIPED DATA (CC1). Before this class, commands bound the
 *    Program to raw STDIN even when stdin was a drained pipe — so `ls |
 *    candy-scriptui filter` deadlocked (EOF'd handle re-pollled forever under
 *    the framerate timer) and `printf 'yes\n' | confirm` FABRICATED an answer
 *    from piped bytes. Now: when stdout is a tty we pass `openTty: true` so
 *    sugar-core rebinds keys to /dev/tty while the pipe stays data-only; when
 *    stdout itself is redirected we take the read side of /dev/tty explicitly;
 *    when no controlling terminal exists at all we refuse (null) exactly like
 *    gum does instead of hanging.
 *
 * 2. UI NEVER POLLUTES THE DATA CHANNEL (CC2). With a redirected stdout the
 *    alt-screen/SGR frame must not land on the captured stream: painting is
 *    routed to STDERR (gum's own posture) while the answer/result keeps
 *    stdout exclusively. On a real terminal both streams are the same
 *    device, so behavior is unchanged for interactive users.
 */
final class InteractiveRuntime
{
    /** @var bool One-time latch for the {@see signalNotice()} warning (scriptui-07). */
    private static bool $pcntlWarned = false;
    /**
     * Program options for an interactive run, or null when NO usable input
     * terminal exists (pipes on both ends and no /dev/tty) — callers must
     * then refuse with a diagnostic on stderr rather than start a Program
     * that can only deadlock or consume piped bytes as keystrokes.
     */
    public static function programOptions(bool $useAltScreen, bool $hideCursor): ?ProgramOptions
    {
        // Dumb terminals cannot survive the DEC-private modes the renderer
        // emits for alt-screen and cursor hiding (ColourGate already lowers
        // COLOUR for TERM=dumb; this is the same posture for the MODES).
        // Inline mode re-renders in place instead, which every terminal —
        // including `dumb` — can live with.
        $dumb = getenv('TERM') === 'dumb';
        if ($dumb) {
            $useAltScreen = false;
            $hideCursor   = false;
        }

        if (self::stdoutIsTerminal()) {
            // Keys via /dev/tty when stdin is a pipe; sugar-core falls back
            // to STDIN/STDOUT itself when /dev/tty is unavailable.
            return self::warnIfPcntlAbsent(new ProgramOptions(
                useAltScreen:    $useAltScreen,
                hideCursor:      $hideCursor,
                inlineMode:      $dumb,
                catchInterrupts: true,
                openTty:         true,
                loop:            \React\EventLoop\Loop::get(),
            ));
        }

        $paint = self::paintStream();
        $tty   = Tty::openTty();
        if ($tty !== null) {
            // Only the READ side comes from /dev/tty: the write side must be
            // the user's stderr, never the archive-captured stdout.
            return self::warnIfPcntlAbsent(new ProgramOptions(
                useAltScreen:    $useAltScreen,
                hideCursor:      $hideCursor,
                inlineMode:      $dumb,
                catchInterrupts: true,
                input:           $tty[0],
                output:          $paint,
                loop:            \React\EventLoop\Loop::get(),
            ));
        }

        if (self::stdinIsTerminal()) {
            // stdout redirected but stdin is the terminal — still viable:
            // keys from STDIN, frame to STDERR.
            return self::warnIfPcntlAbsent(new ProgramOptions(
                useAltScreen:    $useAltScreen,
                hideCursor:      $hideCursor,
                inlineMode:      $dumb,
                catchInterrupts: true,
                output:          $paint,
                loop:            \React\EventLoop\Loop::get(),
            ));
        }

        return null;
    }

    /**
     * scriptui-07 (candy-scriptui-036): the options above request
     * signal-mapped cancellation (`catchInterrupts: true`), but sugar-core
     * can only install the handler when ext-pcntl is loaded — without it
     * SIGINT/SIGTERM take the default disposition (instant death: no 130/143
     * mapping, no teardown unwind). Surface that once on the diagnostic
     * channel instead of letting the documented promise silently vanish.
     * The returned sentence is the notice to print, or null when there is
     * nothing to say (pcntl present, or already warned this run).
     *
     * @param ?bool $pcntlLoaded Test seam: override the extension probe.
     */
    public static function signalNotice(?bool $pcntlLoaded = null): ?string
    {
        if ($pcntlLoaded ?? extension_loaded('pcntl')) {
            return null;
        }

        if (self::$pcntlWarned) {
            return null;
        }
        self::$pcntlWarned = true;

        return Lang::t('runtime.pcntl_missing');
    }

    /**
     * Print {@see signalNotice()} once to STDERR (never STDOUT — the data
     * channel) and pass the options through.
     */
    private static function warnIfPcntlAbsent(ProgramOptions $options): ProgramOptions
    {
        $notice = self::signalNotice();
        if ($notice !== null) {
            \fwrite(\STDERR, $notice . "\n");
        }

        return $options;
    }

    /**
     * scriptui-06 (candy-scriptui-002/-005): true exactly when
     * {@see programOptions()} would refuse — no stdout terminal, no
     * /dev/tty, no stdin terminal — i.e. the only honest spin shape is
     * the direct-run arm (no key reader, no renderer, no DEC-mode
     * writer). Callers must not paper over this null with a raw-STDIN
     * Program fallback: that is how one 0x1B byte in the child's DATA
     * stream once became a forged ESC cancellation, and how 38 bytes of
     * `?25l/?2027h` chrome once corrupted a redirected data channel.
     */
    public static function isHeadless(): bool
    {
        return self::programOptions(useAltScreen: false, hideCursor: false) === null;
    }

    /**
     * True when the UI frame will be painted somewhere OTHER than stdout —
     * used to route pre-launch chrome (headers, prompts) onto the same
     * stream the Program paints to.
     */
    public static function paintsToStderr(): bool
    {
        return !self::stdoutIsTerminal();
    }

    /**
     * Write UI text (never result data) to the stream the interactive frame
     * belongs on: STDOUT when it is the terminal, STDERR otherwise.
     */
    public static function paint(string $text): void
    {
        fwrite(self::paintStream(), $text);
    }

    /** @return resource */
    private static function paintStream()
    {
        return self::stdoutIsTerminal() ? STDOUT : STDERR;
    }

    public static function stdoutIsTerminal(): bool
    {
        return defined('STDOUT') && is_resource(STDOUT) && @stream_isatty(STDOUT);
    }

    public static function stdinIsTerminal(): bool
    {
        return defined('STDIN') && is_resource(STDIN) && @stream_isatty(STDIN);
    }
}
