<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Decoder as AnimationDecoder;
use SugarCraft\Video\Decode\GifDecoder;
use SugarCraft\Video\Render\Mode;

/**
 * E-11: the graphics upscale must be ONE resample in true-colour space at use
 * time, not the historical double loss (box-aspect area squeeze in the PHP
 * decode grid, then a GD stretch back to the terminal box).
 *
 * The fixture is deliberately palette-adversarial: a 1-pixel checkerboard of
 * two same-hue, far-apart reds. The double-loss path area-averages adjacent
 * columns into a 50% blend the source never had, then magnifies that mush —
 * colours invented by the pipeline, not by the content. The single-resample
 * path starts from a 1:1 source-faithful grid, so GD's resample writes
 * exactly the two source colours over 90% of the pixels (measured at this
 * fixture/box) and invents zero half-blends. The old path is reconstructed
 * here as an in-test oracle FROM THE DECODED CELLS, so both sides share the
 * GIF loader's colour basis and the only variable is the resample count.
 *
 * The second axis is E-12: the fix must live entirely in the transient
 * per-frame pass. The resident form stays the indexed cell grid — no frame
 * may carry the decode-time canvas RGBA capture (N × W × H × 4 resident is
 * exactly what the cap law forbids a player to pay), and the decodeBytes
 * call site must keep passing no third argument.
 *
 * @covers \SugarCraft\Video\Decode\GifDecoder
 */
final class E11UpscaleFidelityTest extends TestCase
{
    /** Two same-hue reds whose 1-px alternation is the resampler's worst client. */
    private const COLOR_A = [255, 0, 0];
    private const COLOR_B = [102, 0, 0];

    /** Source pixel size of the adversarial fixture. */
    private const SRC_W = 12;
    private const SRC_H = 6;

    /** Terminal box the fixture is presented into: 8×3 cells at 10×20 px. */
    private const BOX_W = 80;
    private const BOX_H = 60;
    private const CELLS_W = 8;
    private const CELLS_H = 3;

