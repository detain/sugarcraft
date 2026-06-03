<?php

declare(strict_types=1);

namespace SugarCraft\Reel;

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Reel\Render\AutoMode;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Render\RendererFactory;

/**
 * Terminal video player facade — plays a video file by decoding frames on the
 * fly and rendering them to ASCII / ANSI / truecolor half-block / sixel / kitty
 * output, the way `mpv -vo tct`, `tplay`, `video-to-ascii`, and `glyph` do.
 *
 * No single upstream: the decode → render → pace pipeline draws on prior art in
 * maxcurzi/tplay, seatedro/glyph, and joelibaceta/video-to-ascii. The rendering
 * stack is reused from the SugarCraft ecosystem (candy-mosaic image renderers,
 * candy-flip downsampling, candy-palette color mapping, candy-core TEA runtime)
 * rather than reinvented.
 *
 * Usage:
 *   Reel::open('video.mp4')->play();
 *   Reel::open('video.mp4')->withMode(Mode::Ascii)->withSize(120, 40)->play();
 *   Reel::new()->withSize(100, 30)->withFps(30.0)->play(); // with no source (Synthetic test pattern)
 *
 * State is immutable — each `with*()` returns a new Reel instance.
 */
final class Reel
{
    /**
     * @param string      $path  Video file path ('' for synthetic/unbound)
     * @param Mode|null   $mode  Rendering mode (null = auto-detect)
     * @param int          $cols  Terminal cell width
     * @param int          $rows  Terminal cell height
     * @param float|null   $fps   FPS override (null = auto from probe)
     * @param bool         $loop  When true, playback restarts at end instead of stopping
     */
    private function __construct(
        private readonly string $path,
        private readonly ?Mode $mode,
        private readonly int $cols,
        private readonly int $rows,
        private readonly ?float $fps,
        private readonly bool $loop = false,
    ) {
    }

    /**
     * Construct an empty player with no source bound yet.
     *
     * Calling play() on the result will show a synthetic test pattern.
     */
    public static function new(): self
    {
        return new self('', null, 80, 24, null);
    }

    /**
     * Open a video source by path. Does not probe or decode — it only records
     * the path so the instance can be configured before playback.
     */
    public static function open(string $path): self
    {
        return new self($path, null, 80, 24, null);
    }

    /**
     * The source video path this player was opened with ('' when unbound).
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The configured rendering mode (null means auto-detect).
     */
    public function mode(): ?Mode
    {
        return $this->mode;
    }

    /**
     * The configured terminal cell width.
     */
    public function cols(): int
    {
        return $this->cols;
    }

    /**
     * The configured terminal cell height.
     */
    public function rows(): int
    {
        return $this->rows;
    }

    /**
     * The configured FPS override, or null for auto-detect from probe.
     */
    public function fps(): ?float
    {
        return $this->fps;
    }

    /**
     * Whether playback loops back to the start at end-of-stream.
     */
    public function loop(): bool
    {
        return $this->loop;
    }

    /**
     * Set the rendering mode. Returns a new Reel (immutable).
     */
    public function withMode(Mode $mode): self
    {
        return $this->with(mode: $mode);
    }

    /**
     * Set the rendering mode to auto-detect (probed at play time).
     * Returns a new Reel (immutable).
     */
    public function withAutoMode(): self
    {
        return $this->with(mode: new AutoMode());
    }

    /**
     * Enable (or disable) looping: replay from the start at end-of-stream
     * instead of stopping. Returns a new Reel (immutable).
     */
    public function withLoop(bool $loop = true): self
    {
        return $this->with(loop: $loop);
    }

    /**
     * Set the terminal size in cells. Returns a new Reel (immutable).
     */
    public function withSize(int $cols, int $rows): self
    {
        return $this->with(cols: $cols, rows: $rows);
    }

    /**
     * Set a target FPS override. Pass null to use auto-detect from video probe.
     * Returns a new Reel (immutable).
     */
    public function withFps(float $fps): self
    {
        return $this->with(fps: $fps);
    }

    /**
     * Run the player: creates a Player from the configured options and
     * executes the TEA program loop via Program::run().
     *
     * If no path was set (Reel::new()), plays a built-in synthetic test pattern.
     */
    public function play(): void
    {
        $path = $this->path;

        // When unbound, generate synthetic test pattern.
        if ($path === '') {
            $path = $this->buildSyntheticGif();
        }

        // Resolve auto-mode to the best available mode at runtime (F3).
        $resolvedMode = $this->mode ?? RendererFactory::autoMode();

        // Create the Player with the configured dimensions, fps, render mode and loop flag.
        $player = Player::open($path, $this->cols, $this->rows, $this->fps, $resolvedMode, $this->loop);

        $options = new ProgramOptions(
            useAltScreen: true,
            hideCursor: true,
        );

        (new Program($player, $options))->run();
    }

    /**
     * Build a rainbow gradient GIF in /tmp as a synthetic test pattern.
     * Used when play() is called on Reel::new() (no path bound).
     */
    private function buildSyntheticGif(): string
    {
        $path = '/tmp/sugar-reel-synthetic.gif';

        if (!extension_loaded('gd')) {
            // Fall back to a simple 1×1 transparent GIF if GD is absent.
            $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x01\x00;";
            file_put_contents($path, $gif);
            return $path;
        }

        $w = 120;
        $h = 60;
        $im = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $r = (int) min(255, 255 * $x / $w);
                $g = (int) min(255, 255 * $y / $h);
                $b = (int) min(255, 255 * (($x + $y) % $w) / $w);
                $col = imagecolorallocate($im, $r, $g, $b);
                imagesetpixel($im, $x, $y, $col);
            }
        }
        imagegif($im, $path);
        imagedestroy($im);

        return $path;
    }

    /**
     * Generic immutable-update helper: create a new Reel with changed fields.
     *
     * @param string             $path  Leave null to keep current
     * @param Mode|AutoMode|null $mode  Leave null to keep current; AutoMode sets null (auto-detect)
     * @param int                $cols  Leave null to keep current
     * @param int                $rows  Leave null to keep current
     * @param float|null         $fps   Leave null to keep current
     * @param bool|null          $loop  Leave null to keep current
     */
    private function with(
        ?string $path = null,
        Mode|AutoMode|null $mode = null,
        ?int $cols = null,
        ?int $rows = null,
        ?float $fps = null,
        ?bool $loop = null,
    ): self {
        // AutoMode sentinel → null (play() will resolve to auto-detected mode).
        $resolvedMode = $mode instanceof AutoMode ? null : ($mode ?? $this->mode);

        return new self(
            $path ?? $this->path,
            $resolvedMode,
            $cols ?? $this->cols,
            $rows ?? $this->rows,
            $fps ?? $this->fps,
            $loop ?? $this->loop,
        );
    }
}
