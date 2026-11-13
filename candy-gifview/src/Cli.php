<?php

declare(strict_types=1);

namespace SugarCraft\GifView;

use SugarCraft\Animation\Decoder;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\Renderer;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\Tty;

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
 * The authored NETSCAPE loop count is honoured: the audited intake answers
 * [frames, loops] (loopCount over the bytes it just read — null = play
 * forever, the absent-extension answer chosen over libnsgif's gif.c:1601
 * single-play default). --watch binds chicle's run_watch rebind
 * (chafa.c:908-955) to Player::withWatch behind a host-probe seam, so CI
 * drives change tokens without a real 10 ms busy-poll.
 *
 * chicle = https://github.com/haraldschmidt/chicle (CLI viewer over chafa):
 * the flag-parser model this front end mirrors, so every chicle-options.c/
 * chafa.c line cite below refers to that project.
 */
final class Cli
{
    /** Viewport used when STDOUT is not a tty (piped output, CI, examples). */
    public const FALLBACK_CELLS_W = 60;
    public const FALLBACK_CELLS_H = 24;

    /**
     * Outer ceiling on the frames the viewer materialises at once: the whole
     * animation lives in RAM while playing (sugar-animation exposes no
     * streaming playback seam). The engine window truncates long animations
     * to its first Decoder::MAX_FRAMES frames before this ceiling can ever
     * fire, and the truncation is announced on stderr — the ceiling is
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
        // A phar-less host (`php -n` drops the shared module): absence is
        // exactly the '' non-archive answer resolve() already handles.
        $running = \class_exists('Phar') ? \Phar::running(false) : '';

        return Support\Version::resolve($running ?: '', Support\Version::CURRENT);
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
     * @param ?\Closure(string, int, int): array{list<\SugarCraft\Animation\Frame>, ?int} $decode
     *        test seam for the intake: answers [frames, loops] for a path and
     *        grid — the loop-count audit is part of the intake, so a pin's
     *        garbage-bytes stand-in throws inside the rescue, never past it;
     *        null falls back to {@see decodeFrames()}. Pins can drive a
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

        foreach (\array_slice($argv, 1) as $token) {
            if ($token === '--help' || $token === '-h') {
                \fwrite($stdout, Lang::t('cli.usage') . "\n");

                return 0;
            }

            if ($token === '--version' || $token === '-v') {
                \fwrite($stdout, 'candy-gifview ' . self::runningVersion() . "\n");

                return 0;
            }

            if (\str_starts_with($token, '--')) {
                $flag = \substr($token, 2);
                $raw = null;
                if (($eq = \strpos($flag, '=')) !== false) {
                    $raw = \substr($flag, $eq + 1);
                    $flag = \substr($flag, 0, $eq);
                }

                if (!\in_array($flag, ['fps', 'speed', 'duration', 'watch', 'paused'], true)) {
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
                        $fps = self::parseSecondsLike($raw);
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
                        if (\substr($raw, -3) === 'fps') {
                            // chicle's Nfps spelling (chicle-options.c:1456-1458): an absolute rate.
                            $fps = self::parseSecondsLike(\substr($raw, 0, -3));
                            if ($fps === null) {
                                \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--speed', 'value' => self::printable($raw)]) . "\n");

                                return 1;
                            }
                            break;
                        }
                        $speed = self::parseSecondsLike($raw);
                        if ($speed === null) {
                            \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--speed', 'value' => self::printable($raw)]) . "\n");

                            return 1;
                        }
                        break;
                    case 'duration':
                        $duration = self::parseSecondsLike($raw);
                        if ($duration === null) {
                            \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--duration', 'value' => self::printable($raw)]) . "\n");

                            return 1;
                        }
                        break;
                }
                continue;
            }

            // A lone `-` is the conventional stdin spelling, but this viewer
            // has no stdin intake at all — it only ever opens a named file
            // (an unbounded stream would also slip past the size gate) — so
            // the dash-only token keeps its exemption here: it is treated as
            // an ordinary path and fails at the engine with the honest
            // could-not-play line (gifview-019 pins it).
            //
            // Any other dash-prefixed token (-fps=10, -x) is not a short form
            // this front end speaks: refuse it as an unknown option rather
            // than silently feeding it to the positional ladder as a path.
            // The display normalizes to --name form: the cli.bad_flag template
            // hardcodes the double dash, so `-fps=10` echoes as
            // `unknown option --fps` (pinned by CliTest's single-dash refusal).
            if (\str_starts_with($token, '-') && $token !== '-') {
                $body = \substr($token, 1);
                $name = \rtrim(($eq = \strpos($body, '=')) === false ? $body : \substr($body, 0, $eq), '-');
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
                if (!\in_array($token, [Renderer::PRESET_SOLID, Renderer::PRESET_DENSITY], true)) {
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
            // re-opens the file behind the capture, and every engine throw
            // (decode AND audit) lands inside this rescue.
            [$frames, $loops] = $decode($path, $cellsW, $cellsH);
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
            $reason = \preg_replace('/^sugar-animation: /', '', $e->getMessage()) ?? $e->getMessage();
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

        if (\count($frames) >= Decoder::MAX_FRAMES) {
            // Honest disclosure of the engine window: what plays is the
            // first Decoder::MAX_FRAMES frames, whatever the file holds.
            // The notice fires when the decoded list FILLS the window: a
            // genuinely 256-frame file is indistinguishable from a longer
            // one truncated to 256 until the engine exposes totalFramesSeen
            // (documented seam), so we deliberately over-disclose at the
            // exact boundary rather than silently under-claim.
            \fwrite($stderr, Lang::t('cli.truncated', ['max' => (string) Decoder::MAX_FRAMES]) . "\n");
        }

        if ($duration !== null && \count($frames) < 2) {
            // The Player re-anchors its deadline on every early-fire tick,
            // so --duration can never elapse on a single-frame animation —
            // refuse loudly instead of hanging (sugar-animation engine seam).
            \fwrite($stderr, Lang::t('cli.duration_static', ['count' => (string) \count($frames)]) . "\n");

            return 1;
        }

        $renderer = Renderer::withConstraints($cellsH, $cellsW);
        $player = new Player($frames, paused: $paused, preset: $preset, renderer: $renderer);

        // Honour the file: null (unauthored or raw 0) keeps the Player's
        // infinite default; N >= 2 stops the schedule after N complete
        // playthroughs, matching chicle's intent exactly. The audit rode the
        // intake, so no raw bytes survive this point (gifview-007).
        $player = $player->withLoops($loops);

        // --fps / --speed both land on the SAME fixed-cadence seam
        // (sugar-animation exposes no per-frame multiplier; applying the
        // multiplier to the nominal rate derived from the decoded frames is
        // the honest mapping and it trades per-frame timing for smoothness —
        // recorded as a deviation). --duration is a model deadline.
        if ($fps !== null || $speed !== 1.0 || $speedMax) {
            $target = $speedMax
                ? Player::MAX_FPS
                : \min(($fps ?? self::nominalFps($frames)) * $speed, Player::MAX_FPS);
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
    private static function parseSecondsLike(string $raw): ?float
    {
        if (!\is_numeric($raw)) {
            return null;
        }
        $value = (float) $raw;
        if (!\is_finite($value) || $value <= 0.0) {
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
     * @return array{list<\SugarCraft\Animation\Frame>, ?int} [frames, loops]
     *         — the loop count is audited over the same bytes inside this
     *         call, so the raw bytes never leave the scope (gifview-006/007).
     */
    private static function decodeFrames(string $path, int $cellsW, int $cellsH): array
    {
        $size = @\filesize($path);
        if (\is_int($size) && $size > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException(Lang::t('cli.reason_bytes_over', [
                'max' => (string) \intdiv(self::MAX_INPUT_BYTES, 1_048_576),
            ]));
        }

        $bytes = Decoder::readFile($path);

        // The loop-count audit runs HERE, inside the intake, not in the
        // caller: a 'not a GIF' throw from loopCount() is then absorbed by
        // main()/watchProbe()'s rescue catches through the single exit-1
        // channel instead of fataling past them (a future gate divergence
        // between decodeBytes() and loopCount() cannot produce a mid-run
        // 255), and the raw bytes die at this return — a forever-looping
        // animation no longer carries up to MAX_INPUT_BYTES of dead RAM.
        return [self::collectFrames(
            Decoder::decodeBytes($bytes, $cellsW, $cellsH),
            self::MAX_FRAMES,
            $cellsW * $cellsH,
        ), Decoder::loopCount($bytes)];
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
            $count = \count($out);
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
     * filename or argv token can carry C0 controls (the OSC introducer
     * ESC is one of them), so EVERY interpolated echo (paths, argv
     * tokens, and the relayed engine reason) funnels through this single
     * choke. Replacement keeps the echo readable without ever letting a
     * quoted token redraw the screen. The sweep is C0 + DEL only: C1
     * bytes and bidi overrides such as U+202E ride through as ordinary
     * printable text, a scope the app's surfaces were verified against.
     */
    private static function printable(string $text): string
    {
        return \preg_replace('/[\x00-\x1F\x7F]/', '?', $text) ?? $text;
    }

    /**
     * Stat-shaped change token for a path — dev+ino+size+mtime (second
     * granularity on the real stat; seam injections may carry mtime_ns) — or
     * ['missing'] when stat fails (deleted between frames: keep watching;
     * chicle keeps polling too). $stat is the injectable seam; null uses the
     * real @stat so a transient permission blip never fakes a change — after
     * clearing PHP's per-process stat cache for this path first. The cache
     * survives external writes (another process saved the file; our poll
     * would answer stale forever), which is exactly the bug the watch host
     * test catches.
     *
     * @param ?\Closure(string): (array<string|int,mixed>|false) $stat
     */
    private static function changeToken(string $path, ?\Closure $stat): array
    {
        if ($stat !== null) {
            // The injected seam stays cache-free: hermetic pins own their data.
            $s = $stat($path);
        } else {
            \clearstatcache(false, $path);
            $s = @\stat($path);
        }
        if (!\is_array($s)) {
            return ['missing'];
        }

        // A real stat() carries NO mtime_ns member (verified key set), only
        // second-granularity mtime; the ?? keeps fabricated seam shapes on
        // nanoseconds while production falls back silently instead of
        // warning on every poll tick.
        return [$s['dev'], $s['ino'], $s['size'], $s['mtime_ns'] ?? $s['mtime']];
    }

    /**
     * Build the Player's watch probe for --watch.
     *
     * chicle's run_watch (chafa.c:908-955) destroys and recreates the
     * animation whenever the file's change counter moves; it deliberately
     * distrusts timestamps alone and re-reads on every tick. We deviate in
     * two disclosed ways: the token folds dev+ino+size+mtime so a
     * replace-by-rename is caught where the host filesystem exposes stable
     * dev/ino — on FAT/exFAT, SMB/CIFS and synthetic-inode mounts dev/ino
     * collapse and the token degrades to size+mtime (and real stat answers
     * are second-granular); and a decode failure adopts the token anyway —
     * a half-written file must not re-decode every 10 ms, the next real
     * change re-mints it. Stale frames keep playing through a broken state,
     * exactly chicle's if (media_loader) guard shape.
     *
     * @param ?\Closure(string): (array<string|int,mixed>|false) $watchStat
     * @param ?\Closure(string, int, int): array{list<\SugarCraft\Animation\Frame>, ?int} $decode
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
                // The audited tuple: the intake itself carries the loop
                // count, so a reload-time 'not a GIF' is rescued right here
                // exactly like a decode failure — stale frames keep playing.
                [$frames, $loops] = $decode($path, $cellsW, $cellsH);
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
        $n = \count($frames);
        if ($n === 0) {
            return 100.0;
        }
        $total = \array_sum(\array_map(
            static fn ($f): int => \max(1, $f->delay),
            $frames,
        )) / 100.0;

        return $n / $total;
    }

    /**
     * Size the decode grid to the terminal viewport: rows-2, one row for the
     * Player's status line and one for the trailing newline its view()
     * emits — an over-tall final line scrolls the alt screen by one. The
     * COLUMNS/LINES environment pair wins first (bare positive integers —
     * the universal CLI escape hatch for hosts the probe cannot answer);
     * otherwise the never-throwing sugar-core Tty facade probes STDOUT. Off
     * a terminal (piped output, CI) the grid is the deterministic 60x24
     * fallback. On resize the engine re-clamps to rows-1 itself
     * (sugar-animation Player; engine seam), so the reserve is exact for
     * the initial paint.
     *
     * The probe delegates to the facade rather than querying the raw ioctl:
     * the old seat read descriptor 1 behind a core stream_isatty gate and
     * every FFI/ioctl failure surfaced as an uncaught exception with exit
     * 255. Tty answers isTty() and size() without throwing, and the
     * catch-all belt below turns even a hypothetical facade failure into
     * the fallback grid. Descriptor discipline — `(int) STDOUT` is the
     * RESOURCE ID, not the descriptor, the defect fixed in
     * Renderer::withAdaptiveSize() — now belongs to the facade's own
     * seats, pinned in sugar-core; BinSizeQueryPolarityTest pins this
     * file's delegation.
     *
     * @return array{int, int} [cellsW, cellsH]
     */
    public static function viewportCells(): array
    {
        $columns = self::envPositiveInt('COLUMNS');
        $lines = self::envPositiveInt('LINES');

        if (($columns === null || $lines === null) && \is_resource(\STDOUT)) {
            try {
                $tty = new Tty(\STDOUT);

                if ($tty->isTty()) {
                    $size = $tty->size();
                    $columns ??= $size['cols'];
                    $lines ??= $size['rows'];
                }
            } catch (\Throwable) {
                // Belt: Tty is documented never to throw, but the viewer's
                // outcome must stay a clean frame (or exit 1 with one
                // localized line) — a failed probe degrades to the fallback
                // axes below, never an escaping exception.
            }
        }

        if ($columns !== null && $lines !== null) {
            return [$columns, \max(1, $lines - 2)];
        }

        return [$columns ?? self::FALLBACK_CELLS_W, $lines ?? self::FALLBACK_CELLS_H];
    }

    /**
     * A bare positive integer read from the environment, or null when the
     * name is unset or not one (junk like "0", "-5" or "12.5" is ignored,
     * not honoured).
     */
    private static function envPositiveInt(string $name): ?int
    {
        $raw = \getenv($name);

        if (!\is_string($raw) || $raw === '' || !\ctype_digit($raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }
}
