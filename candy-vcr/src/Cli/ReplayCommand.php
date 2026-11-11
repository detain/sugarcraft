<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\CassetteLoader;
use SugarCraft\Vcr\Support\Scalars;

/**
 * `candy-vcr replay <cassette> [--speed=instant|realtime] [--idle-trim=N|--idle-trim N]`
 *
 * Stream the cassette's recorded output bytes to stdout. With
 * `--speed=realtime`, sleeps between events so playback matches the
 * recorded cadence (useful for visual inspection and demos); when the
 * flag is absent the default is `instant` — the whole stream is flushed
 * as fast as the kernel accepts it. The header's `playbackSpeed`
 * multiplier is a RENDER-side concept (honoured by `render-tape` /
 * `render-batch` through FrameStream); live replay does not scale by it.
 *
 * `--idle-trim=N` clamps inter-event gaps longer than N seconds to N,
 * so long pauses in the recording don't produce long pauses in replay.
 * The space form `--idle-trim N` is accepted as well, like every other
 * value-taking flag on this verb.
 * Use `--no-trim` to restore original (pre-trim) wall-clock timing.
 *
 * The recorded stream is VERBATIM terminal bytes — it may leave the
 * screen on the alternate buffer with a hidden cursor (a vim cassette
 * does exactly that). On an interactive stdout the replay therefore
 * brackets the stream with a DEC 1049 save/restore plus cursor-show
 * and SGR-reset trailer, and SIGINT/SIGTERM/SIGHUP abort playback
 * long enough to emit that restore before exiting 128+signo. When
 * stdout is a pipe or file the byte stream is passed through with no
 * wrapper — capture stays byte-exact. Replaying a foreign cassette
 * executes its raw bytes AT your terminal (OSC-8, OSC-52 clipboard,
 * title, PUA): treat cassettes like binaries from strangers.
 *
 * No Program is involved — this just plays back what was recorded.
 * For round-trip-replay-into-a-fresh-Program use the
 * {@see \SugarCraft\Vcr\Player} class from PHP code.
 *
 * A finished replay reports where it got to (events streamed, output bytes,
 * virtual time) on stderr — never on stdout, because with a piped stdout the
 * byte stream IS the capture. Silence used to make an empty cassette and a
 * full replay indistinguishable, and both of those indistinguishable from a
 * failure that simply said nothing.
 */
final class ReplayCommand implements DocumentedCommand
{
    /** DEC VT: save cursor / suspend-to-alternate-screen state (CSI ? 1049 s). */
    public const SCREEN_GUARD_SAVE = "\x1b[?1049s";

    /** Restore saved screen, show the cursor, reset every SGR attribute. */
    public const SCREEN_GUARD_RESTORE = "\x1b[?1049u\x1b[?25h\x1b[0m";

    /** @var \Closure(resource): bool */
    private readonly \Closure $ttyProbe;

    /**
     * @param (callable(resource): bool)|null $ttyProbe seam used by tests to
     *                                          force the interactive path
     *                                          without a controlling terminal
     */
    public function __construct(?callable $ttyProbe = null)
    {
        $this->ttyProbe = $ttyProbe !== null
            ? \Closure::fromCallable($ttyProbe)
            : static fn ($stream): bool => \stream_isatty($stream);
    }

    public function summary(): string
    {
        return "Stream a cassette's recorded output to stdout";
    }

    public function usage(): string
    {
        return 'usage: candy-vcr replay <cassette> [--speed=instant|realtime]
       [--idle-trim=N|--idle-trim N] [--no-trim]' . "\n"
            . "  Default --speed is instant. The header's playbackSpeed multiplier" . "\n"
            . "  applies to rendering (render-tape/render-batch), not live replay." . "\n";
    }

    public function run(array $args, $stdout, $stderr): int
    {
        $path = null;
        $speed = 'instant';
        $noTrim = false;
        $idleTrim = null;
        $argCount = count($args);
        for ($i = 0; $i < $argCount; $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--speed=')) {
                $speed = substr($arg, 8);
            } elseif ($arg === '--no-trim') {
                $noTrim = true;
            } elseif (str_starts_with($arg, '--idle-trim=')) {
                $idleTrim = (float) substr($arg, 12);
                if ($idleTrim <= 0.0) {
                    fwrite($stderr, "candy-vcr replay: --idle-trim must be > 0 seconds\n");
                    return 2;
                }
            } elseif ($arg === '--idle-trim') {
                $next = $args[$i + 1] ?? null;
                if ($next === null || str_starts_with($next, '--')) {
                    fwrite($stderr, "candy-vcr replay: --idle-trim requires a positive seconds argument\n");
                    return 2;
                }
                $i++;
                $idleTrim = (float) $next;
                if ($idleTrim <= 0.0) {
                    fwrite($stderr, "candy-vcr replay: --idle-trim must be > 0 seconds\n");
                    return 2;
                }
            } elseif (str_starts_with($arg, '--')) {
                fwrite($stderr, "candy-vcr replay: unknown option {$arg}\n");
                return 2;
            } else {
                $path = $arg;
            }
        }

