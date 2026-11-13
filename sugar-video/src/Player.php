<?php

declare(strict_types=1);

namespace SugarCraft\Video;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Style;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Video\Lang;
use SugarCraft\Core\Util\Width;
use SugarCraft\ColorProfile\Probe;
use SugarCraft\Video\Decode\Decoder;
use SugarCraft\Video\Decode\DecoderFactory;
use SugarCraft\Video\Decode\FfmpegDecodeFailure;
use SugarCraft\Video\Decode\GifDecoder;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Msg\ResizeRebuildMsg;
use SugarCraft\Video\Msg\TickMsg;
use SugarCraft\Video\Render\Color;
use SugarCraft\Video\Render\FrameRenderer;
use SugarCraft\Video\Render\LumaRamp;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Render\RendererFactory;
use SugarCraft\Video\Source\HttpHeaders;
use SugarCraft\Video\Source\VideoSource;
use SugarCraft\Video\Subtitle\WebVtt;

/**
 * TEA Model for terminal video playback.
 *
 * The Player implements the Elm Architecture (Model-View-Update) via
 * the TEA runtime in sugar-core. It pulls frames from a Decoder iterator,
 * paces them using wall-clock time and the Sync engine, and renders via
 * FrameRenderer (ascii/ansi256/truecolor/half-block).
 *
 * Repaint: view() returns the FULL current frame every call. sugar-core's
 * Renderer owns the diffing: it compares the new frame against the
 * previously rendered one and re-sends only the rows that changed — at ROW
 * granularity by default, with finer cell-granularity diffing opt-in via
 * ProgramOptions. The Player must not diff inside view(): the framework
 * diffs against the last rendered OUTPUT, so handing it a partial frame
 * would desync that comparison and corrupt the screen.
 *
 * Keys:
 *   Space       — pause / resume
 *   ← / →      — seek backward / forward 10 frames
 *   [ / ]      — decrease / increase speed (0.25 steps, 0.25–4.0)
 *   0–9        — seek to 0–90% of duration
 *   m          — cycle rendering mode
 *   q / Esc / Ctrl-C — quit
 *
 * Mirrors the TEA player structure in sugar-animation/src/Player.php but
 * adapted for continuous video rather than discrete GIF frames.
 */
final class Player implements Model
{
    /** Minimum and maximum terminal column/row bounds for resize clamping. */
    public const MIN_COLS = 10;
    public const MAX_COLS = 200;
    /**
     * SV-M19: floor of the DEFAULT per-tick decode budget armed by
     * {@see open()} — max(this, 2 × frame interval). A tick may walk at most
     * that long synchronously before yielding the rest of a catch-up to the
     * next tick, so a long seek keeps painting while it converges.
     */
    public const DEFAULT_FRAME_BUDGET_FLOOR_MS = 50.0;
    /**
     * SV-M20/045: the largest content-time delta ONE tick may charge. The
     * pacing clock is monotonic (hrtime), so NTP steps and suspend jumps can
     * no longer forge elapsed time at all; this clamp is the second line of
     * defence for hand-fed clock seams and pathological stalls — a negative
     * skew reads as zero and no single update() bursts past one second of
     * playback, whatever the source claims.
     */
    public const MAX_TICK_DELTA_SEC = 1.0;
    public const MIN_ROWS = 5;
    public const MAX_ROWS = 80;

    /**
     * Debounce window for applying a pending resize, in seconds.
     *
     * Sized to comfortably exceed a manual drag-resize's inter-reflow gap (a
     * terminal reflows on each character-cell change, so events arrive milliseconds
     * apart) while staying well under a frame the user would notice: at the default
     * 24 fps the picture holds its pre-resize geometry for barely one frame.
     */
    private const RESIZE_DEBOUNCE_SECONDS = 0.05;
    /**
     * SV-L86/086: floor on the wall-clock interval between two frameAt()
     * thumbnail reads. A mouse scrub crosses a whole second of buckets in a
     * burst of events; without a floor each crossed bucket is one throwaway
     * ffmpeg spawn (factory players) or one double-reopen walk (in-place
     * players) — a spawn storm painted by the pointer.
     */
    private const FRAME_AT_MIN_SPAWN_SEC = 1.0;

    /**
     * @param Decoder                      $decoder      Frame source iterator
     * @param Mode                         $mode         Rendering mode
     * @param float                       $speed        Playback speed multiplier
     * @param bool                        $paused       True when playback is paused
     * @param float                       $videoTime    Content seconds since playback start (accumulates delta*speed, NOT retroactive)
     * @param int                         $frameIndex   Current frame number (0-based)
     * @param RgbFrame|null               $currentFrame Decoded frame ready for rendering
     * @param float                       $lastTickTime Wall-clock time at last tick (microtime)
     * @param float                       $fps          Frames per second from VideoSource
     * @param int                         $totalFrames  Total frame count (0 if unknown/stream)
     * @param int                         $cellsW       Terminal cell width
     * @param int                         $cellsH       Terminal cell height
     * @param string                      $videoPath    Source video file path (for seek/restart)
     * @param AudioPlayer|null            $audioPlayer  Audio subprocess handle (null when no audio)
     * @param bool                        $ended        True once the decoder is exhausted (playback stopped at end)
     * @param bool                        $loop         When true, restart from frame 0 at end instead of stopping
     * @param string                      $ramp         Luma ramp name: 'minimal', 'standard', 'dense'
     * @param \Closure                    $audioFactory Factory fn(string $path, ?int $startMs, array $headers): AudioPlayer
     * @param int                         $cellPxW      Pixel width of a terminal cell (graphics-mode resolution)
     * @param int                         $cellPxH      Pixel height of a terminal cell
     * @param WebVtt|null                 $subtitles    Parsed subtitle track, or null when no subtitles
     * @param FrameRenderer|null          $renderer     Cached renderer for direct-render modes (Sixel/Kitty/iTerm2/Ansi256)
     * @param array<array-key, string>    $headers      HTTP request headers for a remote source, re-presented on every decoder rebuild
     * @param float                       $frameBudgetMs Wall-clock budget for one tick's decode+skip work before catch-up bails (0 = unbounded); enforced between frames, never mid-decode
     * @param WindowSizeMsg|null          $pendingResize Clamped geometry received from a `WindowSizeMsg` whose decoder rebuild is still deferred by the resize debounce; null when nothing is pending
     */
    private function __construct(
        public readonly Decoder $decoder,
        public readonly Mode $mode,
        public readonly float $speed,
        public readonly bool $paused,
        public readonly float $videoTime,
        public readonly int $frameIndex,
        public readonly ?RgbFrame $currentFrame,
        private readonly float $lastTickTime,
        public readonly float $fps,
        public readonly int $totalFrames,
        public readonly int $cellsW,
        public readonly int $cellsH,
        private readonly string $videoPath,
        private readonly ?AudioPlayer $audioPlayer,
        public readonly bool $ended,
        private readonly bool $loop,
        private readonly string $ramp = 'standard',
        private readonly ?\Closure $audioFactory = null,
        public readonly int $cellPxW = 10,
        public readonly int $cellPxH = 20,
        private readonly ?WebVtt $subtitles = null,
        private readonly ?FrameRenderer $renderer = null,
        private readonly array $headers = [],
        private readonly float $frameBudgetMs = 0.0,
        private readonly ?WindowSizeMsg $pendingResize = null,
        public readonly ?string $failure = null,
        public readonly bool $audioMuted = false,
        private readonly bool $hostRestricted = false,
        // SV-M20/020: scripted pacing clock (seconds; test injection only, via
        // fromDecoder/openForTest — the precedent is $autoModeChooser). null
        // keeps the library default: the monotonic hrtime base in now().
        private readonly ?\Closure $clock = null,
        // SV-PGEO/068: the host's REAL column count when the last window it
        // reported sits below the MIN_COLS floor, null otherwise. A below-floor
        // terminal cannot hold even one pane row without soft-wrapping it, so
        // view() paints a one-line refusal at the real width instead of
        // emitting lines that are guaranteed wider than the screen.
        private readonly ?int $narrowCols = null,
        // SV-L86/086: the frameAt() thumbnail memo + spawn throttle, carried
        // by mutate() for the lifetime of the stream (keyed-and-invalidated by
        // the geometry signature inside, so a rebuild can never serve a stale
        // bucket). Mutable state on an immutable model is deliberate — the
        // $stopped latch above is the precedent: it is a CACHE, not a value
        // the model is presented with.
        private readonly \ArrayObject $frameAtMemo = new \ArrayObject(),
    ) {
    }

    /**
     * SV-M20/045: the pacing clock. Monotonic by default — microtime() is a
     * wall clock: an NTP step or a laptop-suspend resume jumped it by hours,
     * fast-forwarding thousands of frames in one tick (or freezing playback
     * when it stepped backwards). hrtime() cannot move. A hand-injected
     * $clock seam (openForTest) overrides the source so pacing tests script
     * time instead of seeding lastTickTime through reflection.
     */
    private function now(): float
    {
        $clock = $this->clock;

        return $clock !== null ? (float) $clock() : self::monotonicNow();
    }

