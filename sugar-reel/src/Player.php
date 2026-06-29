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
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Palette\Probe;
use SugarCraft\Palette\Probe\Capability;
use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\DecoderFactory;
use SugarCraft\Reel\Decode\RgbFrame;
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
     * @param \Closure                    $audioFactory Factory fn(string $path, ?int $startMs): AudioPlayer
     * @param int                         $cellPxW      Pixel width of a terminal cell (graphics-mode resolution)
     * @param int                         $cellPxH      Pixel height of a terminal cell
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
     * @param string     $ramp         Luma ramp name: 'minimal', 'standard', 'dense'
     * @param int        $cellPxW      Pixel width of a terminal cell — graphics modes decode at
     *                                 cells·cellPx for full resolution (caller probes it; 10×20 default)
     * @param int        $cellPxH      Pixel height of a terminal cell
     */
    public static function open(string $videoPath, int $cellsW, int $cellsH, ?float $fpsOverride = null, Mode $mode = Mode::HalfBlock, bool $loop = false, string $ramp = 'standard', int $cellPxW = 10, int $cellPxH = 20): self
    {
        $source = VideoSource::probe($videoPath);

        // FPS from probe, default 24 if not available. Use override when set.
        $fps = $fpsOverride ?? ($source->fps > 0.0 ? $source->fps : 24.0);

        // Compute totalFrames from duration and fps when both are known.
        $totalFrames = ($source->duration > 0.0 && $fps > 0.0)
            ? (int)round($source->duration * $fps) : 0;

        // Decoder resolution is keyed to the render mode (HalfBlock decodes at
        // 2× cell height; graphics modes at cells·cellPx). Seek recreates the
        // decoder with the current mode and the same cell geometry.
        $decoder = DecoderFactory::create($videoPath, $cellsW, $cellsH, $fps, $mode, 0.0, $cellPxW, $cellPxH);

        // Audio companion factory: creates AudioPlayer on demand. The factory
        // is threaded through the Player so tests can inject a spy subclass.
        $audioFactory = static fn(string $path, ?int $startMs = null): AudioPlayer
            => new AudioPlayer($path, $startMs);

        // Audio companion is created when the source has audio but is NOT
        // started here — the Player starts it on first play (see updateKey)
        // so audio and the video wall clock share the same t0.
        $audioPlayer = $source->hasAudio ? $audioFactory($videoPath, null) : null;

        // Player starts paused; the first tick is scheduled when playback begins.
        return new self(
            decoder: $decoder,
            mode: $mode,
            speed: 1.0,
            paused: true,
            videoTime: 0.0,
            frameIndex: 0,
            currentFrame: null,
            lastTickTime: microtime(true),
            fps: $fps,
            totalFrames: $totalFrames,
            cellsW: $cellsW,
            cellsH: $cellsH,
            videoPath: $videoPath,
            audioPlayer: $audioPlayer,
            ended: false,
            loop: $loop,
            ramp: $ramp,
            audioFactory: $audioFactory,
            cellPxW: $cellPxW,
            cellPxH: $cellPxH,
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
     * @param \Closure     $audioFactory   Factory fn(string $path, ?int $startMs): AudioPlayer
     * @param AudioPlayer  $audioPlayer   Optional initial AudioPlayer instance (for spy injection)
     * @param bool         $paused         Start paused (default true)
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
    ): self {
        // Default factory when none supplied (produces a real AudioPlayer — fine
        // for openForTest since audio is not started in the paused initial state).
        $factory = $audioFactory ?? static fn(string $path, ?int $startMs = null): AudioPlayer
            => new AudioPlayer($path, $startMs);

        return new self(
            decoder: $decoder,
            mode: Mode::HalfBlock,
            speed: 1.0,
            paused: $paused,
            videoTime: 0.0,
            frameIndex: 0,
            currentFrame: null,
            lastTickTime: microtime(true),
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

        // F10: WindowSizeMsg — resize the player cell dimensions and rebuild
        // the decoder so frames are decoded at the correct resolution.
        if ($msg instanceof \SugarCraft\Core\Msg\WindowSizeMsg) {
            return $this->updateResize($msg->cols, $msg->rows);
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
            return $this->onReachedEnd($nextFrame, $nextIndex, $newVideoTime, $now);
        }

        $nextPlayer = $this->withNewFrame($nextFrame, $nextIndex, $this->decoder, $newVideoTime, $now);

        $cmd = $nextPlayer->paused
            ? null
            : Cmd::tick(1.0 / $this->fps, static fn(): Msg => new TickMsg());

        return [$nextPlayer, $cmd];
    }

    /**
     * Handle a WindowSizeMsg: clamp, no-op if unchanged, otherwise rebuild
     * the decoder at the new cell dimensions and reschedule ticks.
     *
     * @return array{0:Player, 1:?Closure}
     */
    private function updateResize(int $cols, int $rows): array
    {
        $cols = max(10, min($cols, 200));
        $rows = max(5, min($rows, 80));

        if ($cols === $this->cellsW && $rows === $this->cellsH) {
            return [$this, null]; // no-op when unchanged
        }

        [$decoder, $frame] = $this->rebuildDecoderAt($cols, $rows, $this->mode, $this->frameIndex);

        $nextPlayer = $this->mutate([
            'cellsW' => $cols,
            'cellsH' => $rows,
            'decoder' => $decoder,
            'currentFrame' => $frame ?? $this->currentFrame,
            'lastTickTime' => microtime(true),
        ]);

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
    private function onReachedEnd(?RgbFrame $nextFrame, int $nextIndex, float $newVideoTime, float $now): array
    {
        $tick = Cmd::tick(1.0 / $this->fps, static fn(): Msg => new TickMsg());

        if (!$this->loop) {
            // End of stream, no loop: stop audio and freeze on the last frame.
            $this->audioPlayer?->stop();
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
        // real path (close + DecoderFactory) and the '/fake' path (re-open the
        // injected decoder, which resets it to frame 0) under one branch.
        [$newDecoder, $firstFrame] = $this->rebuildDecoderAt($this->cellsW, $this->cellsH, $this->mode, 0);

        $newAudio = $this->audioPlayer;
        if ($this->audioPlayer !== null) {
            $this->audioPlayer->stop();
            // Use the audioFactory seam so tests can inject a spy AudioPlayer.
            $factory = $this->audioFactory ?? static fn(string $path, ?int $ms): AudioPlayer => new AudioPlayer($path, $ms);
            $newAudio = $factory($this->videoPath, 0);
            if (!$this->paused) {
                $newAudio->start();
            }
        }

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
        // Build the cycle dynamically based on Mosaic::diagnose() capabilities (F2 tail).
        if ($msg->type === KeyType::Char && $msg->rune === 'm') {
            // Always present: text modes.
            $textModes = [Mode::Ascii, Mode::Ansi256, Mode::TrueColor, Mode::HalfBlock, Mode::QuarterBlock];
            // Graphics modes only if the terminal reports the capability.
            $report = Mosaic::diagnose();
            $graphicsModes = [];
            if ($report->has(Capability::Sixel)) {
                $graphicsModes[] = Mode::Sixel;
            }
            if ($report->has(Capability::KittyKeyboard)) {
                // Note: Kitty image mode is gated on Capability::KittyKeyboard because
                // candy-palette exposes no standalone KittyGraphics capability.
                $graphicsModes[] = Mode::Kitty;
            }
            if ($report->has(Capability::ITerm2)) {
                $graphicsModes[] = Mode::Iterm2;
            }
            $allModes = array_merge($textModes, $graphicsModes);

            // Guard: if current mode is somehow not in the cycle, start from first.
            $currentIdx = array_search($this->mode, $allModes, true);
            if ($currentIdx === false) {
                $currentIdx = -1;
            }
            $nextMode = $allModes[($currentIdx + 1) % count($allModes)];

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
        // The digit restart hint ("0 restart") is only shown when totalFrames > 0
        // because digit-seek is a no-op on streams of unknown length.
        if ($this->ended) {
            $hint = $this->totalFrames > 0 ? '0 restart  q quit' : 'q quit';
            return $out . "\r\n" . '[ended]  ' . $hint;
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
            // INLINE HalfBlock path: renders directly via Buffer → toAnsi().
            // This is the runtime path used by Player::view() for HalfBlock.
            // There is a SEPARATE HalfBlockRenderer (wired at RendererFactory:98)
            // that is NEVER reached by the Player runtime — only by direct
            // factory use / tests. The parity test testHalfBlockInlineMatchesMosaicRenderer
            // guards that these two paths produce identical colored half-blocks,
            // so they can't silently drift apart.
            //
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
                        $this->pixelRgb($bytes, $w, $byteLen, $x0,     $y0),     // UL
                        $this->pixelRgb($bytes, $w, $byteLen, $x0 + 1, $y0),     // UR
                        $this->pixelRgb($bytes, $w, $byteLen, $x0,     $y0 + 1), // LL
                        $this->pixelRgb($bytes, $w, $byteLen, $x0 + 1, $y0 + 1), // LR
                    ];
                    [$glyph, $fgInt, $bgInt] = self::quarterCell($quads);
                    $cell = Cell::new($glyph, new Style($fgInt, $bgInt), null, 1);
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
                    $ch = LumaRamp::char((float)$luma, $this->ramp);

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
        $luma = static fn (array $c): int => (($c[0] * 77) + ($c[1] * 150) + ($c[2] * 29)) >> 8;
        $pack = static fn (array $c): int => (($c[0] & 0xFF) << 16) | (($c[1] & 0xFF) << 8) | ($c[2] & 0xFF);

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
        $renderer = RendererFactory::create($this->mode, $this->ramp, $this->cellPxW, $this->cellPxH);
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
            $decoder = DecoderFactory::create($this->videoPath, $cellsW, $cellsH, $this->fps, $mode, 0.0, $this->cellPxW, $this->cellPxH);
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
    public function withSeek(int $targetIndex): self
    {
        // Clamp to valid range.
        $targetIndex = max(0, $targetIndex);

        // F6: realign audio to the seek target position.
        $newAudio = $this->audioPlayer;
        if ($this->audioPlayer !== null) {
            $this->audioPlayer->stop();
            $startMs = (int)round(($targetIndex / $this->fps) * 1000);
            // Use the audioFactory seam so tests can intercept AudioPlayer creation.
            $factory = $this->audioFactory ?? static fn(string $path, ?int $ms): AudioPlayer => new AudioPlayer($path, $ms);
            $newAudio = $factory($this->videoPath, $startMs);
            if (!$this->paused) {
                $newAudio->start();
            }
        }

        // Backward seek: decoders are forward-only, so close-and-rebuild the
        // decoder and skip forward to the target. rebuildDecoderAt() closes the
        // old decoder first (F21: no leaked ffmpeg process) on the real path.
        if ($targetIndex < $this->frameIndex) {
            [$decoder, $frame] = $this->rebuildDecoderAt($this->cellsW, $this->cellsH, $this->mode, $targetIndex);
            $newVideoTime = $targetIndex / $this->fps; // videoTime = content time (not scaled)

            return new self(
                decoder: $decoder,
                mode: $this->mode,
                speed: $this->speed,
                paused: $this->paused,
                videoTime: $newVideoTime,
                frameIndex: $targetIndex,
                currentFrame: $frame,
                lastTickTime: microtime(true),
                fps: $this->fps,
                totalFrames: $this->totalFrames,
                cellsW: $this->cellsW,
                cellsH: $this->cellsH,
                videoPath: $this->videoPath,
                audioPlayer: $newAudio,
                ended: false,
                loop: $this->loop,
                ramp: $this->ramp,
                audioFactory: $this->audioFactory,
                cellPxW: $this->cellPxW,
                cellPxH: $this->cellPxH,
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

        // F4 fix: videoTime = content time (not scaled by speed).
        $newVideoTime = $idx / $this->fps;

        return new self(
            decoder: $this->decoder,
            mode: $this->mode,
            speed: $this->speed,
            paused: $this->paused,
            videoTime: $newVideoTime,
            frameIndex: $idx,
            currentFrame: $frame,
            lastTickTime: microtime(true),
            fps: $this->fps,
            totalFrames: $this->totalFrames,
            cellsW: $this->cellsW,
            cellsH: $this->cellsH,
            videoPath: $this->videoPath,
            audioPlayer: $newAudio,
            ended: false, // a seek clears the ended state
            loop: $this->loop,
            ramp: $this->ramp,
            audioFactory: $this->audioFactory,
            cellPxW: $this->cellPxW,
            cellPxH: $this->cellPxH,
        );
    }

    /**
     * Current playback position in content seconds (videoTime).
     */
    public function position(): float
    {
        return $this->videoTime;
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
     * intervening frame. Audio is realigned to the same offset. Returns a new
     * Player (immutable); a host screen decides whether to re-arm the tick.
     */
    public function seekToSeconds(float $sec): self
    {
        $sec = max(0.0, $sec);
        $duration = $this->duration();
        if ($duration > 0.0 && $sec > $duration) {
            $sec = $duration;
        }
        $targetIndex = (int) round($sec * $this->fps);

        // Realign audio to the seek target (same factory seam as withSeek()).
        $newAudio = $this->audioPlayer;
        if ($this->audioPlayer !== null) {
            $this->audioPlayer->stop();
            $startMs = (int) round($sec * 1000);
            $factory = $this->audioFactory ?? static fn(string $path, ?int $ms): AudioPlayer => new AudioPlayer($path, $ms);
            $newAudio = $factory($this->videoPath, $startMs);
            if (!$this->paused) {
                $newAudio->start();
            }
        }

        [$decoder, $frame] = $this->rebuildDecoderAtSeconds($this->cellsW, $this->cellsH, $this->mode, $sec);

        return $this->mutate([
            'decoder' => $decoder,
            'currentFrame' => $frame ?? $this->currentFrame,
            'frameIndex' => $targetIndex,
            'videoTime' => $sec,
            'lastTickTime' => microtime(true),
            'audioPlayer' => $newAudio,
            'ended' => false, // a seek clears the end-of-stream state
        ]);
    }

    /**
     * Grab a single frame at $sec for a scrubber-hover thumbnail, WITHOUT
     * disturbing live playback. Spawns a throwaway decoder seeked to $sec (fast
     * `-ss`), reads one frame, and closes it. Returns null for a synthetic /
     * test / unbound source, or when the grab yields nothing.
     */
    public function frameAt(float $sec): ?RgbFrame
    {
        if ($this->videoPath === '' || $this->videoPath === '/fake') {
            return null;
        }
        $decoder = DecoderFactory::create($this->videoPath, $this->cellsW, $this->cellsH, $this->fps, $this->mode, max(0.0, $sec), $this->cellPxW, $this->cellPxH);
        $frame = $decoder->next(); // with -ss, the first frame is at/near $sec
        $decoder->close();

        return $frame;
    }

    /**
     * Tear down playback: stop the audio companion and close the decoder
     * (terminating its ffmpeg subprocess). Idempotent — safe to call more than
     * once. A host screen calls this when leaving the player so no audio/video
     * subprocess leaks across play → back.
     */
    public function stop(): void
    {
        $this->audioPlayer?->stop();
        $this->decoder->close();
    }

    /**
     * Build a fresh decoder seeked to $startSec via fast ffmpeg input seeking
     * (`-ss`), returning the decoder and its first (target) frame. The real path
     * closes the old decoder first (F21: no leaked ffmpeg) and threads startSec
     * through DecoderFactory; the '/fake' test path has no `-ss`, so it falls
     * back to the index-based rebuild (decode-forward to the equivalent frame).
     *
     * @return array{0: Decoder, 1: ?RgbFrame}
     */
    private function rebuildDecoderAtSeconds(int $cellsW, int $cellsH, Mode $mode, float $startSec): array
    {
        if ($this->videoPath === '/fake') {
            $index = (int) round(max(0.0, $startSec) * $this->fps);
            return $this->rebuildDecoderAt($cellsW, $cellsH, $mode, $index);
        }

        $this->decoder->close(); // never leak the old ffmpeg process
        $decoder = DecoderFactory::create($this->videoPath, $cellsW, $cellsH, $this->fps, $mode, max(0.0, $startSec), $this->cellPxW, $this->cellPxH);
        $frame = $decoder->next(); // with -ss, the first frame IS the seek target

        return [$decoder, $frame];
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
        return new self(
            decoder: $decoder,
            mode: $this->mode,
            speed: $this->speed,
            paused: $this->paused,
            videoTime: $videoTime,
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
            ramp: $this->ramp,
            audioFactory: $this->audioFactory,
            cellPxW: $this->cellPxW,
            cellPxH: $this->cellPxH,
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
            videoTime: $changes['videoTime'] ?? $this->videoTime,
            frameIndex: $changes['frameIndex'] ?? $this->frameIndex,
            currentFrame: $changes['currentFrame'] ?? $this->currentFrame,
            lastTickTime: $changes['lastTickTime'] ?? $this->lastTickTime,
            fps: $changes['fps'] ?? $this->fps,
            totalFrames: $changes['totalFrames'] ?? $this->totalFrames,
            cellsW: $changes['cellsW'] ?? $this->cellsW,
            cellsH: $changes['cellsH'] ?? $this->cellsH,
            videoPath: $this->videoPath, // pinned — videoPath is immutable for the player's lifetime
            audioPlayer: $changes['audioPlayer'] ?? $this->audioPlayer,
            // ?? is null-coalescing, so passing ended => false / frameIndex => 0
            // through mutate() is honourée (false/0 are not null).
            ended: $changes['ended'] ?? $this->ended,
            loop: $changes['loop'] ?? $this->loop,
            ramp: $changes['ramp'] ?? $this->ramp,
            audioFactory: $changes['audioFactory'] ?? $this->audioFactory,
            cellPxW: $changes['cellPxW'] ?? $this->cellPxW,
            cellPxH: $changes['cellPxH'] ?? $this->cellPxH,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
