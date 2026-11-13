<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\Tty;
use SugarCraft\ScriptUi\Lang;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

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
     *
     * scriptui-51 (finding 095): the loop arrives from the caller — the SAME
     * instance the command armed its timeout on. Resolving it a second time
     * here would let a future swap of the shared singleton between the reads
     * arm a deadline on a loop nobody runs: fail-open with zero symptom.
     */
    public static function programOptions(bool $useAltScreen, bool $hideCursor, LoopInterface $loop): ?ProgramOptions
    {
        // Dumb terminals cannot survive the DEC-private modes the renderer
        // emits for alt-screen and cursor hiding (ColourGate already lowers
        // COLOUR for dumb TERM — unset/''/dumb via the shared predicate,
        // scriptui-36; this is the same posture for the MODES). Inline mode
        // re-renders in place instead, which every terminal — dumb included
        // — can live with. Grapheme-cluster mode (DEC 2027) is a DEC-private
        // mode too (finding 050): it rides the same posture through
        // unicodeMode:false. The DEC 2026 frame wrapper lives in
        // sugar-core's Renderer with no option to disable — rider.
        $dumb = ColourGate::termIsDumb();
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
                unicodeMode:     !$dumb,
                catchInterrupts: true,
                openTty:         true,
                loop:            $loop,
            ));
        }

        // scriptui-34 (finding 054): with BOTH streams redirected under a
        // real terminal, /dev/tty restores the KEYS — but the old code still
        // handed the whole frame to paintStream() == the redirected STDERR
        // FILE (a `confirm >out 2>trace.log` probe wrote 62 DEC bytes into
        // the log while the user stared at a blank prompt: corrupted data
        // and invisible UI at once). The frame now goes where a human can
        // actually see it: STDERR only when STDERR itself is the terminal,
        // otherwise the write side of the open /dev/tty handle. With neither
        // available the shape refuses (null) exactly like a headless run —
        // callers already print runtime.no_terminal for that.
        $tty = self::ttyPair();
        if ($tty !== null) {
            // scriptui-70 (finding 091): the pair is FRESH per call, so it
            // is owned here. A STDERR that can paint makes the write side
            // dead on arrival — closed before returning. When STDERR cannot
            // display, the write handle IS the paint output and travels with
            // the options; its lifetime then belongs to whoever runs the
            // Program (the sugar-core close-on-teardown note is the rider).
            // The read side always travels with the options for the same
            // reason.
            if (self::stderrIsTerminal()) {
                $paint = STDERR;
                if (is_resource($tty[1])) {
                    fclose($tty[1]);
                }
            } else {
                $paint = $tty[1];
            }

            return self::warnIfPcntlAbsent(new ProgramOptions(
                useAltScreen:    $useAltScreen,
                hideCursor:      $hideCursor,
                inlineMode:      $dumb,
                unicodeMode:     !$dumb,
                catchInterrupts: true,
                input:           $tty[0],
                output:          $paint,
                loop:            $loop,
            ));
        }

        if (self::stdinIsTerminal() && self::stderrIsTerminal()) {
            // stdout redirected but stdin AND stderr are the terminal —
            // still viable: keys from STDIN, frame to STDERR. When STDERR is
            // a redirected file there is nowhere honest to paint (scriptui-
            // 34): refuse instead of corrupting the capture.
            return self::warnIfPcntlAbsent(new ProgramOptions(
                useAltScreen:    $useAltScreen,
                hideCursor:      $hideCursor,
                inlineMode:      $dumb,
                unicodeMode:     !$dumb,
                catchInterrupts: true,
                output:          STDERR,
                loop:            $loop,
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
        // scriptui-51: this nullness probe runs no Program, so it resolves
        // the singleton once for its own call — no deadline loop can ever
        // disagree with it.
        return self::programOptions(useAltScreen: false, hideCursor: false, loop: Loop::get()) === null;
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
     * belongs on, per the {@see paintStream()} ladder. When nothing can
     * display (every stream redirected, no /dev/tty) the text is dropped —
     * corrupting a captured data file with chrome is worse than silence,
     * and callers refuse the whole run in that shape anyway.
     */
    public static function paint(string $text): void
    {
        $stream = self::paintStream();
        if ($stream === null) {
            return;
        }
        fwrite($stream, $text);
    }

    /**
     * Where the frame belongs: STDOUT when it is the terminal, STDERR when
     * that is the terminal, else the /dev/tty write handle — and null only
     * when no human could ever see the bytes (scriptui-34: the old `: STDERR`
     * fallback fed DEC sequences into redirected capture files).
     *
     * @return resource|null
     */
    private static function paintStream()
    {
        if (self::stdoutIsTerminal()) {
            return STDOUT;
        }
        if (self::stderrIsTerminal()) {
            return STDERR;
        }
        $tty = self::ttyPair();

        return $tty[1] ?? null;
    }

    /**
     * Test seam (scriptui-34): fabricate the terminal-shape census without
     * a real controlling terminal — keys {@see setTerminalProbesForTesting()}
     * to null restores the honest probes.
     *
     * @param array{stdout?:bool,stderr?:bool,stdin?:bool,tty?:mixed}|null $probes
     */
    public static function setTerminalProbesForTesting(?array $probes): void
    {
        self::$probesForTesting = $probes;
    }

    /** @var array<string, mixed>|null */
    private static ?array $probesForTesting = null;

    public static function stdoutIsTerminal(): bool
    {
        if (self::$probesForTesting !== null && array_key_exists('stdout', self::$probesForTesting)) {
            return (bool) self::$probesForTesting['stdout'];
        }

        return defined('STDOUT') && is_resource(STDOUT) && @stream_isatty(STDOUT);
    }

    public static function stderrIsTerminal(): bool
    {
        if (self::$probesForTesting !== null && array_key_exists('stderr', self::$probesForTesting)) {
            return (bool) self::$probesForTesting['stderr'];
        }

        return defined('STDERR') && is_resource(STDERR) && @stream_isatty(STDERR);
    }

    public static function stdinIsTerminal(): bool
    {
        if (self::$probesForTesting !== null && array_key_exists('stdin', self::$probesForTesting)) {
            return (bool) self::$probesForTesting['stdin'];
        }

        return defined('STDIN') && is_resource(STDIN) && @stream_isatty(STDIN);
    }

    /** @var array{0:resource,1:resource}|null */
    private static ?array $ownedTtyPair = null;

    /**
     * The /dev/tty read/write pair, or null when there is no controlling
     * terminal. scriptui-70 (finding 091): exactly one pair is opened per
     * process and reused by every prompt and every paint() — an embedder
     * looping 100 prompts pays one fd, not two hundred. The pair's read
     * side (and the write side whenever it is the paint output) then
     * travels with ProgramOptions; sugar-core closing them at Program
     * teardown is the rider note. The probe seam may hand in a substitute
     * pair, which is passed through untouched.
     *
     * @return array{0:resource,1:resource}|null
     */
    private static function ttyPair(): ?array
    {
        if (self::$probesForTesting !== null && array_key_exists('tty', self::$probesForTesting)) {
            $pair = self::$probesForTesting['tty'];

            return is_array($pair) ? $pair : null;
        }

        if (self::$ownedTtyPair === null) {
            self::$ownedTtyPair = Tty::openTty();
        }

        return self::$ownedTtyPair;
    }
}
