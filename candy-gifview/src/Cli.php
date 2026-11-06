<?php

declare(strict_types=1);

namespace SugarCraft\GifView;

use SugarCraft\Animation\Decoder;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\Renderer;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Pty\SizeIoctl;

/**
 * Command-line entry point: parse argv, size the decode grid to the viewport,
 * hand the frames to sugar-animation's Player, and run the SugarCraft Program.
 *
 * Front-end design antecedent: namzug16/gifterm (Go); the CLI itself was
 * extracted from sugar-animation's `bin/sugar-animation` when the viewer
 * split out into this app package. The bin stays a thin shim over
 * {@see main()} so every branch here is testable in-process.
 *
 * Playback controls: --fps=N (uniform cadence via Player::withFixedFps),
 * --speed=N|Nfps|max (multiplier onto the effective fps, mirroring chicle's
 * parser shape at chicle-options.c:1439-1473; the bare multiplier is applied
 * to the GIF's own nominal frame rate, which under the fixed-cadence model
 * means the decoded frames' average delay), and --duration=SECONDS
 * (Player::withMaxSeconds — a model-side deadline, never a wall-clock kill).
 *
 * The authored NETSCAPE loop count is honoured: the decoded Player is bound
 * with Decoder::loopCount() over the same bytes (null = play forever, the
 * absent-extension answer chosen over libnsgif's gif.c:1601 single-play
 * default). --watch binds chicle's run_watch rebind (chafa.c:908-955) to
 * Player::withWatch behind a host-probe seam, so CI drives change tokens
 * without a real 10 ms busy-poll.
 */
final class Cli
{
    /** Viewport used when STDOUT is not a tty (piped output, CI, examples). */
    public const FALLBACK_CELLS_W = 60;
    public const FALLBACK_CELLS_H = 24;

    /**
     * Outer belt on the frames the viewer materialises at once: the whole
     * animation lives in RAM while playing (sugar-animation exposes no
     * streaming playback seam). The engine window truncates long animations
     * to its first Decoder::MAX_FRAMES frames before this belt can ever
     * fire, and the truncation is announced on stderr — this ceiling is
     * defence in depth for a future engine that stops capping. The live
     * memory gate is {@see MAX_RESIDENT_CELLS}. See README "Limits".
     */
    public const MAX_FRAMES = 4_096;

    /**
     * Refuse files larger than this before reading them: the intake is a
     * single file_get_contents, so an open-and-quit on a huge file must not
     * buffer it (a 64 MiB GIF is far beyond anything a terminal can play).
     */
    public const MAX_INPUT_BYTES = 64 * 1024 * 1024;

    /**
     * Frame-count × viewport-cells budget for the resident frame list,
     * checked while draining the decoder. The 6 million cells cover the
     * engine's full 256-frame window only up to about 23,437 cells per
     * frame (256 × 23,438 crosses the budget); at a 60,000-cell viewport a
     * 256-frame file therefore refuses on frame 101. It exists so a wide
     * terminal's decode stays bounded well before the engine's own per-frame
     * grid ceilings (Decoder::MAX_CELLS / MAX_TOTAL_PIXELS) become the only defence.
     */
    public const MAX_RESIDENT_CELLS = 6_000_000;

    /** chicle's own ceiling (CHICLE_ANIM_FPS_MAX, chicle-options.h:60) = "--speed max". */
    private const SPEED_MAX = 'max';

    /**
     * The version this run reports: the phar archive's build stamp when
     * executing inside one (InstalledVersions lies under the frozen phar
     * vendor/, so the stamp is the only truth), else the compiled-in
     * Support\Version::CURRENT. The archive is identified by
     * \Phar::running(false), which is exactly the real path the \Phar
     * constructor wants here (this class only ever runs required from
     * inside the archive, never as the stub).
     */
    public static function runningVersion(): string
    {
        return Support\Version::resolve(\Phar::running(false) ?: '', Support\Version::CURRENT);
    }

    private function __construct()
    {
    }