        if ($path === null) {
            fwrite($stderr, $this->usage());
            return 2;
        }
        if (!in_array($speed, ['instant', 'realtime'], true)) {
            fwrite($stderr, "candy-vcr replay: --speed must be 'instant' or 'realtime'\n");
            return 2;
        }

        $loader = new CassetteLoader();
        try {
            $cassette = $loader->load($path);
        } catch (\Throwable $e) {
            fwrite($stderr, "candy-vcr replay: {$e->getMessage()}\n");
            return 1;
        }
        CassetteLoader::writeTapeWarnings($loader, $stderr, 'replay');

        $interrupted = 0;
        $signalsArmed = $this->armInterruptHandlers($interrupted);
        $interactive = ($this->ttyProbe)($stdout);

        try {
            if ($interactive) {
                fwrite($stdout, self::SCREEN_GUARD_SAVE);
            }

            $previousT = 0.0;
            $outputBytes = 0;
            $playedEvents = 0;
            $totalEvents = count($cassette->events);
            foreach ($cassette->events as $event) {
                if ($interrupted !== 0) {
                    break;
                }
                // Dual-timestamp replay: when --no-trim is passed
                // and the event carries `tRaw` (recorded with --idle-trim),
                // honour the uncompressed timestamp so the playback cadence
                // matches the original wallclock. Default replay (no flag)
                // uses `t` (compressed) which matches existing behaviour.
                $effectiveT = ($noTrim && isset($event->payload['tRaw']))
                    ? Scalars::float($event->payload['tRaw'], 'replay tRaw timestamp')
                    : $event->t;
                if ($speed === 'realtime') {
                    $delta = $effectiveT - $previousT;
                    // Apply idle-trim: clamp long delays to the specified threshold
                    // so playback doesn't wait for multi-second gaps that were
                    // only present in the recording environment.
                    if ($idleTrim !== null && $delta > $idleTrim) {
                        $delta = $idleTrim;
                    }
                    if ($delta > 0) {
                        usleep((int) ($delta * 1_000_000));
                    }
                }
                if ($event->kind === EventKind::Output) {
                    $bytes = Scalars::string($event->payload['b'] ?? '', 'replay output bytes');
                    fwrite($stdout, $bytes);
                    $outputBytes += strlen($bytes);
                }
                $previousT = $effectiveT;
                $playedEvents++;
            }

            if ($interrupted !== 0) {
                fwrite($stderr, sprintf(
                    "candy-vcr replay: interrupted after %d of %d events (%d output bytes)\n",
                    $playedEvents,
                    $totalEvents,
                    $outputBytes,
                ));

                return 128 + $interrupted;
            }

            if ($loader->compiledTape() && $outputBytes === 0) {
                // A .tape describes KEYSTROKES; compiling it yields input
                // events and zero output bytes. Printing nothing and exiting
                // 0 was a dead success — the user wanted render-tape.
                fwrite($stderr, "candy-vcr replay: {$path} is a tape source with no recorded output bytes — replay shows nothing; use 'candy-vcr render-tape' to render .tape files\n");
                return 1;
            }

            // A replay that printed nothing used to be indistinguishable from a
            // silent failure — an empty cassette and a full one both exited 0
            // with an empty terminal. The verdict goes to STDERR because on a
            // piped stdout the byte stream IS the capture and must stay
            // byte-exact.
            fwrite($stderr, sprintf(
                "candy-vcr replay: played %d of %d events (%d output bytes, %.3f s of recorded time)\n",
                $playedEvents,
                $totalEvents,
                $outputBytes,
                $previousT,
            ));

            return 0;
        } finally {
            if ($interactive) {
                // Runs on normal end, a signal break, AND an exception
                // escaping the loop — the terminal always gets its screen
                // and cursor back.
                fwrite($stdout, self::SCREEN_GUARD_RESTORE);
            }
            if ($signalsArmed) {
                $this->disarmInterruptHandlers();
            }
        }
    }

    /**
     * Install abort handlers so a Ctrl-C mid-replay still restores the
     * terminal. Returns false when ext-pcntl is unavailable (then the
     * trailer only covers the normal/exception paths, not signals).
     */
    private function armInterruptHandlers(int &$interrupted): bool
    {
        if (!\function_exists('pcntl_async_signals') || !\defined('SIGINT')) {
            return false;
        }
        pcntl_async_signals(true);
        $handler = static function (int $signo) use (&$interrupted): void {
            $interrupted = $signo;
        };
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGHUP, $handler);
        return true;
    }

    private function disarmInterruptHandlers(): void
    {
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGHUP, SIG_DFL);
    }
}
