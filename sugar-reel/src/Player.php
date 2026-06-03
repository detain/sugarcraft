<?php

declare(strict_types=1);

namespace SugarCraft\Reel;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\DecoderFactory;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Msg\FrameMsg;
use SugarCraft\Reel\Msg\TickMsg;
use SugarCraft\Reel\Render\LumaRamp;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Render\RendererFactory;
use SugarCraft\Reel\Source\VideoSource;

/**
 * TEA Model for terminal video playback.
 *
 * The Player implements the Elm Architecture (Model-View-Update) via
 * the TEA runtime in candy-core. It pulls frames from a Decoder iterator,
 * paces them using wall-clock time and the Sync engine, and renders via
 * FrameRenderer (ascii/ansi256/truecolor/half-block).
 *
 * Repaint: view() returns the FULL current frame every call. candy-core's
 * Renderer already diffs each frame against the previously rendered one and
 * emits only the minimal terminal update, so the Player does not (and must
 * not) diff inside view() — doing so would double-diff and corrupt output.
 *
 * Keys:
 *   Space       — pause / resume
 *   ← / →      — seek backward / forward 10 frames
 *   [ / ]      — decrease / increase speed (0.25 steps, 0.25–4.0)
 *   0–9        — seek to 0–90% of duration
 *   m          — cycle rendering mode
 *   q / Esc    — quit
 *
 * Mirrors the TEA player structure in candy-flip/src/Player.php but
 * adapted for continuous video rather than discrete GIF frames.
 */
final class Player implements Model
{
    /**
     * @param Decoder                      $decoder      Frame source iterator
     * @param Mode                         $mode         Rendering mode
     * @param float                       $speed        Playback speed multiplier
     * @param bool                        $paused       True when playback is paused
     * @param float                       $elapsed      Wall-clock seconds since playback start
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
     */
    private function __construct(
        public readonly Decoder $decoder,
        public readonly Mode $mode,
        public readonly float $speed,
        public readonly bool $paused,
        public readonly float $elapsed,
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
    ) {
    }

    /**
     * Open a video file and return a Player ready for Program::run().
     *
     * Probes the video to get FPS and dimensions, creates the appropriate
     * decoder, and returns a Player in the paused state at frame 0.
     *
     * @param string     $videoPath    Path to the video file (mp4, gif, avi, etc.)
     * @param int       $cellsW       Target terminal cell width
     * @param int       $cellsH       Target terminal cell height
     * @param float|null $fpsOverride FPS override (null = auto from probe)
     * @param Mode       $mode         Rendering mode (decoder resolution matches it)
     * @param bool       $loop         Restart from the beginning at end-of-stream instead of stopping
     */
    public static function open(string $videoPath, int $cellsW, int $cellsH, ?float $fpsOverride = null, Mode $mode = Mode::HalfBlock, bool $loop = false): self
    {
        $source = VideoSource::probe($videoPath);

        // FPS from probe, default 24 if not available. Use override when set.
        $fps = $fpsOverride ?? ($source->fps > 0.0 ? $source->fps : 24.0);

        // Decoder resolution is keyed to the render mode (HalfBlock decodes at
        // 2× cell height). Seek recreates the decoder with the current mode.
        $decoder = DecoderFactory::create($videoPath, $cellsW, $cellsH, $fps, $mode);

        // Audio companion is created when the source has audio but is NOT
        // started here — the Player starts it on first play (see updateKey)
        // so audio and the video wall clock share the same t0.
        $audioPlayer = $source->hasAudio ? new AudioPlayer($videoPath) : null;

        // Player starts paused; the first tick is scheduled when playback begins.
        return new self(
            decoder: $decoder,
            mode: $mode,
            speed: 1.0,
            paused: true,
            elapsed: 0.0,
            frameIndex: 0,
            currentFrame: null,
            lastTickTime: microtime(true),
            fps: $fps,
            totalFrames: 0,
            cellsW: $cellsW,
            cellsH: $cellsH,
            videoPath: $videoPath,
            audioPlayer: $audioPlayer,
            ended: false,
            loop: $loop,
        );
    }