    /**
     * Run the viewer front end.
     *
     * @param list<string> $argv full argument vector, script name at index 0
     * @param resource $stdout stream for usage/help output
     * @param resource $stderr stream for usage/error output
     * @param ?\Closure(Player): int $play test seam: invoked with the wired
     *        Player instead of launching the blocking Program loop; its int
     *        becomes the exit code.
     * @param ?\Closure(string): (array|false) $watchStat test seam for the
     *        watcher: answers stat() for a path; null falls back to the real
     *        @stat().
     * @param ?\Closure(string, int, int): array{string, list<\SugarCraft\Animation\Frame>} $decode
     *        test seam for the intake: answers [bytes, frames] for a path and
     *        grid; null falls back to {@see decodeFrames()}. Pins can drive a
     *        raw \Error out of the walk to prove the rescue catches absorb it.
     */
    public static function main(array $argv, $stdout, $stderr, ?\Closure $play = null, ?\Closure $watchStat = null, ?\Closure $decode = null): int
    {
        $decode ??= static fn (string $p, int $w, int $h): array => self::decodeFrames($p, $w, $h);

        $path = null;
        $presetToken = null;
        $fps = null;
        $speed = 1.0;
        $speedMax = false;
        $duration = null;
        $watch = false;
        $paused = false;

        foreach (array_slice($argv, 1) as $token) {
            if ($token === '--help' || $token === '-h') {
                \fwrite($stdout, Lang::t('cli.usage') . "\n");

                return 0;
            }

            if ($token === '--version' || $token === '-v') {
                \fwrite($stdout, 'candy-gifview ' . self::runningVersion() . "\n");

                return 0;
            }

            if (\str_starts_with($token, '--')) {
                $flag = substr($token, 2);
                $raw = null;
                if (($eq = strpos($flag, '=')) !== false) {
                    $raw = substr($flag, $eq + 1);
                    $flag = substr($flag, 0, $eq);
                }

                if (!in_array($flag, ['fps', 'speed', 'duration', 'watch', 'paused'], true)) {
                    \fwrite($stderr, Lang::t('cli.bad_flag', ['flag' => self::printable($flag)]) . "\n");
                    \fwrite($stderr, Lang::t('cli.usage') . "\n");

                    return 1;
                }

                // --watch and --paused are the only bare (value-less) flags;
                // the value-taking flags below keep their bad_value refusal.
                if ($flag === 'watch' || $flag === 'paused') {
                    if ($raw !== null && $raw !== '') {
                        \fwrite($stderr, Lang::t('cli.unexpected_value', ['flag' => $flag]) . "\n");

                        return 1;
                    }
                    if ($flag === 'watch') {
                        $watch = true;
                    } else {
                        $paused = true;
                    }
                    continue;
                }

                if ($raw === null || $raw === '') {
                    \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--' . $flag, 'value' => '']) . "\n");

                    return 1;
                }

                switch ($flag) {
                    case 'fps':
                        $fps = self::parseSecondsLike('fps', $raw);
                        if ($fps === null) {
                            \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--fps', 'value' => self::printable($raw)]) . "\n");

                            return 1;
                        }
                        break;
                    case 'speed':
                        if ($raw === self::SPEED_MAX) {
                            $speedMax = true;
                            break;
                        }
                        if (substr($raw, -3) === 'fps') {
                            // chicle's Nfps spelling (chicle-options.c:1456-1458): an absolute rate.
                            $fps = self::parseSecondsLike('speed', substr($raw, 0, -3));
                            if ($fps === null) {
                                \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--speed', 'value' => self::printable($raw)]) . "\n");

                                return 1;
                            }
                            break;
                        }
                        $speed = self::parseSecondsLike('speed', $raw);
                        if ($speed === null) {
                            \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--speed', 'value' => self::printable($raw)]) . "\n");

                            return 1;
                        }
                        break;
                    case 'duration':
                        $duration = self::parseSecondsLike('duration', $raw);
                        if ($duration === null) {
                            \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--duration', 'value' => self::printable($raw)]) . "\n");

                            return 1;
                        }
                        break;
                }
                continue;
            }

            // Any other dash-prefixed token (-fps=10, -x) is not a short form
            // this front end speaks: refuse it as an unknown option rather
            // than silently feeding it to the positional ladder as a path.
            if (\str_starts_with($token, '-') && $token !== '-') {
                $body = substr($token, 1);
                $name = rtrim(($eq = strpos($body, '=')) === false ? $body : substr($body, 0, $eq), '-');
                \fwrite($stderr, Lang::t('cli.bad_flag', ['flag' => self::printable($name)]) . "\n");
                \fwrite($stderr, Lang::t('cli.usage') . "\n");

                return 1;
            }

            if ($path === null) {
                $path = $token;
                continue;
            }
            if ($presetToken === null) {
                // The preset slot accepts exactly the two documented presets;
                // anything else is a refusal, not a silent fall back to solid.
                if (!in_array($token, [Renderer::PRESET_SOLID, Renderer::PRESET_DENSITY], true)) {
                    \fwrite($stderr, Lang::t('cli.bad_preset', ['preset' => self::printable($token)]) . "\n");
                    \fwrite($stderr, Lang::t('cli.usage') . "\n");

                    return 1;
                }
                $presetToken = $token;
                continue;
            }

            \fwrite($stderr, Lang::t('cli.too_many', ['arg' => self::printable($token)]) . "\n");

            return 1;
        }

        if ($path === null) {
            \fwrite($stderr, Lang::t('cli.usage') . "\n");

            return 1;
        }

        // Fail before the viewport probe and the intake: every byte of this
        // app needs GD to walk a GIF at all.
        if (!\extension_loaded('gd')) {
            \fwrite($stderr, Lang::t('cli.need_gd') . "\n");

            return 1;
        }

        $preset = $presetToken ?? Renderer::PRESET_SOLID;

        [$cellsW, $cellsH] = self::viewportCells();

        if ($play === null) {
            // The real launch path is silent between exec and the first
            // frame; say what the pause is for. Seam-driven tests skip it.
            \fwrite($stderr, Lang::t('cli.decoding', ['path' => self::printable($path)]) . "\n");
        }

        try {
            // Read once, then decode and audit the SAME bytes — the authored
            // loop count rides the same intake as the frames, so nothing
            // re-opens the file behind the capture.
            [$bytes, $frames] = $decode($path, $cellsW, $cellsH);
        } catch (\Throwable $e) {
            // \Throwable, not just the Decoder's documented RuntimeExceptions:
            // the GIF walk is hand-rolled, and a latent \Error (ValueError/
            // TypeError) from hostile bytes must exit 1 loudly here rather
            // than fatal mid-launch. Wrap the reason in the app's own brand
            // so a terminal never sees a bare library string as the CLI's
            // final word. The engine interpolates the RAW path into its own
            // messages (decoder.no_file), so the relayed reason passes the
            // same printable() choke as every other echo — wording survives,
            // control bytes do not.
            $reason = preg_replace('/^sugar-animation: /', '', $e->getMessage()) ?? $e->getMessage();
            \fwrite($stderr, Lang::t('cli.cannot_play', [
                'path' => self::printable($path),
                'reason' => self::printable($reason),
            ]) . "\n");

            return 1;
        }

        if ($frames === []) {
            \fwrite($stderr, Lang::t('cli.no_frames', ['path' => self::printable($path)]) . "\n");

            return 1;
        }

        if (count($frames) >= Decoder::MAX_FRAMES) {
            // Honest disclosure of the engine window: what plays is the
            // first Decoder::MAX_FRAMES frames, whatever the file holds.
            // The notice fires when the decoded list FILLS the window: a
            // genuinely 256-frame file is indistinguishable from a longer
            // one truncated to 256 until the engine exposes totalFramesSeen
            // (documented seam), so we deliberately over-disclose at the
            // exact boundary rather than silently under-claim.
            \fwrite($stderr, Lang::t('cli.truncated', ['max' => (string) Decoder::MAX_FRAMES]) . "\n");
        }

        if ($duration !== null && count($frames) < 2) {
            // The Player re-anchors its deadline on every early-fire tick,
            // so --duration can never elapse on a single-frame animation —
            // refuse loudly instead of hanging (sugar-animation engine seam).
            \fwrite($stderr, Lang::t('cli.duration_static', ['count' => (string) count($frames)]) . "\n");

            return 1;
        }

        $renderer = Renderer::withConstraints($cellsH, $cellsW);
        $player = new Player($frames, paused: $paused, preset: $preset, renderer: $renderer);

        // Honour the file: null (unauthored or raw 0) keeps the Player's
        // infinite default; N >= 2 stops the schedule after N complete
        // playthroughs, exactly chicle-style intent.
        $player = $player->withLoops(Decoder::loopCount($bytes));

        // --fps / --speed both land on the SAME fixed-cadence seam
        // (sugar-animation exposes no per-frame multiplier; applying the
        // multiplier to the nominal rate derived from the decoded frames is
        // the honest mapping and it trades per-frame timing for smoothness —
        // recorded as a deviation). --duration is a model deadline.
        if ($fps !== null || $speed !== 1.0 || $speedMax) {
            $target = $speedMax
                ? Player::MAX_FPS
                : min(($fps ?? self::nominalFps($frames)) * $speed, Player::MAX_FPS);
            $player = $player->withFixedFps($target);
        }
        if ($duration !== null) {
            $player = $player->withMaxSeconds($duration);
        }

        // Arm the chicle-style change watcher. The poll seam is the
        // host probe (change token over stat); the Player re-arms a
        // WATCH_POLL_SECONDS tick whenever the probe reports no change, and
        // rebinds frames + loop count when it does.
        if ($watch) {
            $player = $player->withWatch(self::watchProbe($path, $cellsW, $cellsH, $watchStat, $decode));
        }

        if ($play !== null) {
            return $play($player);
        }

        $program = new Program($player, new ProgramOptions(useAltScreen: true));
        $program->run();

        // A signal-stopped loop exits 0 by the Program contract; re-encode
        // the signal as the shell idiom 128+N so scripts can tell a kill
        // from a clean quit (stopSignal() stays null on every clean path).
        $signal = $program->stopSignal();

        return $signal !== null ? 128 + $signal : 0;
    }