    /** The grid the OLD box-aspect rule asked for at the same box: min(12/80, 6/60) = 0.1. */
    private const OLD_GRID_W = 8;
    private const OLD_GRID_H = 6;

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->paths = [];
        parent::tearDown();
    }

    /**
     * @testdox the graphics decode grid is the source's own size, not a box-aspect squeeze
     */
    public function testDecodeGridIsSourceFaithful(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $path = $this->createCheckerboardGif(self::SRC_W, self::SRC_H);

        $decoder = new GifDecoder();
        $decoder->open($path, self::CELLS_W, self::CELLS_H, 10.0, Mode::Sixel);
        $frame = $decoder->next();
        // Behavioural half: the resident frame's grid IS the screen — sampleCanvas
        // at 1:1, every cell one source pixel, no invented aspect. (Read before
        // close(): close() drops the frames by contract.)
        $grid = $this->residentFrames($decoder)[0]->cells;
        $decoder->close();

        $this->assertNotNull($frame);
        $this->assertSame(self::BOX_W, $frame->w, 'upscaled width still lands on the full box');
        $this->assertSame(self::BOX_H, $frame->h);

        $this->assertSame(self::SRC_H, count($grid), 'resident grid height = source height');
        $this->assertSame(self::SRC_W, count($grid[0]), 'resident grid width = source width (the old rule squeezed 12 to 8)');
    }

    /**
     * @testdox the single-resample output keeps the source colours the double-loss path blended away
     */
    public function testSingleResamplePreservesWhatTheDoubleLossSqueezedAway(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $path = $this->createCheckerboardGif(self::SRC_W, self::SRC_H);

        $decoder = new GifDecoder();
        $decoder->open($path, self::CELLS_W, self::CELLS_H, 10.0, Mode::Sixel);
        $frame = $decoder->next();
        $cells = $this->residentFrames($decoder)[0]->cells;
        $decoder->close();

        $this->assertNotNull($frame);
        $this->assertNotNull($frame->png, 'a graphics-mode frame is presented as PNG at the box');
        $this->assertSame('', $frame->bytes);

        // Both paths share the GIF loader's colour basis: compare against the
        // decoded cell colours themselves, never the pre-encode RGB.
        $a = $cells[0][0];
        $b = $cells[0][1];
        $this->assertNotSame($a, $b, 'the fixture must load as two distinct colours');
        // The exact half-blend sampleCanvas() would write when it averaged one
        // pair of adjacent columns — the colour the source never contained.
        $blend = [
            (int) round(($a[0] + $b[0]) / 2),
            (int) round(($a[1] + $b[1]) / 2),
            (int) round(($a[2] + $b[2]) / 2),
        ];

        $new = $this->classifyPixels($frame->png, $a, $b, $blend);
        $oracle = $this->classifyPixels($this->doubleLossOraclePng($cells), $a, $b, $blend);

        $this->assertNotSame(
            $oracle[3],
            $frame->png,
            'the single-resample output must differ from the reconstructed double-loss path',
        );
        $this->assertGreaterThan(0, $oracle[1], 'the oracle really did invent half-blend pixels — otherwise this proves nothing');
        $this->assertSame(0, $new[1], 'the single-resample pass invents no 50%-blend colour the source lacked');
        $this->assertGreaterThan($oracle[0], $new[0], 'more pixels keep an exact source colour than the double-loss path managed');
        $total = self::BOX_W * self::BOX_H;
        $this->assertGreaterThan(intdiv(85 * $total, 100), $new[0], 'exact-source colour must cover at least 85% of the presentation');
    }

    /**
     * @testdox no resident frame carries a decode-time canvas RGBA capture (E-12)
     *
     * The quality fix must stay transient. This reddens the moment anyone
     * solves E-11 by flipping decodeBytes' $withCanvasRgba at hydrate, which
     * would keep N × W × H × 4 alive for the whole playback lifetime.
     */
    public function testResidentFramesAreCellOnlyUnderTheE12MemoryModel(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $path = $this->createCheckerboardGif(self::SRC_W, self::SRC_H);

        $decoder = new GifDecoder();
        $decoder->open($path, self::CELLS_W, self::CELLS_H, 10.0, Mode::Sixel);
        $frames = $this->residentFrames($decoder);
        $decoder->close();

        $this->assertNotSame([], $frames);
        foreach ($frames as $index => $frame) {
            $this->assertFalse($frame->hasRgba(), "resident frame $index carries a canvas RGBA capture — E-12 residency violation");
            // The packed buffer is genuinely absent, not merely flagged off:
            // rgbaBytes() only answers for a captured frame. (rgbaWidth() is
            // the canvas the capture WOULD cover — non-zero by design — so the
            // residency claim is pinned through the buffer itself.)
            $captured = null;
            try {
                $captured = $frame->rgbaBytes();
                $this->fail("resident frame $index exposed a canvas RGBA buffer");
            } catch (\LogicException) {
                $this->assertNull($captured);
            }
        }

        // Structural twin: the decode call site must keep passing no capture flag.
        $source = (string) file_get_contents($this->decoderSourcePath());
        $this->assertSame(
            1,
            preg_match_all('/decodeBytes\(\$bytes, \$decodeW, \$decodeH\)/', $source),
            'the graphics path must hydrate through the exact no-capture decodeBytes call',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/decodeBytes\([^)]*canvasRgba[^)]*\)|decodeBytes\([^)]*,\s*true\s*\)/',
            $source,
            'a decode-time RGBA capture must not re-enter the player path (E-12)',
        );
    }

    /**
     * @testdox the grid shrinks uniformly to the residency cap, preserving the source aspect
     */
    public function testDecodeGridClampsToCapAspectPreservingly(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $decoder = new GifDecoder();
        $grid = new \ReflectionMethod($decoder, 'graphicsDecodeGrid');

        // 500×500 = 250,000 cells > MAX_CELLS: the old rule fit the BOX aspect
        // (800×480 → 408×245, a non-uniform 500→408/500→245 squint); the new
        // rule fits the cap through the SOURCE aspect — floor(500·sqrt(100000/250000)) = 316.
        $big = $this->createCheckerboardGif(500, 500);
        $bigGrid = $grid->invoke($decoder, (string) file_get_contents($big), 800, 480);
        $this->assertSame([316, 316], $bigGrid);
        $this->assertLessThanOrEqual(AnimationDecoder::MAX_CELLS, $bigGrid[0] * $bigGrid[1]);

        // Under the cap the source size passes through untouched.
        $small = $this->createCheckerboardGif(self::SRC_W, self::SRC_H);
        $this->assertSame([self::SRC_W, self::SRC_H], $grid->invoke($decoder, (string) file_get_contents($small), 800, 480));

        // Unknown screen descriptor: legacy box fallback, cap-clamped (the box
        // itself, 800×480, exceeds the cap → the sqrt fit 408×245).
        $this->assertSame([408, 244], $grid->invoke($decoder, str_repeat('x', 20), 800, 480));
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    /**
     * The resident frame list — the indexed representation E-12 keeps.
     *
     * @return list<\SugarCraft\Animation\Frame>
     */
    private function residentFrames(GifDecoder $decoder): array
    {
        $property = new \ReflectionProperty($decoder, 'frames');

        return $property->getValue($decoder);
    }

    private function decoderSourcePath(): string
    {
        $path = (new \ReflectionClass(GifDecoder::class))->getFileName();
        $this->assertIsString($path);

        return $path;
    }

    /**
     * Reconstruct the retired double-loss path as an oracle: area-average the
     * source-faithful cells down to the box-aspect grid (exactly the floor
     * arithmetic sugar-animation's sampleCanvas used), then hand that grid to
     * the same GD resample the old code ran. Shared colour basis with the live
     * path by construction — the only difference is the extra squeeze.
     */
    private function doubleLossOraclePng(array $cells): string
    {
        $grid = imagecreatetruecolor(self::OLD_GRID_W, self::OLD_GRID_H);
        $this->assertNotFalse($grid);

        for ($cy = 0; $cy < self::OLD_GRID_H; $cy++) {
            for ($cx = 0; $cx < self::OLD_GRID_W; $cx++) {
                $x0 = (int) ($cx * self::SRC_W / self::OLD_GRID_W);
                $x1 = max($x0, (int) (($cx + 1) * self::SRC_W / self::OLD_GRID_W) - 1);
                $sumR = 0;
                $sumG = 0;
                $sumB = 0;
                $count = 0;
                for ($sx = $x0; $sx <= $x1; $sx++) {
                    $cell = $cells[$cy][$sx];
                    $this->assertNotNull($cell, 'the fixture has no transparent pixels');
                    $sumR += $cell[0];
                    $sumG += $cell[1];
                    $sumB += $cell[2];
                    $count++;
                }
                imagesetpixel(
                    $grid,
                    $cx,
                    $cy,
                    $this->allocatePair($grid, [(int) round($sumR / $count), (int) round($sumG / $count), (int) round($sumB / $count)]),
                );
            }
        }

        $dst = imagecreatetruecolor(self::BOX_W, self::BOX_H);
        $this->assertNotFalse($dst);
        imagecopyresampled($dst, $grid, 0, 0, 0, 0, self::BOX_W, self::BOX_H, self::OLD_GRID_W, self::OLD_GRID_H);

        ob_start();
        $ok = imagepng($dst, null, 1);
        $png = (string) ob_get_clean();
        imagedestroy($dst);
        imagedestroy($grid);
        $this->assertNotFalse($ok);

        return $png;
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function allocatePair(\GdImage $image, array $rgb): int
    {
        $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        $this->assertNotFalse($color);

        return $color;
    }

    /**
     * Count pixels keeping an exact source colour [0], pixels landing on the
     * invented half-blend [1], pixels elsewhere [2], and echo the PNG [3].
     *
     * @return array{0:int,1:int,2:int,3:string}
     */
    private function classifyPixels(string $png, array $a, array $b, array $blend): array
    {
        $image = imagecreatefromstring($png);
        $this->assertNotFalse($image);

        $pure = 0;
        $mushed = 0;
        $other = 0;
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $pixel = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $color = [$pixel['red'], $pixel['green'], $pixel['blue']];
                if ($color === $a || $color === $b) {
                    $pure++;
                } elseif ($color === $blend) {
                    $mushed++;
                } else {
                    $other++;
                }
            }
        }
        imagedestroy($image);

        return [$pure, $mushed, $other, $png];
    }

    /** A single-frame GD checkerboard of the two fixture colours. */
    private function createCheckerboardGif(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        $this->assertNotFalse($image);
        $a = imagecolorallocate($image, self::COLOR_A[0], self::COLOR_A[1], self::COLOR_A[2]);
        $b = imagecolorallocate($image, self::COLOR_B[0], self::COLOR_B[1], self::COLOR_B[2]);
        $this->assertNotFalse($a);
        $this->assertNotFalse($b);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                imagesetpixel($image, $x, $y, (($x + $y) % 2 === 0) ? $a : $b);
            }
        }

        $path = $this->track(tempnam(sys_get_temp_dir(), 'sugar-video-e11') . '.gif');
        $this->assertNotFalse(imagegif($image, $path));
        imagedestroy($image);

        return $path;
    }

    private function track(string $path): string
    {
        $this->paths[] = $path;

        return $path;
    }
}