    /**
     * Seconds on the monotonic base — what the static factories stamp a fresh
     * playhead with, and the default behind now().
     */
    private static function monotonicNow(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * Teardown latch set by {@see stop()}.
     *
     * WHY a plain mutable property rather than a promoted readonly field: stopping is
     * an event on the instance the host already holds, not a value the model is built
     * with — and a 26th constructor parameter would shift the positional argument list
     * every test helper assembles. mutate() copies it forward deliberately: a derived
     * instance owns the SAME decoder and audio companion that stop() already closed, so
     * nothing that happens after teardown may respawn them. That includes the
     * debounce timer, which is armed before the resize lands and would
     * otherwise spawn a fresh ffmpeg child onto a player the host has already torn down
     * — a leak the static child-lifetime roster cannot see, since the spawn site itself
     * is accounted for.
     */
    private bool $stopped = false;

    /**
     * Open a video file and return a Player ready for Program::run().
     *
     * Probes the video to get FPS and dimensions (SV-M39/039: an explicit
     * $fpsOverride skips the metadata probe outright), creates the
     * appropriate decoder, and returns a Player paused AT frame 0 — the
     * first advance pulls frame 1, matching the seek-path playhead exactly.
     *
     * Leaving $mode unset auto-detects the terminal's best rendering mode —
     * the same ladder {@see Reel::play()} resolves through, so both entry
     * points answer identically (single source: {@see RendererFactory::autoMode()}).
     *
     * @param string     $videoPath    Path to the video file (mp4, gif, avi, etc.)
     * @param int       $cellsW       Target terminal cell width
     * @param int       $cellsH       Target terminal cell height
     * @param float|null $fpsOverride FPS override; SV-M39/039: supplying it
     *                            skips the metadata probe ENTIRELY — no
     *                            ffprobe child, and as a consequence
     *                            totalFrames is unknown (0) so the stream
     *                            ends by decoder exhaustion, and no audio
     *                            companion is built (its presence is a
     *                            probe answer). Null = auto from the probe.
     * @param Mode|null  $mode         Rendering mode (decoder resolution matches it);
     *                                null (default) = probe the terminal and pick the
     *                                best available mode
     * @param bool       $loop         Restart from the beginning at end-of-stream instead of stopping
     * @param string     $ramp         Luma ramp name: 'minimal', 'standard', 'dense'
     * @param int        $cellPxW      Pixel width of a terminal cell — graphics modes decode at
     *                                 cells·cellPx for full resolution (caller probes it; 10×20 default)
     * @param int        $cellPxH      Pixel height of a terminal cell
     * @param WebVtt|null $subtitles  Parsed subtitle track, or null when no subtitles
     * @param array<array-key, string> $headers HTTP request headers for a remote
     *                            http(s) source, re-presented on every decoder rebuild
     * @param float|null   $frameBudgetMs Wall-clock budget (ms) for one tick's catch-up
     *                            decode before it defers the rest to the next tick.
     *                            SV-M19: null = the default budget max(50ms, 2 × frame
     *                            interval); an explicit 0.0 keeps the old unbounded
     *                            single-tick walk (the parity hosts pass it).
     * @param (callable():Mode)|null $autoModeChooser Test seam consulted ONLY when
     *                            $mode is null: supplies the resolved auto-mode
     *                            without probing the live terminal, so the default
     *                            path stays hermetic under hostile CI environments
     *                            (TERM/COLORTERM churn). Default null = resolve via
     *                            {@see RendererFactory::autoMode()}.
     * @param bool         $hostRestricted true when the caller's Reel binds a host
     *                            allowlist: every network child (ffprobe probe,
     *                            ffmpeg decode — including all seek/resize rebuilds —
     *                            and the audio companion) is pinned to zero HTTP
     *                            redirects so a 302 cannot ride out of the allowlist
     *                            (SSRF transport law, see
     *                            {@see FfmpegCommandBuilder::ALLOWED_MAX_REDIRECTS}).
     *
     * @throws \InvalidArgumentException for a non-positive $fpsOverride.
     * @throws \RuntimeException for an EXPLICIT mode the seat cannot honestly
     *         render (no ext-gd for a graphics rung, or a protocol the
     *         terminal never claimed). SV-L66/066: this gate runs BEFORE any
     *         child is spawned, so a refused open leaks nothing; an AUTO
     *         (or chooser-resolved) mode never throws — the ladder steps
     *         down to the next rung that constructs.
     */
    public static function open(string $videoPath, int $cellsW, int $cellsH, ?float $fpsOverride = null, ?Mode $mode = null, bool $loop = false, string $ramp = 'standard', int $cellPxW = 10, int $cellPxH = 20, ?WebVtt $subtitles = null, array $headers = [], ?float $frameBudgetMs = null, ?\Closure $autoModeChooser = null, bool $hostRestricted = false): self
    {
        // m11: every pacing site divides by fps (Cmd::tick(1.0/fps)); refuse a
        // non-positive override at the boundary before a Model can exist. The
        // guard runs FIRST so a bad fps is reported as itself, not after an
        // unrelated probe failure on the same path.
        if ($fpsOverride !== null && $fpsOverride <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('player.fps_positive'));
        }

        // SV-H7/007: the documented cols [10,200] x rows [5,80] window binds
        // at EVERY entry, not just the resize path — an unclamped open() is a
        // multi-megabyte view() per frame (QA measured 100,000,056 bytes at
        // 9999x9999) and a memory DoS one constructor call deep.
        $narrowCols = $cellsW < self::MIN_COLS ? $cellsW : null; // SV-PGEO/068
        [$cellsW, $cellsH] = self::clampCells($cellsW, $cellsH);

        // Null mode = auto-detect, resolved once here at the boundary so every
        // downstream consumer (decoder geometry, renderer, mode cycling) trusts
        // a concrete Mode. The chooser is the hermetic test seam; production
        // callers leave it null and get the live probe.
        $explicitMode = $mode !== null;
        $mode ??= $autoModeChooser !== null ? $autoModeChooser() : RendererFactory::autoMode();

        // SV-L66/066: renderer construction IS the capability gate (ext-gd
        // for the graphics rungs, terminal support for all) — build it here,
        // BEFORE any child is spawned, so a refused open leaves zero procs
        // for __destruct to sweep later (the finding's real fix: the gate
        // used to run after probe + the decode child already existed).
        // The split on refusal is deliberate: an EXPLICIT mode is a promise
        // the caller made, so it stays a loud throw; an AUTO-resolved mode
        // is a suggestion, so the ladder steps down to the next rung that
        // can honestly render instead of failing the whole seat.
        try {
            $renderer = RendererFactory::new($mode, $ramp, $cellPxW, $cellPxH);
        } catch (\RuntimeException $refusal) {
            if ($explicitMode) {
                throw $refusal;
            }
            [$mode, $renderer] = self::degradeAutoMode($mode, $ramp, $cellPxW, $cellPxH, $refusal);
        }

        // SV-M39/039: an explicit fps override is the caller declaring they
        // know the stream. fps is then the ONLY probe product the Player
        // still consumes — duration feeds totalFrames and hasAudio feeds the
        // audio companion, both of which the override caller has implicitly
        // opted out of by refusing to wait for metadata — so the ffprobe
        // child is skipped entirely rather than spawned and ignored. The
        // documented trade (see @param): totalFrames stays 0 and end-of-
        // stream answers by decoder exhaustion, and no audio companion is
        // built. Callers wanting the timeline and audio leave it null.
        $source = $fpsOverride !== null
            ? VideoSource::unprobed($videoPath)
            : VideoSource::probe($videoPath, $hostRestricted);

        // FPS from probe, default 24 if not available. Use override when set.
        $fps = $fpsOverride ?? ($source->fps > 0.0 ? $source->fps : 24.0);

        // Compute totalFrames from duration and fps when both are known.
        $totalFrames = ($source->duration > 0.0 && $fps > 0.0)
            ? (int)round($source->duration * $fps) : 0;

        // Decoder resolution is keyed to the render mode (HalfBlock decodes at
        // 2× cell height; graphics modes at cells·cellPx). Seek recreates the
        // decoder with the current mode and the same cell geometry.
        $decoder = DecoderFactory::new($videoPath, $cellsW, $cellsH, $fps, $mode, 0.0, $cellPxW, $cellPxH, $headers, $hostRestricted);

        // Audio companion factory: creates AudioPlayer on demand. The factory
        // is threaded through the Player so tests can inject a spy subclass.
        // It takes the header map because ffplay/mpv fetch the
        // audio track from the SAME remote URL as the video: a signed stream
        // whose token rides in the request headers is playable for video and
        // 403-silent for audio unless the headers are re-presented here too.
        $audioFactory = self::defaultAudioFactory();

        // Audio companion is created when the source has audio but is NOT
        // started here — the Player starts it on first play (see updateKey)
        // so audio and the video wall clock share the same t0.
        $audioPlayer = $source->hasAudio ? $audioFactory($videoPath, null, $headers, $hostRestricted) : null;

        // SV-L66/066: the renderer was already constructed above — the
        // capability gate runs BEFORE the probe and the decode child, so a
        // refused open never spawned anything. The renderer is rebuilt on
        // mode change or resize via mutate(['renderer' => ...]).

        // M23: pull one frame at open so the paused start screen shows real
        // content instead of the 'loading...' claim. Nothing auto-plays, which is
        // also how a REDUCE_MOTION host is honoured — the screen waits for an
        // explicit command. A source whose child already died fails HERE, loudly.
        $poster = null;
        $posterFailure = null;
        try {
            $poster = $decoder->next();
        } catch (FfmpegDecodeFailure $failure) {
            $poster = null;
            $posterFailure = Sanitize::untrusted($failure->getMessage());
        }

        // SV-M39/039: the poster pull IS frame 0 — after it the decoder
        // cursor sits one PAST the grabbed content, which is precisely the
        // invariant every other playhead update maintains ("currentFrame is
        // the frame at frameIndex; the next pull belongs to frameIndex+1" —
        // true for rebuildDecoderAt's walk landings, for the seconds route,
        // and for every openForTest double). The old open() then REOPENED to
        // rewind: a full close plus a SECOND decode child (for a factory-
        // owned source a fresh ffmpeg with ≤1.5s grace reap, for a network
        // source a cold re-establish of the whole fetch) whose only product
        // was letting the first advance replay frame 0 — desyncing natural
        // playback one content frame behind the index every seek reports.
        // Retaining the grabbed frame deletes the reopen: one decode child
        // per open, first paint byte-identical, and the first tick now
        // shows frame 1 — the frame the playhead actually promises.

        // Player starts paused; the first tick is scheduled when playback begins.
        // SV-M19: an unset budget is no longer "walk the whole catch-up
        // synchronously" — open() defaults one tick to at most
        // max(50ms, 2 frame intervals) of decode work before yielding the
        // remainder to the next tick. 0.0 stays the documented opt-out, and
        // the hand-built entries (fromDecoder/openForTest) keep their old
        // unbounded default so parity fixtures are untouched.
        $frameBudgetMs ??= max(self::DEFAULT_FRAME_BUDGET_FLOOR_MS, 2000.0 / max(0.001, $fps));

        return new self(
            decoder: $decoder,
            mode: $mode,
            speed: 1.0,
            paused: true,
            videoTime: 0.0,
            frameIndex: 0,
            currentFrame: $poster,
            lastTickTime: self::monotonicNow(),
            fps: $fps,
            totalFrames: $totalFrames,
            cellsW: $cellsW,
            cellsH: $cellsH,
            videoPath: $videoPath,
            audioPlayer: $audioPlayer,
            ended: $posterFailure !== null,
            loop: $loop,
            ramp: $ramp,
            audioFactory: $audioFactory,
            cellPxW: $cellPxW,
            cellPxH: $cellPxH,
            subtitles: $subtitles,
            renderer: $renderer,
            headers: $headers,
            hostRestricted: $hostRestricted,
            frameBudgetMs: $frameBudgetMs,
            failure: $posterFailure,
            narrowCols: $narrowCols, // SV-PGEO/068
        );
    }

    /**
     * SV-L66/066: the auto-ladder step-down when the terminal's best rung
     * cannot honestly be built (no ext-gd for a graphics seat, or a rung the
     * renderer door rejects for reasons the capability report missed).
     * Walks the remaining rungs in descending-preference order — reversed
     * Mode::cases() puts the graphics protocols above the block rungs and
     * the block rungs above the palette rungs, matching the ladder's own
     * taste — and takes the first rung {@see RendererFactory::new()}
     * accepts. If EVERY rung refuses, the original refusal is rethrown:
     * degrading must not swallow the report of why the seat is broken.
     *
     * @return array{0: Mode, 1: FrameRenderer}
     */
    private static function degradeAutoMode(Mode $refused, string $ramp, int $cellPxW, int $cellPxH, \RuntimeException $refusal): array
    {
        foreach (array_reverse(Mode::cases()) as $rung) {
            if ($rung === $refused) {
                continue;
            }
            try {
                return [$rung, RendererFactory::new($rung, $ramp, $cellPxW, $cellPxH)];
            } catch (\RuntimeException) {
                // This rung is unusable too — step down.
            }
        }

        throw $refusal;
    }

    /**
     * The blessed public seam for driving a Player from any Decoder implementation.
     *
     * Use this to mount playback over a frame source that is NOT a file on disk —
     * most importantly a server-transcoded stream (raw RGB24 delivered over a
     * socket, wrapped in a small Decoder of the host's own), and equally for a
     * test double that yields canned frames. It bypasses {@see VideoSource::probe()}
     * and {@see DecoderFactory} entirely: the caller supplies the decoder, the
     * geometry, the frame rate and the render mode, and the Player trusts them.
     *
     * Because the source has no rebuildable path behind it, the decoder SHOULD
     * answer {@see Decoder::reopensInPlace()} true so a seek/resize/mode-change
     * re-opens it in place; a decoder that answers false will be handed to
     * DecoderFactory with $videoPath on rebuild (correct only when that path is a
     * real file/URL the factory can re-open).
     *
     * @param Decoder  $decoder       Frame source the Player pulls next() from.
     * @param int      $cellsW        Terminal cell width the decoder targets.
     * @param int      $cellsH        Terminal cell height the decoder targets.
     * @param float    $fps           Frames per second for pacing.
     * @param Mode     $mode          Rendering mode (a renderer is built for it).
     * @param int      $totalFrames   Total frame count (0 = unknown/stream).
     * @param string   $videoPath     Rebuild source path for a factory-owned
     *                                decoder; '' for a pure injected stream.
     * @param bool     $loop          Restart from frame 0 at end-of-stream.
     * @param string   $ramp          Luma ramp name: 'minimal', 'standard', 'dense'.
     * @param \Closure|null $audioFactory Factory fn(string $path, ?int $startMs, array $headers, bool $hostRestricted): AudioPlayer.
     * @param AudioPlayer|null $audioPlayer Initial audio companion (null = silent).
     * @param bool     $paused        Start paused (default true).
     * @param int      $cellPxW       Pixel width of one terminal cell.
     * @param int      $cellPxH       Pixel height of one terminal cell.
     * @param WebVtt|null $subtitles  Parsed subtitle track, or null.
     * @param array<array-key, string> $headers HTTP request headers for a remote source.
     * @param float    $frameBudgetMs Bounded per-tick catch-up decode (ms); 0 = unbounded.
     * @param \Closure|null $clock SV-M20/020: scripted seconds source for pacing
     *                            tests (the openForTest seam); null = the monotonic
     *                            hrtime base. Never feed wall-clock values and the
     *                            scripted values alternately — one playhead base
     *                            must hold for a player's whole life.
     */
    public static function fromDecoder(
        Decoder $decoder,
        int $cellsW = 80,
        int $cellsH = 24,
        float $fps = 24.0,
        Mode $mode = Mode::HalfBlock,
        int $totalFrames = 0,
        string $videoPath = '',
        bool $loop = false,
        string $ramp = 'standard',
        ?\Closure $audioFactory = null,
        ?AudioPlayer $audioPlayer = null,
        bool $paused = true,
        int $cellPxW = 10,
        int $cellPxH = 20,
        ?WebVtt $subtitles = null,
        array $headers = [],
        float $frameBudgetMs = 0.0,
        ?\Closure $clock = null,
    ): self {
        // m11: same boundary law as open() — a hand-built Player with fps <= 0
        // would throw DivisionByZeroError from its first scheduled tick.
        if ($fps <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('player.fps_positive'));
        }

        // SV-H7/007: the pane window binds here too — fromDecoder is the
        // entry every Reel build and the test seams walk through, so the
        // 9999x9999 DoS constructor is refused at the same door as open().
        $narrowCols = $cellsW < self::MIN_COLS ? $cellsW : null; // SV-PGEO/068
        [$cellsW, $cellsH] = self::clampCells($cellsW, $cellsH);

        // The header map is validated HERE, at this public boundary: every other entry point — Reel::open/openUrl, the ffmpeg
        // decoder's open() — parses before trusting, and the deferred
        // rebuildAudio() would otherwise let a malformed map throw mid-`update()`
        // from deep inside the companion factory, escaping the TEA pipeline.
        HttpHeaders::parse($headers);

        $factory = $audioFactory ?? self::defaultAudioFactory();

        // The renderer follows the mode — including the direct-render image
        // protocols — so a host mounting a graphics stream gets the matching
        // FrameRenderer instead of a HalfBlock one that would ignore the mode.
        $renderer = RendererFactory::new($mode, $ramp, $cellPxW, $cellPxH);