    /**
     * Parse a positive finite number for --fps/--speed/--duration; anything
     * else (non-numeric, zero, negative, INF, NAN) refuses with null.
     */
    private static function parseSecondsLike(string $flag, string $raw): ?float
    {
        if (!is_numeric($raw)) {
            return null;
        }
        $value = (float) $raw;
        if (!is_finite($value) || $value <= 0.0) {
            return null;
        }

        return $value;
    }

    /**
     * Default intake: refuse oversized files before reading, then read the
     * file once and materialise its frames for the given grid, bounded by
     * {@see MAX_FRAMES} and {@see MAX_RESIDENT_CELLS} (fail-loud: the drain
     * stops one item past each cap instead of buffering the whole stream).
     *
     * @return array{string, list<\SugarCraft\Animation\Frame>} [bytes, frames]
     */
    private static function decodeFrames(string $path, int $cellsW, int $cellsH): array
    {
        $size = @filesize($path);
        if (is_int($size) && $size > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException(Lang::t('cli.reason_bytes_over', [
                'max' => (string) intdiv(self::MAX_INPUT_BYTES, 1_048_576),
            ]));
        }

        $bytes = Decoder::readFile($path);

        return [$bytes, self::collectFrames(
            Decoder::decodeBytes($bytes, $cellsW, $cellsH),
            self::MAX_FRAMES,
            $cellsW * $cellsH,
        )];
    }

