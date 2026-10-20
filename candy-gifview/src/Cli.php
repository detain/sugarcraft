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
 * Mirrors charmbracelet-style gifterm front-end behaviour; extracted verbatim
 * from sugar-animation's `bin/sugar-animation` when the viewer CLI split out into this
 * app package (rename campaign step 1.3). The bin is a two-line shim over
 * {@see main()} so every branch here is testable in-process.
 *
 * E-09 adds chicle-style playback controls: --fps=N (uniform cadence via
 * Player::withFixedFps), --speed=N|Nfps|max (multiplier onto the effective
 * fps, chicle's parser shape at chicle-options.c:1439-1473; the bare
 * multiplier is applied to the GIF's own nominal frame rate, which under the
 * fixed-cadence model means the decoded frames' average delay), and
 * --duration=SECONDS (Player::withMaxSeconds — a model-side deadline, never
 * a wall-clock kill, E646-clean).
 *
 * E-02 honours the authored NETSCAPE loop count: the decoded Player is bound
 * with Decoder::loopCount() over the same bytes (null = play forever, the
 * absent-extension answer SugarCrush chose over libnsgif's gif.c:1601
 * single-play default). E-10 adds --watch: chicle's run_watch rebind
 * (chafa.c:908-955) becomes Player::withWatch behind a host-probe seam, so
 * CI drives change tokens without a real 10 ms busy-poll.
 */
final class Cli
{
    /** Viewport used when STDOUT is not a tty (piped output, CI, examples). */
    public const FALLBACK_CELLS_W = 60;
    public const FALLBACK_CELLS_H = 24;

    /** chicle's own ceiling (CHICLE_ANIM_FPS_MAX, chicle-options.h:60) = "--speed max". */
    private const SPEED_MAX = 'max';

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
     * @param ?\Closure(string): (array|false) $watchStat test seam for E-10:
     *        answers stat() for a path; null falls back to the real @stat().
     */
    public static function main(array $argv, $stdout, $stderr, ?\Closure $play = null, ?\Closure $watchStat = null): int
    {
        $path = null;
        $presetToken = null;
        $fps = null;
        $speed = 1.0;
        $speedMax = false;
        $duration = null;
        $watch = false;

        foreach (array_slice($argv, 1) as $token) {
            if ($token === '--help' || $token === '-h') {
                \fwrite($stdout, Lang::t('cli.usage') . "\n");

                return 0;
            }

            if (\str_starts_with($token, '--')) {
                $flag = substr($token, 2);
                $raw = null;
                if (($eq = strpos($flag, '=')) !== false) {
                    $raw = substr($flag, $eq + 1);
                    $flag = substr($flag, 0, $eq);
                }

                if (!in_array($flag, ['fps', 'speed', 'duration', 'watch'], true)) {
                    \fwrite($stderr, Lang::t('cli.bad_flag', ['flag' => $flag]) . "\n");
                    \fwrite($stderr, Lang::t('cli.usage') . "\n");

                    return 1;
                }

                // E-10: --watch is the only bare (value-less) flag; the
                // value-taking flags below keep their bad_value refusal.
                if ($flag === 'watch') {
                    if ($raw !== null && $raw !== '') {
                        \fwrite($stderr, Lang::t('cli.unexpected_value', ['flag' => 'watch']) . "\n");

                        return 1;
                    }
                    $watch = true;
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
                            \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--fps', 'value' => $raw]) . "\n");

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
                                \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--speed', 'value' => $raw]) . "\n");

                                return 1;
                            }
                            break;
                        }
                        $speed = self::parseSecondsLike('speed', $raw);
                        if ($speed === null) {
                            \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--speed', 'value' => $raw]) . "\n");

                            return 1;
                        }
                        break;
                    case 'duration':
                        $duration = self::parseSecondsLike('duration', $raw);
                        if ($duration === null) {
                            \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--duration', 'value' => $raw]) . "\n");

                            return 1;
                        }
                        break;
                }
                continue;
            }

            if ($path === null) {
                $path = $token;
                continue;
            }
            if ($presetToken === null) {
                $presetToken = $token;
                continue;
            }

            \fwrite($stderr, Lang::t('cli.too_many', ['arg' => $token]) . "\n");

            return 1;
        }

        if ($path === null) {
            \fwrite($stderr, Lang::t('cli.usage') . "\n");

            return 1;
        }

        $preset = ($presetToken ?? '') === Renderer::PRESET_DENSITY
            ? Renderer::PRESET_DENSITY
            : Renderer::PRESET_SOLID;

        [$cellsW, $cellsH] = self::viewportCells();

        try {
            // E-02: read once, then decode and audit the SAME bytes — the
            // authored loop count rides the same intake as the frames
            // (E-11 law), so nothing re-opens the file behind the capture.
            $bytes = Decoder::readFile($path);
            $frames = iterator_to_array(Decoder::decodeBytes($bytes, $cellsW, $cellsH), preserve_keys: false);
        } catch (\RuntimeException $e) {
            \fwrite($stderr, $e->getMessage() . "\n");

            return 1;
        }

        $renderer = Renderer::withConstraints($cellsH, $cellsW);
        $player = new Player($frames, preset: $preset, renderer: $renderer);

        // E-02: honour the file. null (unauthored or raw 0) keeps the
        // Player's infinite default; N >= 2 stops the schedule after N
        // complete playthroughs, exactly chicle-style intent.
        $player = $player->withLoops(Decoder::loopCount($bytes));

        // E-09: --fps / --speed both land on the SAME fixed-cadence seam
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

        // E-10: arm the chicle-style change watcher. The poll seam is the
        // host probe (change token over stat); the Player re-arms a
        // WATCH_POLL_SECONDS tick whenever the probe reports no change, and
        // rebinds frames + loop count when it does.
        if ($watch) {
            $player = $player->withWatch(self::watchProbe($path, $cellsW, $cellsH, $watchStat));
        }

        if ($play !== null) {
            return $play($player);
        }

        (new Program($player, new ProgramOptions(useAltScreen: true)))->run();

        return 0;
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
     * E-10: stat-shaped change token for a path — dev+ino+size+mtime_ns, or
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
     * E-10: build the Player's watch probe for --watch.
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
     *
     * @return \Closure(): ?array{frames: list<\SugarCraft\Animation\Frame>, loops: ?int}
     */
    private static function watchProbe(string $path, int $cellsW, int $cellsH, ?\Closure $watchStat): \Closure
    {
        // The file was JUST decoded successfully, so its present token is
        // the baseline — the first poll must not reload what we already have.
        $last = self::changeToken($path, $watchStat);

        return static function () use ($path, $cellsW, $cellsH, $watchStat, &$last): ?array {
            $token = self::changeToken($path, $watchStat);
            if ($token === $last) {
                return null;
            }
            $last = $token;

            try {
                $bytes = Decoder::readFile($path);
                $frames = iterator_to_array(Decoder::decodeBytes($bytes, $cellsW, $cellsH), preserve_keys: false);
                $loops = Decoder::loopCount($bytes);
            } catch (\RuntimeException) {
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
     * Size the decode grid to the terminal viewport (cols, rows-1 for the
     * status line). Falls back to 60x24 when STDOUT is not a tty
     * (e.g. piped/CI).
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

            return [$size['cols'], max(1, $size['rows'] - 1)];
        }

        return [self::FALLBACK_CELLS_W, self::FALLBACK_CELLS_H];
    }
}