    /**
     * Factory for tests: create a Player with an injected decoder.
     *
     * Bypasses VideoSource::probe() and DecoderFactory so tests can use
     * a FakeDecoder that yields canned RgbFrame objects without any
     * real video file or external process.
     *
     * @param Decoder  $decoder      Frame source iterator (e.g. FakeDecoder)
     * @param float    $fps           Frames per second
     * @param int      $cellsW       Terminal cell width
     * @param int      $cellsH        Terminal cell height
     * @param string   $videoPath    Fake path for seeking (default '/fake')
     * @param bool     $loop          Restart from frame 0 at end-of-stream instead of stopping
     */
    public static function openForTest(
        Decoder $decoder,
        float $fps,
        int $cellsW = 80,
        int $cellsH = 24,
        string $videoPath = '/fake',
        bool $loop = false,
    ): self {
        return new self(
            decoder: $decoder,
            mode: Mode::HalfBlock,
            speed: 1.0,
            paused: true,
            elapsed: 0.0,
            frameIndex: 0,
            currentFrame: null,
            lastTickTime: microtime(true),
            fps: $fps,
            totalFrames: 0,
            cellsW: $cellsW,
            cellsH: $cellsH,
            videoPath: $videoPath,
            audioPlayer: null,
            ended: false,
            loop: $loop,
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
        return Cmd::tick(1.0 / $this->fps, static fn(): Msg => new TickMsg());
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        // TickMsg: advance frame based on wall-clock sync.
        if ($msg instanceof TickMsg) {
            return $this->updateTick();
        }

        // KeyMsg: pause, seek, speed, mode, quit.
        if ($msg instanceof KeyMsg) {
            return $this->updateKey($msg);
        }

        // FrameMsg: a newly decoded frame is available.
        if ($msg instanceof FrameMsg) {
            // Current frame is already stored in $this->currentFrame;
            // nothing to do on FrameMsg itself.
            return [$this, null];
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
        if ($this->paused) {
            return [$this, null];
        }

        $now = microtime(true);
        $delta = $now - $this->lastTickTime;

        // $elapsed is RAW wall-clock play-time; Sync::targetFrame applies the
        // speed multiplier. (Folding speed into $elapsed here too would square
        // it — the original bug.)
        $newElapsed = $this->elapsed + $delta;
        $target = Sync::targetFrame($newElapsed, $this->fps, $this->speed);

        // Decide skip / hold / advance using the tested Sync engine.
        $nextFrame = $this->currentFrame;
        $nextIndex = $this->frameIndex;
        // Set when the decoder is exhausted this tick (null from next()), so we
        // can stop ticking (or loop) rather than spinning forever at the end.
        $reachedEnd = false;

        if (Sync::shouldSkip($this->frameIndex, $target)) {
            // Behind by more than the skip limit — discard intermediate frames,
            // keeping only the last one decoded, to catch up without lag.
            $skipCount = $target - $this->frameIndex;
            for ($i = 0; $i < $skipCount; $i++) {
                $frame = $this->decoder->next();
                if ($frame === null) {
                    $reachedEnd = true;
                    break;
                }
                $nextFrame = $frame;
                $nextIndex++;
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
            return $this->onReachedEnd($nextFrame, $nextIndex, $newElapsed, $now);
        }

        $nextPlayer = $this->withNewFrame($nextFrame, $nextIndex, $this->decoder, $newElapsed, $now);

        $cmd = $nextPlayer->paused
            ? null
            : Cmd::tick(1.0 / $this->fps, static fn(): Msg => new TickMsg());

        return [$nextPlayer, $cmd];
    }

    /**
     * Handle the decoder running out of frames during a tick.
     *
     * Non-loop: stop the audio, mark the player ended, and return a null Cmd so
     * the tick chain halts (the player no longer reschedules itself). Loop:
     * restart from frame 0 — for a real source by recreating the decoder (and
     * restarting audio from t0); for the '/fake' test path by resetting indices
     * in place (FakeDecoder cannot replay without a rebuild, which is fine for
     * the unit path) — and keep ticking.
     *
     * @return array{0: Model, 1: ?\Closure}
     */
    private function onReachedEnd(?RgbFrame $nextFrame, int $nextIndex, float $newElapsed, float $now): array
    {
        $tick = Cmd::tick(1.0 / $this->fps, static fn(): Msg => new TickMsg());

        if (!$this->loop) {
            // End of stream, no loop: stop audio and freeze on the last frame.
            $this->audioPlayer?->stop();
            $nextPlayer = $this->mutate([
                'ended' => true,
                'currentFrame' => $nextFrame ?? $this->currentFrame,
                'frameIndex' => $nextIndex,
                'elapsed' => $newElapsed,
                'lastTickTime' => $now,
            ]);

            return [$nextPlayer, null];
        }

        // Loop: rebuild the decoder from frame 0 and restart audio from t0 so
        // A/V stay aligned on the new pass. rebuildDecoderAt() handles both the
        // real path (close + DecoderFactory) and the '/fake' path (re-open the
        // injected decoder, which resets it to frame 0) under one branch.
        [$newDecoder, $firstFrame] = $this->rebuildDecoderAt($this->cellsW, $this->cellsH, $this->mode, 0);

        $newAudio = $this->audioPlayer;
        if ($this->audioPlayer !== null) {
            $this->audioPlayer->stop();
            $newAudio = new AudioPlayer($this->videoPath, 0);
            if (!$this->paused) {
                $newAudio->start();
            }
        }

        $nextPlayer = $this->mutate([
            'decoder' => $newDecoder,
            'currentFrame' => $firstFrame ?? $this->currentFrame,
            'frameIndex' => 0,
            'elapsed' => 0.0,
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
        // Quit: Escape, q, or ctrl+c.
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q')
            || ($msg->ctrl && $msg->rune === 'c')) {
            $this->audioPlayer?->stop();
            $this->decoder->close();
            return [$this, Cmd::quit()];
        }

        // Space: toggle pause/resume.
        if ($msg->type === KeyType::Space) {
            $resuming = $this->paused;
            $changes = ['paused' => !$this->paused];

            if ($resuming) {
                // Re-anchor the wall clock to now so $elapsed accumulates
                // play-time only — not time spent on the start screen or paused.
                $changes['lastTickTime'] = microtime(true);
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
                : Cmd::tick(1.0 / $this->fps, static fn(): Msg => new TickMsg());
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
            $nextIndex = $this->frameIndex + 10;
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
        if ($msg->type === KeyType::Char && ctype_digit($msg->rune)) {
            $percent = (int)$msg->rune * 10;
            $nextIndex = (int)(($percent / 100.0) * max(1, $this->totalFrames));
            $nextPlayer = $this->withSeek($nextIndex);
            return [$nextPlayer, $this->seekTickCmd($nextPlayer)];
        }

        // m : cycle rendering mode through ALL implemented modes.
        if ($msg->type === KeyType::Char && $msg->rune === 'm') {
            $modes = Mode::cases();
            $currentIdx = array_search($this->mode, $modes, true);
            $nextMode = $modes[($currentIdx + 1) % count($modes)];

            // Rebuild the decoder so the decoded frame resolution matches the
            // new mode (HalfBlock decodes at 2× cell height). Keep position.
            [$decoder, $frame] = $this->rebuildDecoderAt($this->cellsW, $this->cellsH, $nextMode, $this->frameIndex);

            $nextPlayer = $this->mutate([
                'mode' => $nextMode,
                'decoder' => $decoder,
                'currentFrame' => $frame ?? $this->currentFrame,
            ]);
            return [$nextPlayer, null];
        }

        return [$this, null];
    }

    /**
     * Render the current frame to a full ANSI string.
     *
     * view() always emits the COMPLETE current frame. candy-core's Renderer
     * diffs it against the previously rendered frame and writes only the
     * minimal terminal update, so there is intentionally no cell-diff here —
     * diffing inside view() would double-diff the framework's own diff and
     * corrupt the screen.
     *
     * Ascii/TrueColor/HalfBlock render through a candy-buffer Buffer (the
     * cell grid the plan calls for); Ansi256/Sixel/Kitty/Iterm2 render
     * directly through their FrameRenderer.
     *
     * @return string
     */
    public function view(): string
    {
        $frame = $this->currentFrame;

        // If no frame yet (initial render before first tick), show a
        // placeholder rather than crashing.
        if ($frame === null) {
            return $this->renderPlaceholder();
        }

        // Ansi256 (38;5;N) and the image protocols render directly — they are
        // not expressible through the truecolor-only Buffer cell grid.
        if ($this->mode === Mode::Sixel || $this->mode === Mode::Kitty || $this->mode === Mode::Iterm2 || $this->mode === Mode::Ansi256) {
            $out = $this->renderDirect($frame);
        } else {
            $out = $this->frameToBuffer($frame, $this->mode)->toAnsi();
        }

        // At end-of-stream (non-loop) append a status line so the user can see
        // playback stopped and how to restart. Normal playback output is
        // unchanged, so this adds no per-frame snapshot churn.
        if ($this->ended) {
            return $out . "\r\n" . '[ended]  0 restart  q quit';
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
     */
    private function frameToBuffer(RgbFrame $frame, Mode $mode): Buffer
    {
        $w = $frame->w;
        $h = $frame->h;

        // Use cell dimensions from the frame's native size.
        // For HalfBlock, h is doubled (2 rows per cell).
        $cellDims = $this->detectCellDimensions($mode);
        $cellW = $cellDims['w'];
        $cellH = $cellDims['h'];

        // Buffer cell grid dimensions.
        $bufW = (int)ceil($w / $cellW);
        $bufH = (int)ceil($h / $cellH);
        $buffer = Buffer::new($bufW, $bufH);

        $bytes = $frame->bytes;
        $byteLen = strlen($bytes);

        if ($mode === Mode::HalfBlock) {
            // HalfBlock: each cell shows 2 vertically-stacked pixels.
            // Upper pixel = foreground, lower pixel = background.
            for ($cy = 0; $cy < $bufH; $cy++) {
                for ($cx = 0; $cx < $bufW; $cx++) {
                    // Source pixel row for upper half.
                    $upperY = $cy * 2;
                    // Source pixel row for lower half.
                    $lowerY = $cy * 2 + 1;

                    $upperRgb = $this->pixelRgb($bytes, $w, $byteLen, $cx * $cellW, $upperY);
                    $lowerRgb = $this->pixelRgb($bytes, $w, $byteLen, $cx * $cellW, $lowerY);

                    $fg = $this->rgbToStyleColor($upperRgb[0], $upperRgb[1], $upperRgb[2], $mode);
                    $bg = $this->rgbToStyleColor($lowerRgb[0], $lowerRgb[1], $lowerRgb[2], $mode);

                    $style = new Style($fg, $bg);

                    $cell = Cell::new('▀', $style, null, 1);
                    $buffer = $buffer->withCellAt($cx, $cy, $cell);
                }
            }
        } else {
            // Ascii / Ansi256 / TrueColor: one pixel per cell.
            for ($cy = 0; $cy < $bufH; $cy++) {
                for ($cx = 0; $cx < $bufW; $cx++) {
                    $px = $cx * $cellW;
                    $py = $cy * $cellH;

                    $rgb = $this->pixelRgb($bytes, $w, $byteLen, $px, $py);
                    [$r, $g, $b] = $rgb;

                    $luma = (($r * 77) + ($g * 150) + ($b * 29)) >> 8;
                    $ch = LumaRamp::char((float)$luma);

                    $fg = $this->rgbToStyleColor($r, $g, $b, $mode);
                    $style = $fg !== null ? new Style($fg) : null;

                    $cell = Cell::new($ch, $style, null, 1);
                    $buffer = $buffer->withCellAt($cx, $cy, $cell);
                }
            }
        }

        return $buffer;
    }

    /**
     * Get RGB values for a pixel at (px, py) from raw rgb24 bytes.
     *
     * @return array{int, int, int} [r, g, b]
     */
    private function pixelRgb(string $bytes, int $w, int $byteLen, int $px, int $py): array
    {
        $idx = ($py * $w + $px) * 3;
        if ($idx + 2 >= $byteLen) {
            return [0, 0, 0];
        }
        return [ord($bytes[$idx]), ord($bytes[$idx + 1]), ord($bytes[$idx + 2])];
    }

    /**
     * Convert RGB to a candy-buffer Style color (0xRRGGBB) based on mode.
     *
     * Only the buffer-backed modes reach this — Ascii has no color, the rest
     * (TrueColor/HalfBlock) pack the channels into a 0xRRGGBB int. Ansi256 and
     * the image protocols never use the Buffer path (they renderDirect()).
     *
     * @return int|null 0xRRGGBB color for TrueColor/HalfBlock, null for Ascii
     */
    private function rgbToStyleColor(int $r, int $g, int $b, Mode $mode): ?int
    {
        if ($mode === Mode::Ascii) {
            return null;
        }
        return (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
    }

    /**
     * Detect cell dimensions for a frame given the rendering mode.
     *
     * @return array{w: int, h: int}
     */
    private function detectCellDimensions(Mode $mode): array
    {
        return ['w' => $mode->colsPerCell(), 'h' => $mode->rowsPerCell()];
    }

    /**
     * Direct (non-delta) rendering via FrameRenderer.
     */
    private function renderDirect(RgbFrame $frame): string
    {
        $renderer = RendererFactory::create($this->mode);
        return $renderer->render($frame, $this->mode);
    }

    /**
     * Render a placeholder when no frame is available yet.
     */
    private function renderPlaceholder(): string
    {
        $lines = [];
        for ($i = 0; $i < $this->cellsH; $i++) {
            $lines[] = str_repeat(' ', $this->cellsW);
        }
        $body = implode("\r\n", $lines);
        $status = "loading...  space play  q quit";
        return $body . "\r\n" . $status;
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
        return ($this->ended && !$nextPlayer->paused)
            ? Cmd::tick(1.0 / $this->fps, static fn(): Msg => new TickMsg())
            : null;
    }

    /**
     * Close-and-recreate the decoder at a given size+mode, advanced to $frameIndex.
     * Real paths close the old decoder first (fixes the F21 leak) and build a fresh
     * one via DecoderFactory. The '/fake' test path cannot go through DecoderFactory,
     * so it RE-OPENS the injected decoder instead (a mode-aware fake regenerates its
     * frames at the new mode/size) — this is the test equivalent of a rebuild, not a
     * real-process spawn.
     *
     * @return array{0: Decoder, 1: ?RgbFrame}
     */
    private function rebuildDecoderAt(int $cellsW, int $cellsH, Mode $mode, int $frameIndex): array
    {
        if ($this->videoPath === '/fake') {
            $this->decoder->open($this->videoPath, $cellsW, $cellsH, $this->fps, $mode);
            $decoder = $this->decoder;
        } else {
            $this->decoder->close();                 // F21: never leak the old ffmpeg process
            $decoder = DecoderFactory::create($this->videoPath, $cellsW, $cellsH, $this->fps, $mode);
        }
        $frame = null;
        for ($i = 0; $i <= $frameIndex; $i++) {
            $f = $decoder->next();
            if ($f === null) {
                break;
            }
            $frame = $f;
        }
        return [$decoder, $frame];
    }

    /**
     * Seek to a specific frame index by re-creating the decoder
     * and advancing it to the target frame.
     */
    private function withSeek(int $targetIndex): self
    {
        // Clamp to valid range.
        $targetIndex = max(0, $targetIndex);

        // Backward seek: decoders are forward-only, so close-and-rebuild the
        // decoder and skip forward to the target. rebuildDecoderAt() closes the
        // old decoder first (F21: no leaked ffmpeg process) on the real path.
        if ($targetIndex < $this->frameIndex) {
            [$decoder, $frame] = $this->rebuildDecoderAt($this->cellsW, $this->cellsH, $this->mode, $targetIndex);
            $newElapsed = $targetIndex / ($this->fps * $this->speed);   // keep existing formula — elapsed semantics are Phase 3

            return new self(
                decoder: $decoder,
                mode: $this->mode,
                speed: $this->speed,
                paused: $this->paused,
                elapsed: $newElapsed,
                frameIndex: $targetIndex,
                currentFrame: $frame,
                lastTickTime: microtime(true),
                fps: $this->fps,
                totalFrames: $this->totalFrames,
                cellsW: $this->cellsW,
                cellsH: $this->cellsH,
                videoPath: $this->videoPath,
                audioPlayer: $this->audioPlayer,
                ended: false,
                loop: $this->loop,
            );
        }

        if ($targetIndex === $this->frameIndex) {
            return $this;
        }

        // Forward seek: advance decoder to target frame.
        $frame = $this->currentFrame;
        $idx = $this->frameIndex;
        while ($idx < $targetIndex) {
            $frame = $this->decoder->next();
            if ($frame === null) {
                break;
            }
            $idx++;
        }

        // Compute elapsed time based on the new frame position.
        $newElapsed = $idx / ($this->fps * $this->speed);

        return new self(
            decoder: $this->decoder,
            mode: $this->mode,
            speed: $this->speed,
            paused: $this->paused,
            elapsed: $newElapsed,
            frameIndex: $idx,
            currentFrame: $frame,
            lastTickTime: microtime(true),
            fps: $this->fps,
            totalFrames: $this->totalFrames,
            cellsW: $this->cellsW,
            cellsH: $this->cellsH,
            videoPath: $this->videoPath,
            audioPlayer: $this->audioPlayer,
            ended: false, // a seek clears the ended state
            loop: $this->loop,
        );
    }

    /**
     * Create a new Player with a new decoded frame, advancing frame index.
     */
    private function withNewFrame(
        ?RgbFrame $frame,
        int $frameIndex,
        Decoder $decoder,
        float $elapsed,
        float $lastTickTime,
    ): self {
        return new self(
            decoder: $decoder,
            mode: $this->mode,
            speed: $this->speed,
            paused: $this->paused,
            elapsed: $elapsed,
            frameIndex: $frameIndex,
            currentFrame: $frame ?? $this->currentFrame,
            lastTickTime: $lastTickTime,
            fps: $this->fps,
            totalFrames: $this->totalFrames,
            cellsW: $this->cellsW,
            cellsH: $this->cellsH,
            videoPath: $this->videoPath,
            audioPlayer: $this->audioPlayer,
            // Normal tick advance: ended stays as-is (false during play).
            ended: $this->ended,
            loop: $this->loop,
        );
    }

    /**
     * Generic mutable-update helper: create a new Player with changed fields.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(
            decoder: $changes['decoder'] ?? $this->decoder,
            mode: $changes['mode'] ?? $this->mode,
            speed: $changes['speed'] ?? $this->speed,
            paused: $changes['paused'] ?? $this->paused,
            elapsed: $changes['elapsed'] ?? $this->elapsed,
            frameIndex: $changes['frameIndex'] ?? $this->frameIndex,
            currentFrame: $changes['currentFrame'] ?? $this->currentFrame,
            lastTickTime: $changes['lastTickTime'] ?? $this->lastTickTime,
            fps: $changes['fps'] ?? $this->fps,
            totalFrames: $changes['totalFrames'] ?? $this->totalFrames,
            cellsW: $changes['cellsW'] ?? $this->cellsW,
            cellsH: $changes['cellsH'] ?? $this->cellsH,
            videoPath: $this->videoPath,
            audioPlayer: $changes['audioPlayer'] ?? $this->audioPlayer,
            // ?? is null-coalescing, so passing ended => false / frameIndex => 0
            // through mutate() is honoured (false/0 are not null).
            ended: $changes['ended'] ?? $this->ended,
            loop: $changes['loop'] ?? $this->loop,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