    /**
     * Drain a decoder generator into a list, refusing anything longer than
     * $max frames or whose $cells-tall footprint passes MAX_RESIDENT_CELLS.
     * Throws RuntimeExceptions (not Errors) so both rescue catches report
     * them through the normal exit-1 / keep-stale channels.
     *
     * @param \Traversable<\SugarCraft\Animation\Frame> $frames
     *
     * @return list<\SugarCraft\Animation\Frame>
     */
    private static function collectFrames(\Traversable $frames, int $max, int $cells): array
    {
        $out = [];
        foreach ($frames as $frame) {
            $out[] = $frame;
            $count = count($out);
            if ($count > $max) {
                throw new \RuntimeException(Lang::t('cli.reason_frames_over', ['max' => (string) $max]));
            }
            if ($cells > 0 && $count * $cells > self::MAX_RESIDENT_CELLS) {
                throw new \RuntimeException(Lang::t('cli.reason_cells_over', [
                    'max' => (string) self::MAX_RESIDENT_CELLS,
                ]));
            }
        }

        return $out;
    }

    /**
     * Any text echoed into a terminal must not smuggle control bytes: a
     * filename or argv token can carry C0 characters and OSC introducers
     * (`ls` completion of hostile names), and the engine's own messages
     * interpolate raw paths — so EVERY interpolated echo (paths, argv
     * tokens, and the relayed engine reason) funnels through this single
     * choke. Replacement keeps the echo readable without ever letting a
     * quoted token redraw the screen.
     */
    private static function printable(string $text): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', '?', $text) ?? $text;
    }

    /**
     * Stat-shaped change token for a path — dev+ino+size+mtime_ns, or
     * ['missing'] when stat fails (deleted between frames: keep watching,
     * chicle keeps polling too). $stat is the injectable seam; null uses the
     * real @stat so a transient permission blip never fakes a change.
     *
     * @param ?\Closure(string): (array<string|int,mixed>|false) $stat
     */
    private static function changeToken(string $path, ?\Closure $stat): array
    {
        $s = $stat === null ? @stat($path) : $stat($path);
        if (!is_array($s)) {
            return ['missing'];
        }

        return [$s['dev'], $s['ino'], $s['size'], $s['mtime_ns']];
    }

    /**
     * Build the Player's watch probe for --watch.
     *
     * chicle's run_watch (chafa.c:908-955) destroys and recreates the
     * animation whenever the file's change counter moves; it deliberately
     * distrusts timestamps alone and re-reads on every tick. We deviate in
     * two disclosed ways: the token folds dev+ino+size+mtime_ns so a
     * replace-by-rename is caught (timestamp-only granularity misses it),
     * and a decode failure adopts the token anyway — a half-written file
     * must not re-decode every 10 ms, the next real change re-mints it.
     * Stale frames keep playing through a broken state, exactly chicle's
     * if (media_loader) guard shape.
     *
     * @param ?\Closure(string): (array<string|int,mixed>|false) $watchStat
     * @param ?\Closure(string, int, int): array{string, list<\SugarCraft\Animation\Frame>} $decode
     *
     * @return \Closure(): ?array{frames: list<\SugarCraft\Animation\Frame>, loops: ?int}
     */
    private static function watchProbe(string $path, int $cellsW, int $cellsH, ?\Closure $watchStat, ?\Closure $decode = null): \Closure
    {
        $decode ??= static fn (string $p, int $w, int $h): array => self::decodeFrames($p, $w, $h);

        // The file was JUST decoded successfully, so its present token is
        // the baseline — the first poll must not reload what we already have.
        $last = self::changeToken($path, $watchStat);

        return static function () use ($path, $cellsW, $cellsH, $watchStat, $decode, &$last): ?array {
            $token = self::changeToken($path, $watchStat);
            if ($token === $last) {
                return null;
            }
            $last = $token;

            try {
                [$bytes, $frames] = $decode($path, $cellsW, $cellsH);
                $loops = Decoder::loopCount($bytes);
            } catch (\Throwable) {
                // \Throwable, not just RuntimeException: a raw \Error escaping
                // the reload walk must not kill the watch loop — stale frames
                // keep playing and the poll stays armed (same guard shape).
                return null;
            }

            if ($frames === []) {
                return null;
            }

            return ['frames' => $frames, 'loops' => $loops];
        };
    }

    /**
     * Average frames-per-second of the decoded sequence, GIF delays floored
     * at 1 cs (matching sugar-animation's scheduling floor).
     *
     * @param list<\SugarCraft\Animation\Frame> $frames
     */
    private static function nominalFps(array $frames): float
    {
        $n = count($frames);
        if ($n === 0) {
            return 100.0;
        }
        $total = array_sum(array_map(
            static fn ($f): int => max(1, $f->delay),
            $frames,
        )) / 100.0;

        return $n / $total;
    }

    /**
     * Size the decode grid to the terminal viewport: rows-2, one row for the
     * Player's status line and one for the trailing newline its view()
     * emits — an over-tall final line scrolls the alt screen by one. Falls
     * back to 60x24 when STDOUT is not a tty (e.g. piped/CI). On resize the
     * engine re-clamps to rows-1 itself (sugar-animation Player; engine
     * seam), so the reserve is exact for the initial paint.
     *
     * Descriptor 1 is spelled as the literal: `(int) STDOUT` is the RESOURCE
     * ID 2, which names STDERR — the same defect fixed in
     * Renderer::withAdaptiveSize() and pinned by BinSizeQueryPolarityTest.
     * stream_isatty() is core PHP, so the gate needs no ext-posix either.
     *
     * @return array{int, int} [cellsW, cellsH]
     */
    public static function viewportCells(): array
    {
        if (\is_resource(\STDOUT) && stream_isatty(STDOUT)) {
            $size = SizeIoctl::query(1);

            return [$size['cols'], max(1, $size['rows'] - 2)];
        }

        return [self::FALLBACK_CELLS_W, self::FALLBACK_CELLS_H];
    }
}