        return new self(
            decoder: $decoder,
            mode: $mode,
            speed: 1.0,
            paused: $paused,
            videoTime: 0.0,
            frameIndex: 0,
            currentFrame: null,
            lastTickTime: $clock !== null ? (float) $clock() : self::monotonicNow(),
            fps: $fps,
            totalFrames: $totalFrames,
            cellsW: $cellsW,
            cellsH: $cellsH,
            videoPath: $videoPath,
            audioPlayer: $audioPlayer,
            ended: false,
            loop: $loop,
            ramp: $ramp,
            audioFactory: $factory,
            cellPxW: $cellPxW,
            cellPxH: $cellPxH,
            subtitles: $subtitles,
            renderer: $renderer,
            headers: $headers,
            frameBudgetMs: $frameBudgetMs,
            clock: $clock,
            narrowCols: $narrowCols, // SV-PGEO/068
        );
    }

    /**
     * Factory for tests: create a Player with an injected decoder.
     *
     * Bypasses VideoSource::probe() and DecoderFactory so tests can use
     * a FakeDecoder that yields canned RgbFrame objects without any
     * real video file or external process.
     *
     * @param Decoder      $decoder       Frame source iterator (e.g. FakeDecoder)
     * @param float        $fps            Frames per second
     * @param int          $totalFrames    Total frame count (0 if unknown/stream)
     * @param int          $cellsW         Terminal cell width
     * @param int          $cellsH         Terminal cell height
     * @param string       $videoPath      Fake path for seeking (default '/fake')
     * @param bool         $loop           Restart from frame 0 at end-of-stream instead of stopping
     * @param string       $ramp           Luma ramp name: 'minimal', 'standard', 'dense'
     * @param \Closure     $audioFactory   Factory fn(string $path, ?int $startMs, array $headers): AudioPlayer
     * @param AudioPlayer  $audioPlayer   Optional initial AudioPlayer instance (for spy injection)
     * @param bool         $paused         Start paused (default true)
     * @param WebVtt|null  $subtitles      Parsed subtitle track for testing
     *
     * @deprecated Use {@see fromDecoder()} — the blessed public seam, which takes
     *             the render mode as an argument instead of hardcoding HalfBlock.
     *             This alias is retained for existing tests and simply forwards.
     */
    public static function openForTest(
        Decoder $decoder,
        float $fps,
        int $totalFrames = 0,
        int $cellsW = 80,
        int $cellsH = 24,
        string $videoPath = '/fake',
        bool $loop = false,
        string $ramp = 'standard',
        ?\Closure $audioFactory = null,
        ?AudioPlayer $audioPlayer = null,
        bool $paused = true,
        int $cellPxW = 10,
        int $cellPxH = 20,
        ?WebVtt $subtitles = null,
        ?\Closure $clock = null,
    ): self {
        return self::fromDecoder(
            decoder: $decoder,
            cellsW: $cellsW,
            cellsH: $cellsH,
            fps: $fps,
            mode: Mode::HalfBlock,
            totalFrames: $totalFrames,
            videoPath: $videoPath,
            loop: $loop,
            ramp: $ramp,
            audioFactory: $audioFactory,
            audioPlayer: $audioPlayer,
            paused: $paused,
            cellPxW: $cellPxW,
            cellPxH: $cellPxH,
            subtitles: $subtitles,
            clock: $clock,
        );
    }

    /**
     * @return null|\Closure
     */
    public function init(): ?\Closure
    {
        // If paused, schedule no tick; keyboard input will unpause.
        if ($this->paused) {
            return null;
        }
        return Cmd::tick(1.0 / $this->fps, static fn (): Msg => TickMsg::instance());
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     *
     * M12/M3 single choke point: a crashed ffmpeg surfaces as a typed
     * {@see FfmpegDecodeFailure} from deep inside the pump/seek paths. It is
     * converted here — every decoder call site at once — into an ended player
     * carrying the failure text, instead of either killing the TEA loop with
     * an uncaught exception or (the old behaviour) sliding silently into
     * "[ended]". Exceptions of any other kind still propagate untouched.
     */
    public function update(Msg $msg): array
    {
        try {
            return $this->updateModel($msg);
        } catch (FfmpegDecodeFailure $failure) {
            return [$this->onDecodeFailure($failure->getMessage()), null];
        }
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    private function updateModel(Msg $msg): array
    {
        // TickMsg: advance frame based on wall-clock sync.
        if ($msg instanceof TickMsg) {
            return $this->updateTick();
        }

        // KeyMsg: pause, seek, speed, mode, quit.
        if ($msg instanceof KeyMsg) {
            return $this->updateKey($msg);
        }

        // F10: WindowSizeMsg — resize the player cell dimensions and rebuild
        // the decoder so frames are decoded at the correct resolution.
        if ($msg instanceof WindowSizeMsg) {
            return $this->updateResize($msg->cols, $msg->rows);
        }

        // Why this message exists: the debounce window armed by updateResize() elapsed.
        if ($msg instanceof ResizeRebuildMsg) {
            return $this->applyPendingResize();
        }

        return [$this, null];
    }

    /**
     * Handle a TickMsg: compute wall-clock delta, sync to target frame,
     * skip/hold/advance the decoder, and reschedule the next tick.
     *
     * @return array{0: Model, 1: ?\Closure}
     */
    private function updateTick(): array
    {
        if ($this->stopped) {
            // Same owner rule as applyPendingResize(): stop() closed the decoder child this instance owned;
            // no in-flight tick — least of all a looping player's end-of-stream
            // reopen — may spawn a replacement with no one left to reap it.
            return [$this, null];
        }

        if ($this->failure !== null) {
            // M12: a failed session is terminal FOR THE TICK PUMP — the
            // decoder child it died with is closed and nothing here may
            // respawn it behind the error row. Recovery is a SEEK, and the
            // seek arms genuinely clear the row now (SV-H4/004): withSeek()
            // and seekToSeconds() rebuild from the source path and pass
            // 'failure' => null, so the fresh child feeds a live frame
            // instead of freezing under a stale error.
            return [$this, null];
        }

        if ($this->paused) {
            return [$this, null];
        }

        $now = $this->now();
        // SV-M20/045: [0, ceiling] by construction. The monotonic base makes a
        // negative skew impossible on the default clock; the clamp still binds
        // a hand-fed seam and any pathological stall, so no tick can ever
        // burst more than MAX_TICK_DELTA_SEC of content time.
        $delta = min(max(0.0, $now - $this->lastTickTime), self::MAX_TICK_DELTA_SEC);

        // F4 fix: videoTime accumulates delta*speed — speed change only affects
        // FUTURE pacing, never retroactively rescales prior content time.
        $newVideoTime = $this->videoTime + $delta * $this->speed;
        $target = Sync::targetFrame($newVideoTime, $this->fps);

        // Decide skip / hold / advance using the tested Sync engine.
        $nextFrame = $this->currentFrame;
        $nextIndex = $this->frameIndex;
        // Set when the decoder is exhausted this tick (null from next()), so we
        // can stop ticking (or loop) rather than spinning forever at the end.
        $reachedEnd = false;

        if (Sync::shouldSkip($this->frameIndex, $target)) {
            // Behind by more than the skip limit — discard intermediate frames,
            // keeping only the last one decoded, to catch up without lag.
            //
            // Why bounded now: the decode-forward was once unbounded, so a big catch-up
            // (a long seek, or stalling decode) blocked the whole TEA update
            // inside fread() until ffmpeg caught up — freezing input and redraw.
            // The loop now watches the wall clock and stops at $frameBudgetMs,
            // leaving the decoder ahead-of-screen but *converging*: the next tick
            // resumes the skip from the new frameIndex. videoTime tracks the
            // true content position, so playback stays A/V-correct across the
            // multi-tick catch-up; only intermediate frames go unrendered (which
            // is exactly what skipping means). frameBudgetMs <= 0 keeps the old
            // single-tick behaviour for hosts that want it.
            $skipCount = $target - $this->frameIndex;
            $deadline = $this->frameBudgetMs > 0.0
                ? $now + ($this->frameBudgetMs / 1000.0)
                : null;
            for ($i = 0; $i < $skipCount; $i++) {
                $frame = $this->decoder->next();
                if ($frame === null) {
                    $reachedEnd = true;
                    break;
                }
                $nextFrame = $frame;
                $nextIndex++;
                // Check the budget after each frame so we never abandon the tick
                // having decoded nothing (progress is guaranteed by the first read).
                if ($deadline !== null && $i + 1 < $skipCount && $this->now() >= $deadline) {
                    // Out of budget with frames still to skip: yield to the event
                    // loop and finish the catch-up on the next tick.
                    break;
                }
            }
        } elseif (Sync::shouldHold($this->frameIndex, $target)) {
            // Ahead of schedule — hold the current frame, advance nothing.
            // (Holding never reaches end — we have not asked the decoder for more.)
        } else {
            // On schedule — advance by one frame.
            $frame = $this->decoder->next();
            if ($frame !== null) {
                $nextFrame = $frame;
                $nextIndex++;
            } else {
                $reachedEnd = true;
            }
        }

        if ($reachedEnd) {
            return $this->onReachedEnd($nextFrame, $nextIndex, $newVideoTime, $now);
        }

        $nextPlayer = $this->withNewFrame($nextFrame, $nextIndex, $this->decoder, $newVideoTime, $now);

        $cmd = $nextPlayer->paused
            ? null
            : Cmd::tick(1.0 / $this->fps, static fn (): Msg => TickMsg::instance());

        return [$nextPlayer, $cmd];
    }

    /**
     * Handle a WindowSizeMsg: clamp, no-op if unchanged, otherwise record the
     * geometry and DEFER the decoder rebuild behind a debounce timer.
     *
     * Why deferred: a rebuild closes the running ffmpeg child and
     * spawns another at the new resolution, then decodes forward to the current
     * playhead — the single most expensive thing this model does, and on master it
     * ran synchronously inside `update()`. SIGWINCH does not arrive once per resize,
     * it arrives once per reflow: dragging a terminal edge delivers a burst of
     * dozens, so a synchronous rebuild paid that cost dozens of times to display
     * intermediate sizes nobody sees. `update()` therefore does the cheap part only
     * (stash the clamped geometry) and arms a one-shot timer; {@see applyPendingResize()}
     * does the expensive part when the burst settles.
     *
     * WHAT THIS DOES NOT CHANGE: the rebuild is still synchronous work performed
     * inside `update()` when the deferred message arrives, so the event loop still
     * stalls for the length of one spawn. The win is cost, not concurrency — N reflows
     * in a burst collapse to 1 stall instead of N. Off-loop decoding is the remaining
     * half of the resize-cost fix and is still open.
     *
     * @return array{0:Player, 1:?Closure}
     */
    /**
     * SV-H7/007: clamp a cell geometry into the documented pane window —
     * cols in [MIN_COLS, MAX_COLS], rows in [MIN_ROWS, MAX_ROWS]. Every entry
     * that accepts a host-supplied size (open, fromDecoder, updateResize,
     * Reel::withSize) routes through here so the window is single-sourced:
     * the frame block view() builds is cellsH lines x cellsW columns, and an
     * unclamped pane is a multi-megabyte string per frame — a memory DoS at
     * one constructor call, not a rendering curiosity.
     *
     * @return array{0: int, 1: int} the clamped [cols, rows] pair
     */
    public static function clampCells(int $cols, int $rows): array
    {
        return [
            max(self::MIN_COLS, min($cols, self::MAX_COLS)),
            max(self::MIN_ROWS, min($rows, self::MAX_ROWS)),
        ];
    }

    private function updateResize(int $cols, int $rows): array
    {
        if ($this->stopped) {
            // Teardown already happened; arming a debounce timer for a decoder that
            // must never reopen would only schedule the resurrection applyPendingResize()
            // refuses.
            return [$this, null];
        }

        $narrowCols = $cols < self::MIN_COLS ? $cols : null; // SV-PGEO/068
        [$cols, $rows] = self::clampCells($cols, $rows);

        // Compare against the *pending* geometry, not just the applied one: during a
        // burst each event re-states the size, and only a genuinely new target needs
        // a fresh timer. Re-arming for an identical target would only add another
        // message that applies nothing.
        $pending = $this->pendingResize;
        if ($cols === $this->cellsW && $rows === $this->cellsH) {
            // Already decoding at this size. Drop any deferred target: the terminal
            // snapped back mid-burst, and the timer armed by the earlier event is
            // still in flight. Cancelling here is what makes that timer harmless —
            // otherwise it fires and rebuilds the decoder at a geometry the host no
            // longer has, and nothing later corrects it.
            //
            // SV-PGEO/068: the floor state still refreshes on this path — a 6-col
            // terminal clamps to the pane's 10 but genuinely CANNOT hold a line,
            // and an 8-col event must restate the refusal with the real number.
            if ($pending === null && $narrowCols === $this->narrowCols) {
                return [$this, null];
            }

            return [$this->mutate(['pendingResize' => null, 'narrowCols' => $narrowCols]), null];
        }

        if ($pending !== null && $pending->cols === $cols && $pending->rows === $rows) {
            if ($narrowCols === $this->narrowCols) {
                return [$this, null];
            }

            return [$this->mutate(['narrowCols' => $narrowCols]), null];
        }

        // Stash the clamped target so the rebuild honours the same bounds a
        // synchronous path would have, and so `view()` keeps painting the current
        // (slightly stale) frame instead of a black box while the burst settles.
        // The refusal answers the host NOW (its screen is already too small for
        // the stale frame on it), not after the debounce lands.
        $nextPlayer = $this->mutate([
            'pendingResize' => new WindowSizeMsg($cols, $rows),
            'narrowCols' => $narrowCols,
        ]);

        return [$nextPlayer, Cmd::tick(self::RESIZE_DEBOUNCE_SECONDS, static fn (): Msg => ResizeRebuildMsg::instance())];
    }

    /**
     * Apply the pending resize recorded by {@see updateResize()} — the debounced
     * second half of a `WindowSizeMsg`.
     *
     * Rebuilds the decoder at the new cell dimensions, swaps in a renderer for the
     * new grid, and reschedules the tick chain exactly as the old synchronous path
     * did; only the *timing* moved. Every `ResizeRebuildMsg` whose geometry has
     * already been applied (or cancelled) finds nothing pending and no-ops, which is
     * what makes a burst of N armed timers cost one rebuild rather than N.
     *
     * @return array{0:Player, 1:?Closure}
     */
    private function applyPendingResize(): array
    {
        if ($this->stopped) {
            // The host already tore this stream down; the decoder child this message
            // would reopen no longer has an owner. Nothing to cancel — just refuse.
            return [$this, null];
        }

        $pending = $this->pendingResize;
        if ($pending === null) {
            // Superseded by a later resize that already landed, or the terminal
            // snapped back to the current size. No spawn, no re-render.
            return [$this, null];
        }

        $cols = $pending->cols;
        $rows = $pending->rows;

        [$decoder, $frame, $landed] = $this->rebuildDecoderAt($cols, $rows, $this->mode, $this->frameIndex);

        // Rebuild the renderer to match the new cell dimensions (same mode/ramp/cellPx),
        // and clear the pending slot in the same mutate() so the apply is atomic: no
        // Player instance ever shows the new grid while still owning the old decoder.
        $nextPlayer = $this->mutate([
            'cellsW' => $cols,
            'cellsH' => $rows,
            'pendingResize' => null,
            'decoder' => $decoder,
            'currentFrame' => $frame ?? $this->currentFrame,
            // The rebuild may have yielded mid-walk on the frame budget; the
            // recorded playhead must be where the decoder ACTUALLY is.
            'frameIndex' => $landed,
            'videoTime' => $landed / $this->fps,
            'lastTickTime' => $this->now(),
            'renderer' => RendererFactory::new($this->mode, $this->ramp, $this->cellPxW, $this->cellPxH),
        ]);

        $cmd = $nextPlayer->paused
            ? null
            : Cmd::tick(1.0 / $this->fps, static fn (): Msg => TickMsg::instance());

        return [$nextPlayer, $cmd];
    }

    /**
     * Handle the decoder running out of frames during a tick.
     *
     * Non-loop: stop the audio, mark the player ended, and return a null Cmd so
     * the tick chain halts (the player no longer reschedules itself). Loop:
     * restart from frame 0 — {@see rebuildDecoderAt()} reopens the source in
     * place when the decoder reports {@see Decoder::reopensInPlace()} (a
     * synthetic in-memory stream resets its index), or closes and re-creates it
     * through the factory for a real file — and audio restarts from t0.
     *
     * @return array{0: Model, 1: ?\Closure}
     */
    private function onReachedEnd(?RgbFrame $nextFrame, int $nextIndex, float $newVideoTime, float $now): array
    {
        $tick = Cmd::tick(1.0 / $this->fps, static fn (): Msg => TickMsg::instance());

        if (!$this->loop) {
            // End of stream, no loop: stop audio and freeze on the last frame.
            $this->audioPlayer?->stop();
            // SV-L91/091: the F21 house law — a child dies with the purpose
            // that kept it alive. The parked end view needs NO decoder: it
            // paints the retained last frame from the model, and the [ended]
            // row is plain text. Leaving the ffmpeg child open here parked an
            // unreaped process slot plus its /tmp/sugar-video-ffmpeg-* stderr
            // temp for the whole lifetime of the view (destruct backstopped
            // it — bounded, but reason-less). close() is idempotent (every
            // handle is nulled on first tear), stop() staying able to re-run
            // it is what keeps this safe.
            $this->decoder->close();

            $nextPlayer = $this->mutate([
                'ended' => true,
                'currentFrame' => $nextFrame ?? $this->currentFrame,
                'frameIndex' => $nextIndex,
                'videoTime' => $newVideoTime,
                'lastTickTime' => $now,
            ]);

            return [$nextPlayer, null];
        }

        // Loop: rebuild the decoder from frame 0 and restart audio from t0 so
        // A/V stay aligned on the new pass. rebuildDecoderAt() handles both the
        // factory-owned path (close + DecoderFactory) and the reopen-in-place
        // path (re-open the injected decoder, which resets it to frame 0) under
        // one capability check — no '/fake' string comparison here.
        [$newDecoder, $firstFrame] = $this->rebuildDecoderAt($this->cellsW, $this->cellsH, $this->mode, 0);

        // Restart the audio companion from t0 through the shared rebuild seam.
        $newAudio = $this->rebuildAudio(0);

        $nextPlayer = $this->mutate([
            'decoder' => $newDecoder,
            'currentFrame' => $firstFrame ?? $this->currentFrame,
            'frameIndex' => 0,
            'videoTime' => 0.0,
            'lastTickTime' => $now,
            'ended' => false,
            'audioPlayer' => $newAudio,
        ]);

        return [$nextPlayer, $tick];
    }

    /**
     * Handle a KeyMsg: pause/resume, seek, speed, mode cycle, quit.
     *
     * @return array{0: Model, 1: ?\Closure}
     */
    private function updateKey(KeyMsg $msg): array
    {
        // Quit: Escape, q, or ctrl+c. Same teardown a host screen performs via
        // stop() — stop the audio companion and close the decoder (killing its
        // ffmpeg child) — then ask sugar-core to exit the program.
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q')
            || ($msg->ctrl && $msg->rune === 'c')) {
            $this->stop();
            return [$this, Cmd::quit()];
        }

        // Space: toggle pause/resume.
        if ($msg->type === KeyType::Space) {
            $resuming = $this->paused;
            $changes = ['paused' => !$this->paused];

            if ($resuming) {
                // Re-anchor the wall clock to now so $elapsed accumulates
                // play-time only — not time spent on the start screen or paused.
                $changes['lastTickTime'] = $this->now();
                // Start audio on first play; resume it on later unpauses, so it
                // shares the video's t0 and stays roughly in sync.
                if ($this->audioPlayer !== null) {
                    if ($this->audioPlayer->hasStarted()) {
                        $this->audioPlayer->resume();
                    } else {
                        $this->audioPlayer->start();
                    }
                }
            } else {
                // Pause audio alongside video so they realign on resume.
                $this->audioPlayer?->pause();
            }

            $nextPlayer = $this->mutate($changes);
            $cmd = $nextPlayer->paused
                ? null
                : Cmd::tick(1.0 / $this->fps, static fn (): Msg => TickMsg::instance());
            return [$nextPlayer, $cmd];
        }

        // ← : seek backward 10 frames.
        if ($msg->type === KeyType::Left) {
            $nextIndex = max(0, $this->frameIndex - 10);
            $nextPlayer = $this->withSeek($nextIndex);
            return [$nextPlayer, $this->seekTickCmd($nextPlayer)];
        }

        // → : seek forward 10 frames.
        if ($msg->type === KeyType::Right) {
            // SV-H4/090: clamp to the terminal playhead when the length is
            // known (digit-seek already guarded; arrows did not). A fully
            // consumed stream sits at frameIndex == totalFrames — one PAST the
            // last 0-based content index — so that is the ceiling: at the end
            // the seek is then a same-index no-op, no respawned audio, no
            // half-dead "playing" state past EOF.
            $nextIndex = $this->totalFrames > 0
                ? min($this->frameIndex + 10, $this->totalFrames)
                : $this->frameIndex + 10;
            $nextPlayer = $this->withSeek($nextIndex);
            return [$nextPlayer, $this->seekTickCmd($nextPlayer)];
        }

        // [ : decrease speed (min 0.25).
        if ($msg->type === KeyType::Char && $msg->rune === '[') {
            $nextSpeed = max(0.25, $this->speed - 0.25);
            $nextPlayer = $this->mutate(['speed' => $nextSpeed]);
            return [$nextPlayer, null];
        }

        // ] : increase speed (max 4.0).
        if ($msg->type === KeyType::Char && $msg->rune === ']') {
            $nextSpeed = min(4.0, $this->speed + 0.25);
            $nextPlayer = $this->mutate(['speed' => $nextSpeed]);
            return [$nextPlayer, null];
        }

        // 0–9 : seek to percentage of duration (0=0%, 9=90%).
        // Guard: can't percent-seek an unknown-length stream.
        if ($msg->type === KeyType::Char && ctype_digit($msg->rune)) {
            if ($this->totalFrames <= 0) {
                return [$this, null];
            }
            $percent = (int)$msg->rune * 10;
            $nextIndex = (int)(($percent / 100.0) * $this->totalFrames);
            $nextPlayer = $this->withSeek($nextIndex);
            return [$nextPlayer, $this->seekTickCmd($nextPlayer)];
        }

        // m : cycle rendering mode through ONLY the modes the terminal supports.
        // The candidate list comes from the SAME single capability door every
        // renderer construction consults (RendererFactory::supports(), backed
        // by one memoized probe — M28), so cycle and door cannot disagree.
        if ($msg->type === KeyType::Char && $msg->rune === 'm') {
            // Always present: text modes.
            $textModes = [Mode::Ascii, Mode::Ansi256, Mode::TrueColor, Mode::HalfBlock, Mode::QuarterBlock];
            $graphicsModes = array_values(array_filter(
                [Mode::Sixel, Mode::Kitty, Mode::Iterm2],
                static fn (Mode $candidate): bool => RendererFactory::supports($candidate),
            ));
            $allModes = array_merge($textModes, $graphicsModes);

            // Guard: if current mode is somehow not in the cycle, start from first.
            $currentIdx = array_search($this->mode, $allModes, true);
            if ($currentIdx === false) {
                $currentIdx = -1;
            }
            $nextMode = $allModes[($currentIdx + 1) % count($allModes)];

            // Rebuild the decoder ONLY when the new mode needs a different decode
            // geometry — the frames already in hand are exactly what an
            // equal-geometry mode renders, so re-spawning ffmpeg and decoding
            // forward to the current playhead would be pure churn.
            if (self::decodeGeometry($nextMode) === self::decodeGeometry($this->mode)) {
                return [$this->mutate([
                    'mode' => $nextMode,
                    'renderer' => RendererFactory::new($nextMode, $this->ramp, $this->cellPxW, $this->cellPxH),
                ]), null];
            }

            [$decoder, $frame, $landed] = $this->rebuildDecoderAt($this->cellsW, $this->cellsH, $nextMode, $this->frameIndex);

            $nextPlayer = $this->mutate([
                'mode' => $nextMode,
                'decoder' => $decoder,
                'currentFrame' => $frame ?? $this->currentFrame,
                // Budget-yielded walk: record the true landed playhead.
                'frameIndex' => $landed,
                'videoTime' => $landed / $this->fps,
                'renderer' => RendererFactory::new($nextMode, $this->ramp, $this->cellPxW, $this->cellPxH),
            ]);
            return [$nextPlayer, null];
        }

        return [$this, null];
    }

    /**
     * The decode geometry a rendering mode demands from its source, as an
     * opaque comparable signature.
     *
     * WHY this is the right reuse key: a decoder consults the mode
     * for exactly three things — `isGraphics()` (rawvideo rgb24 vs a PNG
     * image2pipe), `colsPerCell()` and `rowsPerCell()` (the pixel size of one
     * source cell). `FfmpegDecoder::open()` and `GifDecoder::open()` derive
     * `$graphics`/`$frameW`/`$frameH` from nothing else, so two modes with equal
     * signatures produce byte-identical decoder argv and an identical frame grid.
     * Ascii → Ansi256 → TrueColor therefore share one decoder process, and the
     * Sixel/Kitty/iTerm2 trio share theirs, while crossing into HalfBlock
     * (2 source rows per cell) or QuarterBlock (2×2) still rebuilds as before.
     *
     * @return string
     */
    private static function decodeGeometry(Mode $mode): string
    {
        return $mode->isGraphics()
            ? sprintf('graphics:%dx%d', $mode->colsPerCell(), $mode->rowsPerCell())
            : sprintf('text:%dx%d', $mode->colsPerCell(), $mode->rowsPerCell());
    }

    /**
     * Convert a decode failure into a terminal, LOUD player state (M12).
     *
     * The message is control-swept a second time here (belt: it was minted
     * through Sanitize on the decoder side) so whatever reaches view() — and
     * through it, the alternate-screen stdout — cannot carry escape material
     * regardless of which decoder variant produced the failure.
     */
    private function onDecodeFailure(string $message): self
    {
        $this->audioPlayer?->stop();
        $this->decoder->close();

        return $this->mutate([
            'ended' => true,
            'paused' => true,
            'failure' => trim(Sanitize::untrusted($message)),
        ]);
    }

    /**
     * Render the current frame to a full ANSI string.
     *
     * view() always emits the COMPLETE current frame, plus overlay rows
     * RESERVED out of the pane height (never appended below it — see the
     * SV-H5/005 budget in the body): the pane is exactly cellsH rows.
     *
     * view() always emits the COMPLETE current frame. sugar-core's Renderer
     * diffs it against the previously rendered frame and re-sends only the
     * rows that changed (row granularity by default; cell-granularity
     * diffing is opt-in through ProgramOptions), so there is intentionally
     * no diffing here — the framework diffs against the last rendered
     * OUTPUT, and a partial view() string would desync that comparison and
     * corrupt the screen.
     *
     * Ascii/TrueColor/HalfBlock/QuarterBlock render through a sugar-cellbuffer
     * Buffer (the cell grid the plan calls for; the quadrant compositor inside
     * frameToBuffer() folds each 2x2 pixel group into one quadrant-glyph cell);
     * Ansi256/Sixel/Kitty/Iterm2 render
     * directly through their FrameRenderer.
     *
     * Hosts that line-diff this output: ask {@see rendersWholeScreen()}
     * (SV-M34) — the three image rungs ship one indivisible raster paint;
     * every other rung, Ansi256 included, emits row-addressable lines.
     *
     * @return string
     */
    public function view(): string
    {
        // SV-PGEO/068: a below-floor terminal cannot hold even one pane row —
        // every line this model can emit soft-wraps and corrupts the whole
        // picture. Refuse in one honest line at the REAL width instead.
        if ($this->narrowCols !== null) {
            return self::clipToCells(Lang::t('player.terminal_too_small', [
                'cols' => (string) $this->narrowCols,
                'min' => (string) self::MIN_COLS,
            ]), $this->narrowCols);
        }

        $frame = $this->currentFrame;

        // If no frame yet (initial render before first tick), show a
        // placeholder rather than crashing.
        if ($frame === null) {
            return $this->renderPlaceholder();
        }

        // SV-H5/005: overlay budget. The pane is exactly cellsH rows; the
        // caption and the terminal status row are APPENDED rows, so a naive
        // append makes the frame over-tall and sugar-core's Renderer (which
        // has no height clamp) scrolls the alt screen on every repaint.
        // Overlays therefore RESERVE rows out of the frame block instead of
        // overlaying it: the picture loses bottom rows, the pane never gains
        // rows. Caption and status never collide — the [error]/[ended] row
        // prints only in the ended state and the caption row keeps its own
        // single slot — so at most two rows are ever withheld.
        $caption = $this->subtitles !== null
            ? WebVtt::sanitizeForTerminal((string) ($this->subtitles->cueAt($this->videoTime) ?? ''))
            : '';
        $hasCaption = $this->subtitles !== null && $caption !== '';
        $hasStatus = $this->ended;
        $reserve = ($hasCaption ? 1 : 0) + ($hasStatus ? 1 : 0);

        // Ansi256 (38;5;N) and the image protocols render directly — they are
        // not expressible through the truecolor-only Buffer cell grid.
        if ($this->mode === Mode::Sixel || $this->mode === Mode::Kitty || $this->mode === Mode::Iterm2 || $this->mode === Mode::Ansi256) {
            $out = $this->renderDirect($frame);
        } else {
            $out = $this->frameToBuffer($frame, $this->mode)->toAnsi();
        }

        // Text-rung rows clip at \r\n boundaries; the three graphics protocols
        // are excluded above, so a cut never splits escape data mid-sequence.
        if ($reserve > 0) {
            $out = self::clipToRows($out, max(1, $this->cellsH - $reserve));
        }

        // Subtitle overlay: the caption rides a reserved row at the pane's
        // bottom (left-aligned, padded to the cell width).
        if ($hasCaption) {
            // Egress re-assert of the WebVtt trust contract (idempotent on
            // text the parser already swept — done above).
            $caption = self::clipToCells($caption, $this->cellsW);
            $caption .= str_repeat(' ', max(0, $this->cellsW - Width::of($caption)));
            $out .= "\r\n" . $caption;
        }

        // At end-of-stream (non-loop) append a status line so the user can see
        // playback stopped and how to restart. Normal playback output is
        // unchanged, so this adds no per-frame snapshot churn.
        // The digit restart hint ("0 restart") is only shown when totalFrames > 0
        // because digit-seek is a no-op on streams of unknown length.
        if ($this->ended) {
            if ($this->failure !== null) {
                // M3: the loud terminal row. The message was control-swept at
                // both mint sites (FfmpegDecoder::describePath/stderrTail);
                // clipToCells keeps it one pane row whatever the terminal.
                $line = self::clipToCells(Lang::t('player.error_status', ['failure' => $this->failure]), $this->cellsW);
                return $out . "\r\n" . $line;
            }
            $hint = $this->totalFrames > 0 ? Lang::t('player.restart_hint') : Lang::t('player.quit_hint');
            return $out . "\r\n" . Lang::t('player.ended_status', ['hint' => $hint]);
        }

        return $out;
    }

    /**
     * Clip $text to at most $cells terminal display cells (East-Asian Width
     * aware — CJK/emoji cost 2, composing marks 0), never splitting a
     * GRAPHEME CLUSTER (SV-PPAINT/067: the old codepoint walk could cut a
     * ZWJ family or a flag mid-sequence and leave the head of it painting a
     * dangling half-glyph on caption/error rows — reachable with hostile
     * subtitle content). Segmentation rides {@see Width::nextCluster()},
     * the same clustering Width::of() itself sums over, so clip and width
     * can never disagree about what a cell costs. The byte-wise mb_strcut
     * this replaced counted neither.
     */
    private static function clipToCells(string $text, int $cells): string
    {
        if (Width::of($text) <= $cells) {
            return $text;
        }
        $out = '';
        $used = 0;
        $i = 0;
        $length = strlen($text);
        while ($i < $length) {
            $cluster = Width::nextCluster($text, $i);
            $width = Width::of($cluster);
            if ($used + $width > $cells) {
                break;
            }
            $out .= $cluster;
            $used += $width;
            $i += strlen($cluster);
        }

        return $out;
    }

    /**
     * Build a Buffer from an RgbFrame using the current rendering mode.
     *
     * For Ascii/Ansi256/TrueColor: each pixel maps to one Buffer cell
     * with the appropriate luma character and optional foreground color.
     *
     * For HalfBlock: each pair of vertically-adjacent pixels maps to
     * one Buffer cell with the upper pixel color as foreground and
     * lower pixel as background (via the ▀ character).
     *
     * For QuarterBlock: each 2x2 pixel group maps to one Buffer cell, the
     * four quadrant colors split into two groups drawn with a quadrant glyph
     * (fg = brighter group, bg = the other).
     */
    private function frameToBuffer(RgbFrame $frame, Mode $mode): Buffer
    {
        $w = $frame->w;
        $h = $frame->h;

        // Use cell dimensions from the frame's native size.
        // For HalfBlock/QuarterBlock, each cell spans 2 source rows.
        $cellDims = $this->detectCellDimensions();
        $cellW = $cellDims['w'];
        $cellH = $cellDims['h'];

        // Buffer cell grid dimensions.
        $bufW = (int)ceil($w / $cellW);
        $bufH = (int)ceil($h / $cellH);

        // SV-PGEO/069: the Decoder interface constrains no frame geometry — an
        // injected or lying decoder can hand us pixels wider/taller than the
        // pane, and emitting ceil(frame/cell) columns anyway is over-wide paint
        // no clamp catches. The pane is the contract: the grid is clipped to
        // cellsW×cellsH. Dev builds (SUGAR_VIDEO_DEBUG=1, or the testing seam
        // below) hear about the offender loudly instead of silently losing the
        // overflow — production degrades honestly by clipping.
        if ($bufW > $this->cellsW || $bufH > $this->cellsH) {
            if (self::geometryAsserts()) {
                throw new \LogicException(sprintf(
                    'decoder emitted %dx%d pixels into a %dx%d-cell pane (cell %dx%d): grid %dx%d exceeds the pane',
                    $w,
                    $h,
                    $this->cellsW,
                    $this->cellsH,
                    $cellW,
                    $cellH,
                    $bufW,
                    $bufH
                ));
            }

            $bufW = min($bufW, $this->cellsW);
            $bufH = min($bufH, $this->cellsH);
        }

        // Accumulate a flat row-major cell grid (index = cy*bufW + cx) and wrap
        // it in a single Buffer::fromGrid() call below. The previous per-cell
        // Buffer::withCellAt() loop copy-on-write duplicated the whole grid on
        // every pixel — O(cells²) per frame; appending to a plain array and
        // wrapping once is O(cells). Every cell is written exactly once (the
        // loops cover the full bufW×bufH range), so the resulting Buffer is
        // byte-identical to the old path.
        /** @var list<Cell> $grid */
        $grid = [];

        $bytes = $frame->bytes;
        $byteLen = strlen($bytes);

        // SV-PPAINT/071: a truncated RGB24 buffer used to paint BLACK in the
        // block rungs (pixelRgb's silent zero fallback) while the identical
        // corrupt frame was refused LOUDLY by the text rung's
        // ImageSource::fromRgb length assert — same defect, two behaviours.
        // One length check per frame, up front, for every rung that reads
        // raw bytes (a PNG frame carries its pixels in $png, not $bytes):
        // the typed FfmpegDecodeFailure the stream side already speaks.
        if ($frame->png === null && $byteLen !== $w * $h * 3) {
            throw new FfmpegDecodeFailure(Lang::t('decoder.frame_truncated', [
                'len' => (string) $byteLen,
                'w' => (string) $w,
                'h' => (string) $h,
                'want' => (string) ($w * $h * 3),
            ]));
        }

        if ($mode === Mode::HalfBlock) {
            // INLINE HalfBlock path: renders directly via Buffer → toAnsi().
            // This is the runtime path used by Player::view() for HalfBlock.
            // There is a SEPARATE HalfBlockRenderer (wired at RendererFactory)
            // that is NEVER reached by the Player runtime — only by direct
            // factory use / tests.
            //
            // Why the duplicate survives (deliberately NOT deleted):
            // it is the only executable specification of the mosaic contract this
            // renderer must keep, and the two paths do NOT emit identical bytes —
            // Buffer::toAnsi() writes one combined `0;38;2;r;g;b;48;2;r;g;bm` run
            // per cell while mosaic writes a reset-terminated fg/bg pair, and
            // mosaic resamples through GD. Routing view() at mosaic would rewrite
            // every HalfBlock snapshot and pull ext-gd (not a declared dependency
            // of this lib) onto the hot path. `tests/HalfBlockParityTest` therefore
            // compares the decoded per-cell (glyph, fg, bg) stream — the picture,
            // not the encoding — on every geometry a conforming decoder can emit,
            // and pins the one shape where they legitimately differ (odd pixel
            // height, which cellsH × rowsPerCell() cannot produce).
            //
            // HalfBlock: each cell shows 2 vertically-stacked pixels.
            // Upper pixel = foreground, lower pixel = background.
            for ($cy = 0; $cy < $bufH; $cy++) {
                for ($cx = 0; $cx < $bufW; $cx++) {
                    // Source pixel row for upper half.
                    $upperY = $cy * 2;
                    // Source pixel row for lower half.
                    $lowerY = $cy * 2 + 1;

                    $upperRgb = self::pixelRgb($bytes, $w, $byteLen, $cx * $cellW, $upperY);
                    $lowerRgb = self::pixelRgb($bytes, $w, $byteLen, $cx * $cellW, $lowerY);

                    $fg = self::rgbToStyleColor($upperRgb[0], $upperRgb[1], $upperRgb[2], $mode);
                    $bg = self::rgbToStyleColor($lowerRgb[0], $lowerRgb[1], $lowerRgb[2], $mode);

                    $style = new Style($fg, $bg);

                    $grid[] = Cell::new('▀', $style, null, 1);
                }
            }
        } elseif ($mode === Mode::QuarterBlock) {
            // QuarterBlock: each cell is a 2×2 pixel group. The four quadrant
            // colours are split into two groups and drawn with a quadrant glyph
            // (fg = brighter group, bg = the other) — two colours across four
            // sub-pixel positions, sharper than half-block.
            for ($cy = 0; $cy < $bufH; $cy++) {
                for ($cx = 0; $cx < $bufW; $cx++) {
                    $x0 = $cx * 2;
                    $y0 = $cy * 2;
                    $quads = [
                        self::pixelRgb($bytes, $w, $byteLen, $x0, $y0),     // UL
                        self::pixelRgb($bytes, $w, $byteLen, $x0 + 1, $y0),     // UR
                        self::pixelRgb($bytes, $w, $byteLen, $x0, $y0 + 1), // LL
                        self::pixelRgb($bytes, $w, $byteLen, $x0 + 1, $y0 + 1), // LR
                    ];
                    [$glyph, $fgInt, $bgInt] = self::quarterCell($quads);
                    $grid[] = Cell::new($glyph, new Style($fgInt, $bgInt), null, 1);
                }
            }
        } else {
            // Ascii / Ansi256 / TrueColor: one pixel per cell.
            for ($cy = 0; $cy < $bufH; $cy++) {
                for ($cx = 0; $cx < $bufW; $cx++) {
                    // M21: a cell covers the full source box mapped to the
                    // buffer grid, not just its top-left pixel. On the exact
                    // frame geometry ffmpeg/GIF decoders emit (one source
                    // pixel per cell) every box degenerates to one pixel and
                    // this is pixel-identical to the old point read; an
                    // oversized frame now averages its cell region instead of
                    // aliasing it.
                    [$r, $g, $b] = self::areaAverageRgb(
                        $bytes,
                        $w,
                        $byteLen,
                        intdiv($cx * $w, max(1, $bufW)),
                        intdiv($cy * $h, max(1, $bufH)),
                        intdiv(($cx + 1) * $w - 1, max(1, $bufW)),
                        intdiv(($cy + 1) * $h - 1, max(1, $bufH)),
                    );

                    $luma = (($r * 77) + ($g * 150) + ($b * 29)) >> 8;
                    $ch = LumaRamp::char((float)$luma, $this->ramp);

                    $fg = self::rgbToStyleColor($r, $g, $b, $mode);
                    $style = $fg !== null ? new Style($fg) : null;

                    $grid[] = Cell::new($ch, $style, null, 1);
                }
            }
        }

        return Buffer::fromGrid($bufW, $bufH, $grid);
    }

    /**
     * SV-PGEO/069: dev-build door for the pane-geometry assert. null follows
     * the environment (SUGAR_VIDEO_DEBUG), true/false forces it. Production
     * never forces it, so the shipped default path is the clamped one.
     */
    private static ?bool $geometryAsserts = null;

    public static function setGeometryAssertsForTesting(?bool $on): void
    {
        self::$geometryAsserts = $on;
    }

    private static function geometryAsserts(): bool
    {
        return self::$geometryAsserts ?? (getenv('SUGAR_VIDEO_DEBUG') !== false);
    }

    /**
     * 16 quadrant glyphs indexed by a 4-bit foreground mask
     * (bit0=UL, bit1=UR, bit2=LL, bit3=LR).
     *
     * @var array<int, string>
     */
    private const QUARTER_GLYPHS = [
        0 => ' ', 1 => '▘', 2 => '▝', 3 => '▀', 4 => '▖', 5 => '▌', 6 => '▞', 7 => '▛',
        8 => '▗', 9 => '▚', 10 => '▐', 11 => '▜', 12 => '▄', 13 => '▙', 14 => '▟', 15 => '█',
    ];

    /**
     * Pick a quadrant glyph + fg/bg colours for one 2×2 cell. The four quadrant
     * colours are split into two groups (a one-step 2-means around the
     * most-distant pair); the glyph marks the brighter foreground group and each
     * group's average colour becomes fg/bg (0xRRGGBB).
     *
     * @param list<array{int,int,int}> $quads ul, ur, ll, lr
     * @return array{0: string, 1: int, 2: int} glyph, fg, bg
     */
    private static function quarterCell(array $quads): array
    {
        $dist = static fn (array $a, array $b): int =>
            ($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2;
        $luma = static fn (array $c): int => LumaRamp::compute($c[0], $c[1], $c[2]);
        $pack = static fn (array $c): int => Color::pack($c[0], $c[1], $c[2]);

        $maxD = -1;
        $a = 0;
        $b = 0;
        for ($i = 0; $i < 4; $i++) {
            for ($j = $i + 1; $j < 4; $j++) {
                $d = $dist($quads[$i], $quads[$j]);
                if ($d > $maxD) {
                    $maxD = $d;
                    $a = $i;
                    $b = $j;
                }
            }
        }

        if ($maxD === 0) {
            return [self::QUARTER_GLYPHS[15], $pack($quads[0]), $pack($quads[0])];
        }

        [$fgSeed, $bgSeed] = $luma($quads[$a]) >= $luma($quads[$b])
            ? [$quads[$a], $quads[$b]]
            : [$quads[$b], $quads[$a]];

        $mask = 0;
        $fgSum = [0, 0, 0];
        $bgSum = [0, 0, 0];
        $fgN = 0;
        $bgN = 0;
        for ($i = 0; $i < 4; $i++) {
            if ($dist($quads[$i], $fgSeed) <= $dist($quads[$i], $bgSeed)) {
                $mask |= (1 << $i);
                $fgSum[0] += $quads[$i][0];
                $fgSum[1] += $quads[$i][1];
                $fgSum[2] += $quads[$i][2];
                $fgN++;
            } else {
                $bgSum[0] += $quads[$i][0];
                $bgSum[1] += $quads[$i][1];
                $bgSum[2] += $quads[$i][2];
                $bgN++;
            }
        }

        $fg = $fgN > 0 ? [intdiv($fgSum[0], $fgN), intdiv($fgSum[1], $fgN), intdiv($fgSum[2], $fgN)] : $fgSeed;
        $bg = $bgN > 0 ? [intdiv($bgSum[0], $bgN), intdiv($bgSum[1], $bgN), intdiv($bgSum[2], $bgN)] : $bgSeed;

        return [self::QUARTER_GLYPHS[$mask], $pack($fg), $pack($bg)];
    }

    /**
     * Get RGB values for a pixel at (px, py) from raw rgb24 bytes.
     *
     * @return array{int, int, int} [r, g, b]
     */
    /**
     * Inclusive-box channel mean over the raw rgb24 grid (M21).
     *
     * Indices clamp to the frame and to the audited byte length; a box that
     * reads no pixels yields black, exactly like {@see pixelRgb()}'s
     * out-of-bounds guard, so no new failure mode rides the filter.
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function areaAverageRgb(string $bytes, int $w, int $byteLen, int $x0, int $y0, int $x1, int $y1): array
    {
        $x0 = max(0, min($x0, $w - 1));
        $x1 = max(0, min($x1, $w - 1));
        $y0 = max(0, min($y0, intdiv(max(0, $byteLen - 2), max(1, $w * 3))));
        $y1 = max(0, min($y1, intdiv(max(0, $byteLen - 2), max(1, $w * 3))));

        $rSum = 0;
        $gSum = 0;
        $bSum = 0;
        $n = 0;
        for ($y = $y0; $y <= $y1; ++$y) {
            for ($x = $x0; $x <= $x1; ++$x) {
                $idx = ($y * $w + $x) * 3;
                if ($idx + 2 >= $byteLen) {
                    continue;
                }
                $rSum += ord($bytes[$idx]);
                $gSum += ord($bytes[$idx + 1]);
                $bSum += ord($bytes[$idx + 2]);
                ++$n;
            }
        }

        if ($n === 0) {
            return [0, 0, 0];
        }

        return [intdiv($rSum, $n), intdiv($gSum, $n), intdiv($bSum, $n)];
    }

    /**
     * One raw rgb24 pixel, black when the index falls outside the audited
     * byte length. The text Buffer path reaches it through
     * {@see areaAverageRgb()} only for degenerate single-pixel boxes.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function pixelRgb(string $bytes, int $w, int $byteLen, int $px, int $py): array
    {
        $idx = ($py * $w + $px) * 3;
        if ($idx + 2 >= $byteLen) {
            return [0, 0, 0];
        }
        return [ord($bytes[$idx]), ord($bytes[$idx + 1]), ord($bytes[$idx + 2])];
    }

    /**
     * Convert RGB to a sugar-cellbuffer Style color (0xRRGGBB) based on mode.
     *
     * Only the buffer-backed modes reach this — Ascii has no color, the rest
     * (TrueColor/HalfBlock) pack the channels into a 0xRRGGBB int. Ansi256 and
     * the image protocols never use the Buffer path (they renderDirect()).
     *
     * Packing delegates to {@see Color::pack()} — the same helper `RgbFrame::toGd()`
     * uses — so the terminal path and the GD export path can never drift on how
     * three bytes become one int.
     *
     * @return int|null 0xRRGGBB color for TrueColor/HalfBlock, null for Ascii
     */
    private static function rgbToStyleColor(int $r, int $g, int $b, Mode $mode): ?int
    {
        if ($mode === Mode::Ascii) {
            return null;
        }

        return Color::pack($r, $g, $b);
    }

    /**
     * Detect cell dimensions for a frame given the current rendering mode.
     *
     * @return array{w: int, h: int}
     */
    private function detectCellDimensions(): array
    {
        return ['w' => $this->mode->colsPerCell(), 'h' => $this->mode->rowsPerCell()];
    }

    /**
     * Direct (non-delta) rendering via cached FrameRenderer.
     *
     * The renderer is built by open()/fromDecoder() for every mode; a null here
     * means a Player reached a direct-render mode without going through a
     * factory that armed one — a programming error we surface loudly rather
     * than fataling on a null method call mid-frame.
     *
     * Disclosure on the other half of that build: in the Buffer-rung modes
     * (Ascii, TrueColor, HalfBlock, QuarterBlock) view() never calls this
     * method, so the factory-built AsciiRenderer, HalfBlockRenderer and
     * QuarterBlockRenderer are never reached by the Player runtime either —
     * the frames are composed inline in frameToBuffer() instead. Those three
     * classes exist for direct factory use and tests; HalfBlockParityTest
     * pins the inline path against its factory-built twin, and the rationale
     * for keeping both (rather than consolidating) is spelled out on the
     * inline HalfBlock branch inside frameToBuffer().
     */
    private function renderDirect(RgbFrame $frame): string
    {
        if ($this->renderer === null) {
            throw new \LogicException(
                'Player is in direct-render mode ' . $this->mode->value
                . ' but carries no FrameRenderer; build it with Player::open() or Player::fromDecoder().'
            );
        }

        return $this->renderer->render($frame, $this->mode);
    }

    /**
     * Render a placeholder when no frame is available yet.
     */
    /**
     * M24: opt audio in or out (the 'm' key stays the mode cycle by design).
     *
     * The companion object itself refuses to spawn while muted, so the opt-out
     * survives every respawn the seek/loop/resize paths perform.
     */
    public function withAudio(bool $on): self
    {
        if ($on) {
            $this->audioPlayer?->unmute();
            if (!$this->paused) {
                $this->audioPlayer?->start();
            }
        } else {
            $this->audioPlayer?->mute();
        }

        return $this->mutate(['audioMuted' => !$on]);
    }

    private function renderPlaceholder(): string
    {
        // SV-H5/005: the start screen's own status row rides inside the pane
        // budget, not below it — cellsH blank rows PLUS the row made the very
        // first paint over-tall by one, exactly the scroll the invariant
        // forbids. Body gets cellsH-1, status takes the last row.
        $lines = [];
        for ($i = 0; $i < max(1, $this->cellsH - 1); $i++) {
            $lines[] = str_repeat(' ', $this->cellsW);
        }
        $body = implode("\r\n", $lines);
        // M22: the start screen announces the active render mode by its human label.
        $status = Lang::t('player.loading_status', ['mode' => $this->mode->label()]);
        return $body . "\r\n" . $status;
    }

    /**
     * Keep a rendered text-rung block inside a row budget.
     *
     * The Buffer/direct text paths emit one pane row per "\r\n"-separated
     * line, so bottom rows are dropped whole — a cut never splits an SGR
     * sequence because each line carries its own complete styling. Only ever
     * called for text modes (graphics protocols place their own pixels and
     * never overflow the pane by line count).
     */
    private static function clipToRows(string $block, int $rows): string
    {
        // The Buffer/direct text paths join rows with "\n" (appended overlay
        // rows use "\r\n"); splitting on either keeps whole lines whole.
        $lines = preg_split("/\r\n|\n/", $block) ?: [];
        if (count($lines) <= $rows) {
            return $block;
        }
        return implode("\n", array_slice($lines, 0, $rows));
    }

    /**
     * Cmd to re-arm the tick chain after a seek.
     *
     * The live tick chain is self-perpetuating, so a seek during normal
     * playback needs no new tick (returns null). But a seek out of the ENDED
     * state (where ticking had stopped) must restart it — provided the
     * post-seek player is not paused.
     */
    private function seekTickCmd(self $nextPlayer): ?\Closure
    {
        // SV-H4/090: a same-index seek returns the SAME instance (true no-op) —
        // arming a tick for a stream that never moved would only pump the
        // ended-guard harmlessly, but it is noise; only a seek that actually
        // rebuilt or walked re-arms the pump.
        return ($this->ended && !$nextPlayer->paused && $nextPlayer !== $this)
            ? Cmd::tick(1.0 / $this->fps, static fn (): Msg => TickMsg::instance())
            : null;
    }

    /**
     * Close-and-recreate the decoder at a given size+mode, advanced to $frameIndex.
     *
     * The decision is driven by the decoder's own {@see Decoder::reopensInPlace()}
     * capability, with one production-class branch (M38), never by a `'/fake'`
     * string or an `instanceof` test class in the playback path — test doubles
     * answer the capability and take the first branch:
     *  - A decoder that reopens in place (an injected test double, or a server
     *    stream that regenerates at the new geometry) is re-opened via reopen().
     *  - The GIF decoder holds its whole source as an open-time capture, so it
     *    re-hydrates from those bytes via its own same-source reopen() — zero
     *    filesystem, zero re-conversion (M40), and the single-capture law stops
     *    a mutated file from changing what plays mid-session.
     *  - Any other decoder (the ffmpeg path DecoderFactory owns) is
     *    closed — F21: never leak the old ffmpeg process — and rebuilt fresh
     *    from the source path via DecoderFactory, re-presenting the request
     *    headers so a signed stream survives the rebuild.
     *
     * The forward walk honours the player's frameBudgetMs wall clock (like the
     * tick pump's catch-up): when the budget expires with frames still to walk,
     * the walk stops at the last frame actually consumed and its content index
     * comes back as element 2 — the landed index — so every caller re-records
     * the TRUE playhead instead of the requested one and later ticks converge
     * from there rather than desyncing ahead of the decoder. Budget 0 (default)
     * walks the whole span, exactly the pre-budget behaviour.
     *
     * @return array{0: Decoder, 1: ?RgbFrame, 2: int} decoder, last frame, landed content index
     */
    private function rebuildDecoderAt(int $cellsW, int $cellsH, Mode $mode, int $frameIndex): array
    {
        if ($this->decoder->reopensInPlace()) {
            $this->decoder->reopen($this->videoPath, $cellsW, $cellsH, $this->fps, $mode, 0.0, $this->headers);
            $decoder = $this->decoder;
        } elseif ($this->decoder instanceof GifDecoder) {
            // M38: the retained-capture decoder IS a reopen-in-place machine
            // for its own bytes — its same-source reopen() re-hydrates from
            // the single open() capture without touching the filesystem (the
            // single-capture law) and the M40 emit cache serves the walk to
            // $frameIndex without re-converting. The close()-before-rebuild
            // in the factory branch exists to reap the ffmpeg child; a local
            // GIF holds no child, and re-reading it mid-playback was both
            // the cost this row retires and the second-chance TOCTOU the
            // capture law exists to deny.
            $this->decoder->reopen($this->videoPath, $cellsW, $cellsH, $this->fps, $mode, 0.0, $this->headers);
            $decoder = $this->decoder;
        } else {
            $this->decoder->close();                 // F21: never leak the old ffmpeg process
            $decoder = DecoderFactory::new($this->videoPath, $cellsW, $cellsH, $this->fps, $mode, 0.0, $this->cellPxW, $this->cellPxH, $this->headers, hostRestricted: $this->hostRestricted);
        }
        $frame = null;
        $landed = 0;
        $deadline = $this->frameBudgetMs > 0.0
            ? $this->now() + ($this->frameBudgetMs / 1000.0)
            : null;
        for ($i = 0; $i <= $frameIndex; $i++) {
            $f = $decoder->next();
            if ($f === null) {
                break;
            }
            $frame = $f;
            $landed = $i;
            // Same guaranteed-progress rule as the tick pump: the first pulled
            // frame is never abandoned, and only a still-pending walk bails.
            if ($deadline !== null && $i < $frameIndex && $this->now() >= $deadline) {
                break;
            }
        }
        return [$decoder, $frame, $landed];
    }

    /**
     * Seek to a specific frame index. SV-H11/011: a factory-owned decoder
     * is re-spawned at the target's SECONDS (fast `-ss` / cursor jump — one
     * pull lands there); an in-place decoder closes-and-reopens at its head
     * and decodes forward, bounded by the frame budget. The landed index is
     * always the truth the new state records (SV-H4/013).
     */
    public function withSeek(int $targetIndex): self
    {
        // Clamp to valid range. A known-length stream cannot seek past its
        // last frame (SV-H11/011: the seconds route would read nothing there
        // and land at 0 — worse than the old walk, which simply stopped at
        // the end); an unknown-length one is left uncapped, exactly how the
        // old live-walk behaved against a decoder's real EOF.
        $targetIndex = max(0, $targetIndex);

        // SV-H4/090: a same-index seek is a TRUE no-op — the check runs
        // BEFORE the clamp and the audio rebuild: a fully consumed stream
        // sits at frameIndex == totalFrames (one past the last 0-based
        // content index), and the arrow law says → there must return
        // identity, not clamp backward off the terminal playhead.
        if ($targetIndex === $this->frameIndex) {
            return $this;
        }

        if ($this->totalFrames > 0) {
            $targetIndex = min($targetIndex, $this->totalFrames - 1);
            // A clamp that lands back on the current frame is the same truth:
            // no respawned audio, no half-dead "playing" claim past EOF.
            if ($targetIndex === $this->frameIndex) {
                return $this;
            }
        }

        // F6: realign audio to the seek target position.
        $newAudio = $this->rebuildAudio((int)round(($targetIndex / $this->fps) * 1000));

        // SV-H11/011: a factory-owned decoder (the ffmpeg/GIF/WebP classes
        // DecoderFactory builds) honours a start-second seek natively — `-ss`
        // before `-i` for ffmpeg, a cursor jump for the byte formats. Routing
        // an index seek through the seconds rebuild lands on the target with
        // ONE pull, where the old index walk re-decoded every intervening
        // frame inside the keypress (one ← at frame 7,000 paid ~6,990 reads).
        // Decoders answering reopensInPlace() true have no seconds notion
        // behind reopen() — an injected double or a socket stream replays
        // from its own head — so they keep the budget-bounded index walks
        // below. The split is the honest meaning of the capability and says
        // so in Decoder::reopensInPlace()'s contract.
        if (!$this->decoder->reopensInPlace()) {
            [$decoder, $frame, $landed] = $this->rebuildDecoderAtSeconds(
                $this->cellsW,
                $this->cellsH,
                $this->mode,
                $targetIndex / $this->fps,
            );

            return $this->mutate([
                'decoder' => $decoder,
                'videoTime' => $landed / $this->fps,
                'frameIndex' => $landed,
                'currentFrame' => $frame ?? $this->currentFrame,
                'lastTickTime' => $this->now(),
                'audioPlayer' => $newAudio,
                'failure' => null, // SV-H4/004: a rebuild from the SOURCE PATH clears the row
                'ended' => $frame !== null ? false : $this->ended,
            ]);
        }

        // Backward seek (in-place decoders): they are forward-only streams,
        // so close-and-reopen and skip forward to the target.
        if ($targetIndex < $this->frameIndex) {
            [$decoder, $frame, $landed] = $this->rebuildDecoderAt($this->cellsW, $this->cellsH, $this->mode, $targetIndex);
            // videoTime = content time (not scaled); the landed index, not the
            // requested one, when the walk yielded on the frame budget.
            $newVideoTime = $landed / $this->fps;

            return $this->mutate([
                'decoder' => $decoder,
                'videoTime' => $newVideoTime,
                'frameIndex' => $landed,
                'currentFrame' => $frame ?? $this->currentFrame,
                'lastTickTime' => $this->now(),
                'audioPlayer' => $newAudio,
                // SV-H4/004: a seek rebuilds from the SOURCE PATH, so a dead
                // session's error row is cleared deliberately — the fresh
                // child can feed, and leaving the row up over a live frame is
                // the worst of both (frozen picture, no error, dead tick).
                'failure' => null,
                // SV-H4/090: with no frame to paint the seek did not actually
                // land anywhere — do not claim a resumed stream.
                'ended' => $frame !== null ? false : $this->ended,
            ]);
        }

        // Forward seek: advance decoder to target frame — under the player's
        // frame budget when one is set, so a deep digit-seek on a long clip
        // yields to the event loop instead of walking thousands of frames
        // inside one keypress (the landed $idx below then records the true
        // position and later ticks converge, exactly like the tick pump).
        $frame = $this->currentFrame;
        $idx = $this->frameIndex;
        $fwdDeadline = $this->frameBudgetMs > 0.0
            ? $this->now() + ($this->frameBudgetMs / 1000.0)
            : null;
        while ($idx < $targetIndex) {
            $pulled = $this->decoder->next();
            if ($pulled === null) {
                break;
            }
            $frame = $pulled;
            $idx++;
            if ($fwdDeadline !== null && $idx < $targetIndex && $this->now() >= $fwdDeadline) {
                break;
            }
        }

        // F4 fix: videoTime = content time (not scaled by speed).
        $newVideoTime = $idx / $this->fps;

        return $this->mutate([
            'videoTime' => $newVideoTime,
            'frameIndex' => $idx,
            'currentFrame' => $frame,
            'lastTickTime' => $this->now(),
            'audioPlayer' => $newAudio,
            'failure' => null, // SV-H4/004: see the backward arm's law
            'ended' => $frame !== null ? false : $this->ended, // a seek clears the ended state when it landed
        ]);
    }

    /**
     * Current playback position in content seconds (videoTime).
     */
    public function position(): float
    {
        return $this->videoTime;
    }

    /**
     * The HTTP request headers this player re-presents on every decoder rebuild
     * (empty for a local file). Bare accessor per the library's no-`get` rule.
     *
     * @return array<array-key, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Whether {@see view()} ships the pane as ONE indivisible paint — the
     * mosaic/image rungs emit a raster blob whose bytes carry no line
     * structure, so a host that line-diffs its output must repaint whole
     * frames instead (SV-M34/034). Every text rung — including the direct
     * Ansi256 row stream, whose rows carry their own SGR and split cleanly
     * at \r\n — answers false: its frames are row-addressable.
     *
     * This is the machine-readable truth behind the old host-side copy of
     * GRAPHICS_MODES plus a width heuristic; a consumer asking the Player
     * cannot drift from the lib's actual routing again.
     */
    public function rendersWholeScreen(): bool
    {
        // The answer must track view()'s DIRECT-render set exactly: the same
        // three graphics protocols bypass the Buffer grid, nothing else does.
        return $this->mode->isGraphics();
    }

    /**
     * Total duration in content seconds, or 0.0 when unknown (a stream of
     * indeterminate length, where totalFrames is 0).
     */
    public function duration(): float
    {
        return ($this->totalFrames > 0 && $this->fps > 0.0)
            ? $this->totalFrames / $this->fps
            : 0.0;
    }

    /**
     * Seek to an absolute time in content seconds (clamped to [0, duration]).
     *
     * The time-domain companion to {@see withSeek()} (which is frame-indexed):
     * it re-spawns the decoder with a fast ffmpeg input seek (`-ss`) so jumping
     * deep into a multi-GB network stream is instant rather than decoding every
     * intervening frame. A decoder without `-ss` falls back to the index walk,
     * which honours the player's frame budget like every other forward pull. Audio is realigned to the same offset. Returns a new
     * Player (immutable); a host screen decides whether to re-arm the tick.
     */
    public function seekToSeconds(float $sec): self
    {
        $sec = max(0.0, $sec);
        $duration = $this->duration();
        if ($duration > 0.0 && $sec > $duration) {
            $sec = $duration;
        }

        // Realign audio to the seek target (same factory seam as withSeek()).
        $newAudio = $this->rebuildAudio((int)round($sec * 1000));

        [$decoder, $frame, $landed] = $this->rebuildDecoderAtSeconds($this->cellsW, $this->cellsH, $this->mode, $sec);

        // SV-H4/013: record the LANDED position, not the requested one — the
        // walk of an in-place decoder can yield short on the frame budget, and
        // rebuildDecoderAt()'s own law is that callers re-record the truth so
        // later ticks converge from there instead of desyncing ahead.
        return $this->mutate([
            'decoder' => $decoder,
            'currentFrame' => $frame ?? $this->currentFrame,
            'frameIndex' => $landed,
            'videoTime' => $landed / $this->fps,
            'lastTickTime' => $this->now(),
            'audioPlayer' => $newAudio,
            'failure' => null, // SV-H4/004: a rebuild-from-source clears the dead session's row
            'ended' => $frame !== null ? false : $this->ended, // a seek clears the end-of-stream state when it landed
        ]);
    }

    /**
     * Grab a single frame at $sec for a scrubber-hover thumbnail.
     *
     * Two source kinds, and frameAt serves both now that the decoder tells the
     * Player how it reopens (no `'/fake'` string check here):
     *  - A decoder that reopens in place (an injected test double or a server
     *    stream) is asked to reopen at $sec, hand back its next frame, then
     *    reopen again at the current playhead. The restore matters: a
     *    scrubber-hover grabs thumbnails WHILE playing, and without rewinding
     *    to $this->videoTime the live stream would keep flowing from $sec while
     *    the Player still believed it was at frameIndex — silently desynced.
     *    (Decoders that honour $startSec resume exactly; replay-from-start
     *    test doubles resume from the head — such doubles ignore absolute
     *    positions everywhere, so their playhead was always approximate.)
     *  - A DecoderFactory-owned decoder (ffmpeg/GIF) is snapshotted through a
     *    throwaway decoder seeked to $sec (fast `-ss`), read once, and closed —
     *    WITHOUT disturbing the live decoder that keeps playing.
     *
     * @throws \LogicException for a factory-owned Player that has no source path
     *         to spawn the throwaway from (an unbound source), where grabbing a
     *         frame is impossible rather than merely empty.
     * @return RgbFrame|null null only when a real grab legitimately yields nothing.
     */
    public function frameAt(float $sec): ?RgbFrame
    {
        // SV-L86/086: one thumbnail per rounded second of video, and never two
        // thumbnail walks inside FRAME_AT_MIN_SPAWN_SEC. Every scrub event used
        // to spawn a THROWAWAY ffmpeg (factory players — one child per hover
        // event, reaped by the grace ladder inside the call) or reopen the live
        // decoder TWICE (in-place players); mouse scrubbing was a spawn storm.
        // The shipped shape is deliberately single-frame memo + throttle: a
        // strip/batch API would answer a scrubber that displays one frame at a
        // time with N decodes it throws away — scope creep the finding rejects.
        // The memo carries the geometry signature, so a resize rebuild
        // invalidates it with no call-site bookkeeping; the clock is the M20
        // seam, so the floor is monotonic like everything else here.
        $sec = max(0.0, $sec);
        $sig = $this->videoPath . '|' . $this->cellsW . 'x' . $this->cellsH . '|'
            . $this->mode->name . '|' . $this->cellPxW . 'x' . $this->cellPxH . '|' . $this->fps;
        $memo = $this->frameAtMemo;
        if (($memo['sig'] ?? null) !== $sig) {
            $memo['sig'] = $sig;
            $memo['bucket'] = null;
            $memo['frame'] = null;
            $memo['at'] = null;
        }

        $bucket = (int) floor($sec);
        if ($memo['bucket'] === $bucket) {
            return $memo['frame']; // same second — the walk already answered it
        }

        if ($memo['at'] !== null && $this->now() - (float) $memo['at'] < self::FRAME_AT_MIN_SPAWN_SEC) {
            return $memo['frame']; // bucket crossed mid-burst — serve the last thumbnail
        }

        $frame = $this->frameAtUncached($sec);
        $memo['bucket'] = $bucket;
        $memo['frame'] = $frame;
        $memo['at'] = $this->now();

        return $frame;
    }

    /**
     * The actual thumbnail read behind the SV-L86/086 memo.
     *
     * Two source kinds, and the read serves both now that the decoder tells the
     * Player how it reopens (no `'/fake'` string check here):
     *  - A decoder that reopens in place (an injected test double or a server
     *    stream) is asked to reopen at $sec, hand back its next frame, then
     *    reopen again at the current playhead. The restore matters: a
     *    scrubber-hover grabs thumbnails WHILE playing, and without rewinding
     *    to $this->videoTime the live stream would keep flowing from $sec while
     *    the Player still believed it was at frameIndex — silently desynced.
     *    (Decoders that honour $startSec resume exactly; replay-from-start
     *    test doubles resume from the head — such doubles ignore absolute
     *    positions everywhere, so their playhead was always approximate.)
     *  - A DecoderFactory-owned decoder (ffmpeg/GIF) is snapshotted through a
     *    throwaway decoder seeked to $sec (fast `-ss`), read once, and closed —
     *    WITHOUT disturbing the live decoder that keeps playing.
     *
     * @throws \LogicException for a factory-owned Player that has no source path
     *         to spawn the throwaway from (an unbound source), where grabbing a
     *         frame is impossible rather than merely empty.
     */
    private function frameAtUncached(float $sec): ?RgbFrame
    {
        if ($this->decoder->reopensInPlace()) {
            try {
                $this->decoder->reopen($this->videoPath, $this->cellsW, $this->cellsH, $this->fps, $this->mode, max(0.0, $sec), $this->headers);
                $frame = $this->decoder->next();
            } finally {
                // Put the playhead back where the ticking Player thinks it is —
                // even when the throwaway read throws, so a caught exception
                // never strands the live decoder at the hovered position.
                $this->decoder->reopen($this->videoPath, $this->cellsW, $this->cellsH, $this->fps, $this->mode, $this->videoTime, $this->headers);
            }

            return $frame;
        }

        if ($this->videoPath === '') {
            throw new \LogicException(
                'frameAt() needs a source path to spawn a throwaway decoder, but this Player is unbound.'
            );
        }

        $decoder = DecoderFactory::new($this->videoPath, $this->cellsW, $this->cellsH, $this->fps, $this->mode, max(0.0, $sec), $this->cellPxW, $this->cellPxH, $this->headers, hostRestricted: $this->hostRestricted);
        try {
            $frame = $decoder->next(); // with -ss, the first frame is at/near $sec
        } finally {
            $decoder->close(); // a throw reads cleanly or not at all — never leaks the child
        }

        return $frame;
    }

    /**
     * The stock audio-companion factory: builds the real {@see AudioPlayer} for a
     * path, seek offset and header map.
     *
     * One definition rather than three copies of the same closure — the duplication pattern
     * applied to Player itself: `open()`, `fromDecoder()` and
     * `rebuildAudio()` all defaulted to this exact shape, so the headers
     * parameter had to be edited in three places at once — the precise drift the
     * audit keeps flagging.
     *
     * @return \Closure(string,?int,array<array-key,string>,bool):AudioPlayer
     */
    private static function defaultAudioFactory(): \Closure
    {
        return static fn (string $path, ?int $startMs = null, array $headers = [], bool $hostRestricted = false): AudioPlayer
            => new AudioPlayer($path, $startMs, null, $headers, false, $hostRestricted);
    }

    /**
     * Tear down playback: stop the audio companion and close the decoder
     * (terminating its ffmpeg subprocess). Idempotent — safe to call more than
     * once. A host screen calls this when leaving the player so no audio/video
     * subprocess leaks across play → back.
     *
     * The stop latch is per object tree: it lives on the instance the host
     * holds, and it is copied forward by
     * `mutate()`, so the three paths that can self-reschedule work — the resize
     * arming path, the deferred debounce apply, and every playback tick — all
     * resolve to identity on a stopped instance. Host-driven
     * one-shot APIs (an explicit seek, a mode keypress) still act, exactly as
     * before the latch existed; a host has no reason to keep feeding input to a
     * torn-down player. To play again, construct a FRESH Player (or `open()` a
     * new one) — reusing the stopped instance will not resurrect its decoder.
     *
     * SV-X95: the exit-code tolerance that classifies the video child's
     * teardown is measured on Linux only; the Windows disposition (refused at
     * spawn by SV-M44, unmeasured-by-design otherwise) is documented at
     * {@see \SugarCraft\Video\Decode\FfmpegDecoder::isTeardownExit()}.
     */
    public function stop(): void
    {
        $this->stopped = true;
        $this->audioPlayer?->stop();
        $this->decoder->close();
    }

    /**
     * Whether {@see stop()} has torn this player down (M15).
     *
     * stop() stays void-and-mutating on the shared instance — flipping it to a
     * fluent return would break every host that already drives it as a
     * teardown statement, so the non-breaking half of the API ask is this
     * reader. mutate() copies the latch down the tree, so any Model derived
     * after teardown still answers true.
     */
    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Pause playback. Returns a new Player with paused=true.
     *
     * SV-L92/092: on a stopped Player this is a no-op returning the same
     * instance — the teardown latch refuses play/pause (and the tick/resize
     * paths) exactly as the class docblock on stop() declares; one-shot
     * APIs like an explicit seek still act.
     */
    public function pause(): self
    {
        if ($this->stopped) {
            return $this;
        }

        $this->audioPlayer?->pause();
        return $this->mutate(['paused' => true]);
    }

    /**
     * Resume playback. Returns a new Player with paused=false and re-anchored
     * clock so play-time accumulates correctly.
     *
     * SV-L92/092: stop() declared this instance dead, and updateTick(),
     * updateResize() and applyPendingResize() all refuse post-stop work —
     * play() carried no matching guard, so a host that kept the object
     * reachable could resume() the audio companion into a FRESH child
     * respawned from banked seekMs (music-without-video on a torn-down
     * stream) and hand back a paused=false model whose decoder was already
     * closed. The latch answers with the stopped instance itself: no
     * respawn, no derived resurrection. To play again, build a FRESH Player.
     */
    public function play(): self
    {
        if ($this->stopped) {
            return $this;
        }

        $this->audioPlayer?->resume();
        return $this->mutate([
            'paused' => false,
            'lastTickTime' => $this->now(),
        ]);
    }

    /**
     * Attach a parsed subtitle track to the player.
     *
     * Returns a new Player (immutable) with the subtitle track set.
     * The caption overlay appears in view() when videoTime falls within a cue.
     */
    public function withSubtitles(WebVtt $track): self
    {
        return $this->mutate(['subtitles' => $track]);
    }

    /**
     * Build a fresh decoder seeked to $startSec via fast ffmpeg input seeking
     * (`-ss`), returning the decoder and its first (target) frame. The real path
     * closes the old decoder first (F21: no leaked ffmpeg) and threads startSec
     * through DecoderFactory. A decoder that reopens in place has no `-ss`
     * notion (an injected test double or a socket stream replays from its own
     * head), so it falls back to the index-based rebuild — decode-forward to the
     * equivalent frame — which is the capability's honest meaning here, not a
     * path-string special case.
     *
     * @return array{0: Decoder, 1: ?RgbFrame, 2: int} decoder, first frame, landed content index (SV-H4/013: three-tuple like {@see rebuildDecoderAt()})
     */
    private function rebuildDecoderAtSeconds(int $cellsW, int $cellsH, Mode $mode, float $startSec): array
    {
        if ($this->decoder->reopensInPlace()) {
            $index = (int) round(max(0.0, $startSec) * $this->fps);
            return $this->rebuildDecoderAt($cellsW, $cellsH, $mode, $index);
        }

        if ($this->decoder instanceof GifDecoder) {
            // M38/038: same retained-capture route as the index rebuild —
            // GifDecoder::open() honours $startSec as a cursor jump over its
            // kept bytes, so the seek lands with ONE cached pull: no re-read,
            // no re-decode, no child to reap (there never was one).
            $this->decoder->reopen($this->videoPath, $cellsW, $cellsH, $this->fps, $mode, max(0.0, $startSec), $this->headers);
            $decoder = $this->decoder;
            $frame = $decoder->next();
            return [$decoder, $frame, $frame !== null ? (int) round(max(0.0, $startSec) * $this->fps) : 0];
        }

        $this->decoder->close(); // never leak the old ffmpeg process
        $decoder = DecoderFactory::new($this->videoPath, $cellsW, $cellsH, $this->fps, $mode, max(0.0, $startSec), $this->cellPxW, $this->cellPxH, $this->headers, hostRestricted: $this->hostRestricted);
        $frame = $decoder->next(); // with -ss, the first frame IS the seek target

        // SV-H4/013: three-tuple like the walk arm — with the fast input seek
        // applied the landed index IS the requested one when a frame came; a
        // silent child has landed nowhere (0).
        return [$decoder, $frame, $frame !== null ? (int) round(max(0.0, $startSec) * $this->fps) : 0];
    }

    /**
     * Rebuild the audio player for a seek operation.
     *
     * Mirrors the factory seam in withSeek() and seekToSeconds() so the
     * same stop/rebuild/start logic is used in both places.
     */
    private function rebuildAudio(?int $startMs): ?AudioPlayer
    {
        if ($this->audioPlayer === null) {
            return null;
        }
        $this->audioPlayer->stop();
        $factory = $this->audioFactory ?? self::defaultAudioFactory();
        // Re-present the source headers on the respawned companion:
        // every seek/loop/mode rebuild used to drop them, so audio worked only
        // on an unauthenticated URL.
        $newAudio = $factory($this->videoPath, $startMs, $this->headers, $this->hostRestricted);
        // M24: a muted session stays muted across every respawn.
        if ($this->audioMuted) {
            $newAudio->mute();
        }

        if (!$this->paused) {
            $newAudio->start();
        }
        return $newAudio;
    }

    /**
     * Create a new Player with a new decoded frame, advancing frame index.
     */
    private function withNewFrame(
        ?RgbFrame $frame,
        int $frameIndex,
        Decoder $decoder,
        float $videoTime,
        float $lastTickTime,
    ): self {
        // mutate() carries every other field; `ended` is intentionally left out so
        // it defaults to the current value — a normal tick advance never changes
        // the end-of-stream state.
        return $this->mutate([
            'decoder' => $decoder,
            'videoTime' => $videoTime,
            'frameIndex' => $frameIndex,
            'currentFrame' => $frame ?? $this->currentFrame,
            'lastTickTime' => $lastTickTime,
        ]);
    }

    /**
     * Generic mutable-update helper: create a new Player with changed fields.
     *
     * WHY `array_key_exists()` and not `??` . The old implementation
     * read `$changes['ended'] ?? $this->ended`, which reads as "fall back when the
     * caller gave me nothing" but actually means "fall back when the caller gave
     * me *null*". Two real consequences, both silent:
     *
     *  - A nullable field could never be CLEARED. `['audioPlayer' => null]` (stop
     *    following an audio companion) or `['currentFrame' => null]` would have
     *    quietly kept the previous value, so the new Player would claim a state the
     *    update path explicitly discarded — and then try to start audio that no
     *    longer belongs to this stream position.
     *  - Falsy-but-present values were only accidentally safe. `ended => false`,
     *    `frameIndex => 0`, `videoTime => 0.0` and `paused => false` are all
     *    meaningful state the loop-restart and seek paths pass, and their survival
     *    depended on a quirk of the operator rather than on intent.
     *
     * Key-existence makes presence the single signal: if the key is in the map the
     * value is used verbatim, whatever it is. Callers therefore express "no change"
     * by omitting the key — never by passing null.
     *
     * `videoPath`, `headers` and `frameBudgetMs` are pinned (not read from
     * $changes at all): they describe the *source* and the host's decode budget,
     * not the playback state a rebuild is allowed to revise, so any attempt to
     * change them through this seam would be a bug we want to be unable to write.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        $next = new self(
            decoder: array_key_exists('decoder', $changes) ? $changes['decoder'] : $this->decoder,
            mode: array_key_exists('mode', $changes) ? $changes['mode'] : $this->mode,
            speed: array_key_exists('speed', $changes) ? $changes['speed'] : $this->speed,
            paused: array_key_exists('paused', $changes) ? $changes['paused'] : $this->paused,
            videoTime: array_key_exists('videoTime', $changes) ? $changes['videoTime'] : $this->videoTime,
            frameIndex: array_key_exists('frameIndex', $changes) ? $changes['frameIndex'] : $this->frameIndex,
            currentFrame: array_key_exists('currentFrame', $changes) ? $changes['currentFrame'] : $this->currentFrame,
            lastTickTime: array_key_exists('lastTickTime', $changes) ? $changes['lastTickTime'] : $this->lastTickTime,
            fps: array_key_exists('fps', $changes) ? $changes['fps'] : $this->fps,
            totalFrames: array_key_exists('totalFrames', $changes) ? $changes['totalFrames'] : $this->totalFrames,
            cellsW: array_key_exists('cellsW', $changes) ? $changes['cellsW'] : $this->cellsW,
            cellsH: array_key_exists('cellsH', $changes) ? $changes['cellsH'] : $this->cellsH,
            videoPath: $this->videoPath, // pinned — videoPath is immutable for the player's lifetime
            audioPlayer: array_key_exists('audioPlayer', $changes) ? $changes['audioPlayer'] : $this->audioPlayer,
            ended: array_key_exists('ended', $changes) ? $changes['ended'] : $this->ended,
            loop: array_key_exists('loop', $changes) ? $changes['loop'] : $this->loop,
            ramp: array_key_exists('ramp', $changes) ? $changes['ramp'] : $this->ramp,
            audioFactory: array_key_exists('audioFactory', $changes) ? $changes['audioFactory'] : $this->audioFactory,
            cellPxW: array_key_exists('cellPxW', $changes) ? $changes['cellPxW'] : $this->cellPxW,
            cellPxH: array_key_exists('cellPxH', $changes) ? $changes['cellPxH'] : $this->cellPxH,
            subtitles: array_key_exists('subtitles', $changes) ? $changes['subtitles'] : $this->subtitles,
            renderer: array_key_exists('renderer', $changes) ? $changes['renderer'] : $this->renderer,
            headers: $this->headers, // pinned — the source's request headers outlive any rebuild
            hostRestricted: $this->hostRestricted, // SV-H2: the zero-redirect transport policy likewise
            frameBudgetMs: $this->frameBudgetMs, // pinned for the player's lifetime
            failure: array_key_exists('failure', $changes) ? $changes['failure'] : $this->failure,
            // Carried, not pinned: a tick, seek or mode switch that happens inside
            // the debounce window must not silently swallow the deferred rebuild, or
            // the player keeps decoding at the superseded geometry forever.
            pendingResize: array_key_exists('pendingResize', $changes) ? $changes['pendingResize'] : $this->pendingResize,
            audioMuted: array_key_exists('audioMuted', $changes) ? $changes['audioMuted'] : $this->audioMuted,
            clock: $this->clock, // the seam belongs to the player, not to one state
            // SV-PGEO/068: carried like the geometry it annotates — updateResize
            // arms it with the raw width the host just reported, and every other
            // state change keeps whatever refusal the pane currently owes.
            narrowCols: array_key_exists('narrowCols', $changes) ? $changes['narrowCols'] : $this->narrowCols,
            // SV-L86/086: pinned — the thumbnail memo belongs to the stream,
            // not to one state; a tick or seek mid-scrub must not reset it.
            frameAtMemo: $this->frameAtMemo,
        );
        // Teardown is not a value the model is built with, so it cannot ride the
        // named-argument list — copy the latch forward instead. Every derived
        // instance owns the very same decoder/audio child that stop() already
        // closed, so it must be just as dead as its parent.
        $next->stopped = $this->stopped;

        return $next;
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        // M27: deliberate. Pacing already rides the update()-returned
        // Cmd::tick chain; a wall-clock subscription here would double-tick
        // the pump. Input and resize arrive through sugar-core's own wiring.
        return null;
    }
}