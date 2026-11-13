<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Decoder as AnimationDecoder;
use SugarCraft\Video\Decode\Decoder;
use SugarCraft\Video\Decode\DecoderFactory;
use SugarCraft\Video\Decode\GifDecoder;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Source\Probe;
use SugarCraft\Video\Synthetic;
use SugarCraft\Video\Tests\Concerns\CapturesErrorLog;

/**
 * Unit tests for GifDecoder.
 *
 * Creates a minimal valid GIF using PHP's built-in GD functions,
 * then tests GifDecoder against it. No hand-crafted hex bytes needed.
 *
 * @covers \SugarCraft\Video\Decode\GifDecoder
 */
final class GifDecoderTest extends TestCase
{
    use CapturesErrorLog;

    private ?string $tempGifPath = null;

    protected function tearDown(): void
    {
        GifDecoder::setEmitCacheCeilingForTesting(null);
        if ($this->tempGifPath !== null && file_exists($this->tempGifPath)) {
            unlink($this->tempGifPath);
            $this->tempGifPath = null;
        }
        parent::tearDown();
    }

    /**
     * Create a minimal valid 1×1 black pixel GIF using PHP's GD functions,
     * write it to a temp file, and return the path.
     */
    private function createTempGif(): string
    {
        // Create a 1×1 black pixel image using GD
        $img = imagecreate(1, 1);
        $black = imagecolorallocate($img, 0, 0, 0); // black
        imagesetpixel($img, 0, 0, $black);

        $path = sys_get_temp_dir() . '/sugarcraft-gif-test-' . uniqid('', true) . '.gif';
        imagegif($img, $path);
        imagedestroy($img);

        $this->tempGifPath = $path;
        return $path;
    }

    // -------------------------------------------------------------------------
    // open + next returns an RgbFrame
    // -------------------------------------------------------------------------

    /**
     * @testdox open() followed by next() returns an RgbFrame with correct dimensions
     *
     * With no $mode the decoder defaults to HalfBlock semantics (2 source rows
     * per cell), so a 1×1 cell GIF decodes to 1 col × 2 pixel-rows.
     */
    public function testOpenAndNextReturnsRgbFrame(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0);

        $frame = $decoder->next();

        $this->assertInstanceOf(RgbFrame::class, $frame);
        $this->assertSame(1, $frame->w);
        // $mode === null defaults to HalfBlock (rowsPerCell 2): cellsH 1 → 2 rows.
        $this->assertSame(2, $frame->h);
        // RgbFrame bytes length = w * h * 3 = 1 * 2 * 3 = 6
        $this->assertSame(6, strlen($frame->bytes));

        $decoder->close();
    }

    /**
     * @testdox a zero startSec starts from the first frame
     */
    public function testStartSecZeroStartsFromFirstFrame(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0, Mode::HalfBlock, 0.0);

        $this->assertInstanceOf(RgbFrame::class, $decoder->next());
        $decoder->close();
    }

    /**
     * @testdox a positive startSec past the only frame clamps onto it (E-11)
     *
     * The fixture GIF has a single frame, so seeking to 1.0s @ 10fps (frame 10)
     * clamps to index 0 — the last available frame — and next() hands it over.
     * The pre-E-11 clamp used count() as the upper bound, put the cursor one
     * slot beyond the only frame, and next() answered null; that overshoot is
     * precisely the off-by-one GifDecoderSeekTest now pins against for real
     * multi-frame GIFs, so this single-frame shape flipped in step.
     */
    public function testStartSecSeeksPastTheAvailableFrames(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0, Mode::HalfBlock, 1.0);

        $this->assertNotNull($decoder->next(), 'a seek beyond the GIF clamps onto its last frame');
        $this->assertNull($decoder->next(), 'and the stream is exhausted right after it');
        $decoder->close();
    }

    // -------------------------------------------------------------------------
    // Iterator exhaustion
    // -------------------------------------------------------------------------

    /**
     * @testdox next() returns null when all frames have been consumed
     */
    public function testOpenAndNextReturnsNullWhenExhausted(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0);

        $first = $decoder->next();
        $this->assertNotNull($first); // At least one frame

        $second = $decoder->next();
        $this->assertNull($second); // No more frames

        $decoder->close();
    }

    /**
     * @testdox next() returns null after close() is called (not an exception)
     */
    public function testCloseThenNextReturnsNull(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0);
        $decoder->next(); // consume the one frame
        $decoder->close();

        // After close(), next() must return null, not throw
        $result = $decoder->next();
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // getIterator
    // -------------------------------------------------------------------------

    /**
     * @testdox getIterator() returns a Generator that yields at least one frame
     */
    public function testGetIterator(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0);

        $iterator = $decoder->getIterator();
        $this->assertInstanceOf(\Generator::class, $iterator);

        // Iterate and count frames using the iterator (NOT $decoder directly —
        // foreach on the Decoder object calls getIterator() again, creating
        // a second generator that starts from frameIndex 0 and racing with
        // the first, resulting in 0 frames counted in the wrong iterator).
        $frameCount = 0;
        foreach ($iterator as $frame) {
            $frameCount++;
            $this->assertInstanceOf(RgbFrame::class, $frame);
        }

        $this->assertGreaterThanOrEqual(1, $frameCount, 'Should yield at least 1 frame');
        $decoder->close();
    }

    // -------------------------------------------------------------------------
    // Pixel content verification
    // -------------------------------------------------------------------------

    /**
     * @testdox toGd() on the decoded RgbFrame produces a correct black pixel image
     */
    public function testDecodedFramePixelContentIsBlack(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0);

        $frame = $decoder->next();
        $this->assertNotNull($frame);

        // Convert RgbFrame back to GD and check the pixel color
        $img = $frame->toGd();
        $rgb = imagecolorat($img, 0, 0);

        // R=0, G=0, B=0 for black pixel
        $this->assertSame(0, ($rgb >> 16) & 0xff, 'R component should be 0');
        $this->assertSame(0, ($rgb >> 8) & 0xff, 'G component should be 0');
        $this->assertSame(0, $rgb & 0xff, 'B component should be 0');

        imagedestroy($img);
        $decoder->close();
    }

    // -------------------------------------------------------------------------
    // F5: GIF decode height honors the rendering mode
    // -------------------------------------------------------------------------

    /**
     * Create a small multi-color truecolor GIF and return its path.
     */
    private function createTempColorGif(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        // Paint a deterministic gradient so the frame is non-trivial.
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $color = imagecolorallocate($img, ($x * 31) % 256, ($y * 47) % 256, (($x + $y) * 17) % 256);
                imagesetpixel($img, $x, $y, $color);
            }
        }

        $path = sys_get_temp_dir() . '/sugar-video-mode-gif-' . uniqid('', true) . '.gif';
        imagegif($img, $path);
        imagedestroy($img);

        $this->tempGifPath = $path;
        return $path;
    }

    /**
     * Regression for F5. GifDecoder must scale the decoded frame height to
     * cellsH * mode->rowsPerCell() so it matches FfmpegDecoder per mode:
     *   - HalfBlock packs 2 source rows per cell → frame->h == cellsH * 2
     *   - Ascii (and the other 1-row modes) → frame->h == cellsH
     *
     * On master GifDecoder always decoded at cellsH (mode ignored), so the
     * HalfBlock frame came out at h == cellsH (6) instead of 12 — the assert
     * `$frame->h === 12` fails.
     *
     * @testdox GifDecoder frame height tracks the mode (HalfBlock 2x, Ascii 1x)
     */
    public function testGifDecodeHeightHonorsMode(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $path = $this->createTempColorGif(8, 6);

        // HalfBlock: 2 source rows per cell → height doubled.
        $hb = new GifDecoder();
        $hb->open($path, 8, 6, 10.0, Mode::HalfBlock);
        $hbFrame = $hb->next();
        $hb->close();

        $this->assertNotNull($hbFrame);
        $this->assertSame(8, $hbFrame->w, 'width must equal cellsW');
        $this->assertSame(6 * 2, $hbFrame->h, 'HalfBlock height must be cellsH * 2 == 12');

        // Ascii: 1 source row per cell → height equals cellsH.
        $ascii = new GifDecoder();
        $ascii->open($path, 8, 6, 10.0, Mode::Ascii);
        $asciiFrame = $ascii->next();
        $ascii->close();

        $this->assertNotNull($asciiFrame);
        $this->assertSame(8, $asciiFrame->w, 'width must equal cellsW');
        $this->assertSame(6, $asciiFrame->h, 'Ascii height must equal cellsH == 6');
    }

    // -------------------------------------------------------------------------
    // Graphics modes decode at the terminal's FULL pixel resolution
    // -------------------------------------------------------------------------

    /**
     * A graphics Mode (Sixel/Kitty/iTerm2) must decode the GIF at
     * cellsW·cellPxW × cellsH·cellPxH so the image protocols get real detail,
     * not one pixel per cell. The decoder is built with explicit cell pixel
     * geometry (new GifDecoder($cellPxW, $cellPxH)).
     *
     * @testdox GifDecoder sizes a graphics-mode frame to cellsW*cellPxW x cellsH*cellPxH
     */
    public function testGifDecodeGraphicsModeUsesFullPixelResolution(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $path = $this->createTempColorGif(8, 6);

        $cellsW = 8;
        $cellsH = 6;
        $cellPxW = 10;
        $cellPxH = 20;

        $decoder = new GifDecoder($cellPxW, $cellPxH);
        $decoder->open($path, $cellsW, $cellsH, 10.0, Mode::Sixel);
        $frame = $decoder->next();
        $decoder->close();

        $this->assertNotNull($frame);
        $this->assertSame($cellsW * $cellPxW, $frame->w, 'graphics width = cellsW * cellPxW');
        $this->assertSame($cellsH * $cellPxH, $frame->h, 'graphics height = cellsH * cellPxH');
    }

    /**
     * REGRESSION. `php examples/play.php synthetic sixel` died outright with
     * `sugar-animation: cell grid product exceeds maximum (100000)`.
     *
     * An ordinary 80×24 terminal at the default 10×20 cell box is 800×480 =
     * 384,000 — six times {@see AnimationDecoder::MAX_CELLS} — and the graphics
     * branch handed that straight to sugar-animation as a CELL grid, which it
     * area-averages one `imagecolorat()` at a time. So every graphics mode
     * was unusable on any real terminal size, not merely slow.
     *
     * The frame still arrives at the full pixel box, because
     * {@see \SugarCraft\Video\Render\GraphicsRenderer} recovers the cell
     * footprint by dividing that size by the cell geometry.
     *
     * @testdox a graphics decode larger than sugar-animation's cap still yields a full-box frame
     */
    public function testGraphicsModeAboveFlipCellCapStillDecodes(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $path = $this->createTempColorGif(120, 60);

        // 80 x 24 cells at the default 10 x 20 cell box.
        $decoder = new GifDecoder();
        $decoder->open($path, 80, 24, 10.0, Mode::Sixel);
        $frame = $decoder->next();
        $decoder->close();

        $this->assertNotNull($frame);
        $this->assertGreaterThan(AnimationDecoder::MAX_CELLS, 800 * 480, 'the box under test must exceed the cap, or this proves nothing');
        $this->assertSame(800, $frame->w);
        $this->assertSame(480, $frame->h);
        // Presented as PNG, like FfmpegDecoder's graphics frames: rebuilding an
        // rgb24 buffer for 384,000 upscaled pixels in PHP is the very cost the
        // bounded decode grid exists to avoid, and nothing on this path reads it.
        $this->assertNotNull($frame->png);
        $this->assertSame('', $frame->bytes);
        $this->assertSame([800, 480], [(int) getimagesizefromstring($frame->png)[0], (int) getimagesizefromstring($frame->png)[1]]);
    }

    /**
     * The cell pixel geometry is configurable per decoder; a different cellPx
     * box yields a proportionally different graphics-mode frame size.
     *
     * @testdox GifDecoder honours a custom cellPx box for graphics modes
     */
    public function testGifDecodeGraphicsModeHonoursCustomCellPx(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $path = $this->createTempColorGif(4, 3);

        $decoder = new GifDecoder(6, 8);
        $decoder->open($path, 4, 3, 10.0, Mode::Kitty);
        $frame = $decoder->next();
        $decoder->close();

        $this->assertNotNull($frame);
        $this->assertSame(4 * 6, $frame->w);
        $this->assertSame(3 * 8, $frame->h);
    }

    /**
     * @testdox GifDecoder reports it does NOT reopen in place (factory-owned)
     */
    public function testGifDecoderIsNotReopenedInPlace(): void
    {
        $this->assertFalse((new GifDecoder())->reopensInPlace());
    }

    /**
     * @testdox a GIF source with headers decodes anyway and logs that they were dropped
     *
     * The brief's "headers ignored, with a documented reason, not silently": a GIF
     * is fetched from disk, never over HTTP, so request headers are meaningless —
     * but we say so in the log rather than swallowing the caller's credentials.
     */
    public function testGifHeadersIgnoredWithLoggedReason(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $path = $this->createTempGif();

        $logged = $this->captureErrorLog(function () use ($path): void {
            $decoder = new GifDecoder();
            $decoder->open($path, 1, 1, 10.0, Mode::HalfBlock, 0.0, ['Authorization' => 'Bearer x']);
            // Decoding still works — headers are dropped, not fatal.
            $this->assertNotNull($decoder->next());
            $decoder->close();
        });

        $this->assertStringContainsString('ignoring 1 HTTP request header', $logged);
    }

    /**
     * Hand-built GIF89a with $frames one-pixel frames. Upstream coalesces
     * identical runs, so this fixture is a shape probe, not a frame-count
     * promise — see the budget helper tests for the arithmetic itself.
     */
    private function rawFrameGifPath(int $frames): string
    {
        $header = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00";
        $frame = "!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x01\x00";
        $bytes = $header . str_repeat($frame, $frames) . ";";

        $path = sys_get_temp_dir() . '/sugarcraft-gif-budget-' . uniqid('', true) . '.gif';
        file_put_contents($path, $bytes);
        $this->tempGifPath = $path;

        return $path;
    }

    /**
     * @testdox M6/M42: the budget helper trips exactly one frame past the re-derived product limit
     */
    public function testTheResidentBudgetHelperTripsAtTheExactProductBoundary(): void
    {
        $probe = new \ReflectionMethod(GifDecoder::class, 'exceedsResidentBudget');
        $probe->setAccessible(true);

        // 99,856 px per frame (316x316 Ascii): the 6th fits the 650,000-px
        // roster, the 7th (698,992) does not (SV-M42 measured derive).
        $this->assertFalse($probe->invoke(null, 5, 99_856));
        $this->assertTrue($probe->invoke(null, 6, 99_856), 'the 7th frame of 99,856 px lands at 698,992 — over');

        // Exact-equality edges stay inside (limit is not "greater than"):
        // held=99 accepts a 100th of 6,500 px at exactly 650,000; held=100
        // would multiply past it.
        $this->assertFalse($probe->invoke(null, 99, 6_500));
        $this->assertTrue($probe->invoke(null, 100, 6_500));
        // And a familiar non-dividing unit still fits with margin / trips:
        $this->assertFalse($probe->invoke(null, 80, 8_000));
        $this->assertTrue($probe->invoke(null, 81, 8_000));
        $this->assertTrue($probe->invoke(null, 0, 650_001), 'a single frame over the whole budget refuses at once');
    }

    /**
     * @testdox M6: hydrate consults the helper before accepting each frame
     */
    public function testTheHydrateLoopEnforcesTheResidentBudget(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__) . '/src/Decode/GifDecoder.php');

        $this->assertStringContainsString(
            'if (self::exceedsResidentBudget(count($frames), $perFrame)) {',
            $source,
            'the consume loop must gate every frame through the helper (M6)',
        );
        $this->assertStringContainsString('\'frame\' => (string) (count($frames) + 1),', $source);
        $this->assertStringNotContainsString(
            'iterator_to_array(AnimationDecoder::decodeBytes',
            $source,
            'the unbounded eager collect must never come back',
        );
    }

    /**
     * @testdox M42: a roster just inside the re-derived budget still hydrates
     *
     * 6 distinct 316×316 frames = 599,136 resident px — the exact fit under
     * 650,000. The old raw-run fixture was a coalescing-shaped probe (it
     * could yield far fewer frames than it declared); the measured derive
     * row pins the trip point on REAL frames instead, via the same
     * Synthetic generator the seek tests trust for frame counts.
     */
    public function testAJustUnderBudgetRosterStillHydrates(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build the animated fixture');
        }

        $this->tempGifPath = Synthetic::generate(
            sys_get_temp_dir() . '/sugar-video-m42-under-' . uniqid('', true) . '.gif',
            316, 316, 6, 4
        );

        $decoder = new GifDecoder();
        $decoder->open($this->tempGifPath, 316, 316, 10.0, Mode::Ascii);
        $this->assertNotNull($decoder->next());
        $decoder->close();
    }

    /**
     * @testdox M42: the 7th 316×316 frame trips the budget in sugar-video's own words
     */
    public function testAJustOverBudgetRosterTripsTheResidentBudget(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build the animated fixture');
        }

        $this->tempGifPath = Synthetic::generate(
            sys_get_temp_dir() . '/sugar-video-m42-over-' . uniqid('', true) . '.gif',
            316, 316, 7, 4
        );

        try {
            (new GifDecoder())->open($this->tempGifPath, 316, 316, 10.0, Mode::Ascii);
            $this->fail('the running budget must refuse the 7th frame');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('GIF decode budget exceeded at frame 7', $e->getMessage());
            $this->assertStringContainsString('650000', $e->getMessage(), 'the refusal names the measured cap');
        }
    }

    /**
     * @testdox M42: the sibling's eager joint gate is translated, never heard raw
     *
     * 21 distinct frames × 99,856 px = 2,096,976 cells — over
     * sugar-animation's own eager MAX_TOTAL_CELLS door, which fires at CALL
     * time before this file's running budget could. Both refusals are the
     * same accounting truth; the user hears one voice (the m13 wording
     * recogniser now covers both upstream sentences).
     */
    public function testTheEagerUpstreamJointGateIsTranslatedToo(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build the animated fixture');
        }

        $this->tempGifPath = Synthetic::generate(
            sys_get_temp_dir() . '/sugar-video-m42-eager-' . uniqid('', true) . '.gif',
            316, 316, 21, 4
        );

        try {
            (new GifDecoder())->open($this->tempGifPath, 316, 316, 10.0, Mode::Ascii);
            $this->fail('the eager joint gate must refuse before any frame lands');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sugar-video: the GIF asks for a cell grid beyond', $e->getMessage());
            $this->assertStringContainsString('total cell budget exceeded', $e->getMessage(), 'the upstream sentence survives as the technical detail');
        }
    }

    /**
     * @testdox m2: the hydrate call sites pass exactly the declared five arguments
     */
    public function testTheHydrateCallSitesMatchTheDeclaredArity(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__) . '/src/Decode/GifDecoder.php');

        $this->assertSame(
            2,
            substr_count($source, '$this->hydrate($cellsW, $cellsH, $fps, $mode, $startSec);'),
            'both rebuild paths (open and same-source reopen) call the five-parameter hydrate',
        );
        $this->assertStringNotContainsString(
            '$this->hydrate($cellsW, $cellsH, $fps, $mode, $startSec, $headers',
            $source,
            'no call site may keep feeding a sixth argument into a five-parameter method (m2)',
        );
    }
    /**
     * @testdox m13/m14: the upstream grid refusal is translated and the gd-less hint exists
     */
    public function testTheUpstreamGridRefusalIsTranslated(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Decode/GifDecoder.php');
        $this->assertStringContainsString("'cell grid product exceeds maximum'", $src, 'the upstream phrase must be recognized');
        $this->assertStringContainsString("'decoder.gif_grid'", $src, 'and re-thrown as sugar-video advice');
        $this->assertStringContainsString('self::textSampled()', $src, 'the gd-less hint has a call site');
        if (function_exists('imagecreatetruecolor')) {
            $this->assertNull(GifDecoder::textSampled(), 'a GD-capable host never hints');
        }
    }

    public function testTheUnreadableSourceRefusalNeverEchoesUrlCredentials(): void
    {
        try {
            (new GifDecoder())->open('gif://user:pass@example.invalid/missing.gif', 8, 4, 10.0);
            self::fail('the refusal was expected');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('user:pass', $e->getMessage());
            self::assertStringContainsString('gif://***@example.invalid/missing.gif', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // M40 — the converted-frame cache
    // -------------------------------------------------------------------------

    /**
     * @testdox M40: a same-shape re-pass converts nothing and serves the cached instances
     *
     * The convert-once contract: once every frame of a capture has been
     * walked, close()+reopen() of the SAME source at the SAME shape must
     * replay from the emit cache — the per-pixel PHP walk runs exactly once
     * per (frame, shape), which is what makes the loop restart cheap.
     */
    public function testTheConvertedFramesAreServedFromCacheOnASecondPass(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build the animated fixture');
        }

        $this->tempGifPath = Synthetic::generate(
            sys_get_temp_dir() . '/sugar-video-m40-' . uniqid('', true) . '.gif',
            12, 8, 6, 4
        );

        $decoder = new GifDecoder();
        $decoder->open($this->tempGifPath, 12, 4, 10.0, Mode::HalfBlock);

        $first = $this->drain($decoder);
        $this->assertGreaterThanOrEqual(2, count($first), 'the fixture must animate, or the spy proves nothing');
        $this->assertCount($decoder->conversionCount(), $first, 'first pass: one conversion per served frame');

        $decoder->close();
        $decoder->reopen($this->tempGifPath, 12, 4, 10.0, Mode::HalfBlock);

        $second = $this->drain($decoder);
        $this->assertCount(count($first), $second);
        $this->assertSame($decoder->conversionCount(), count($first), 'second pass must not re-convert a single frame');
        foreach ($first as $i => $frame) {
            $this->assertSame($frame, $second[$i], "frame $i replays as the cached instance");
        }

        $decoder->close();
    }

    /**
     * @testdox M40: re-hydrating at a different shape drops the index-keyed cache
     *
     * A frame index identifies a frame only WITHIN one decode shape, so a
     * resized re-hydrate of the same bytes must re-derive (the stale cache
     * would hand back the old grid's pixels at the wrong size) — the stamp
     * is the whole map's guard, not a per-entry optimisation.
     */
    public function testAReShapeHydrateDropsTheIndexKeyedCache(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build the animated fixture');
        }

        $this->tempGifPath = Synthetic::generate(
            sys_get_temp_dir() . '/sugar-video-m40-shape-' . uniqid('', true) . '.gif',
            12, 8, 6, 4
        );

        $decoder = new GifDecoder();
        $decoder->open($this->tempGifPath, 12, 4, 10.0, Mode::HalfBlock);
        $first = $this->drain($decoder);
        $walked = count($first);
        $this->assertSame(8, $first[0]->h, 'cellsH 4 at HalfBlock decodes 8 rows');

        $decoder->close();
        $decoder->reopen($this->tempGifPath, 12, 8, 10.0, Mode::HalfBlock);
        $second = $this->drain($decoder);

        $this->assertSame(16, $second[0]->h, 'the taller pane decodes a taller grid — old pixels must not serve');
        $this->assertSame($walked * 2, $decoder->conversionCount(), 'the shape change invalidated every entry');

        $decoder->close();
    }

    /**
     * @testdox M40: the byte ceiling evicts oldest-first and never the newest frame
     */
    public function testTheEmitCacheHonoursItsByteCeiling(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build the animated fixture');
        }

        $this->tempGifPath = Synthetic::generate(
            sys_get_temp_dir() . '/sugar-video-m40-cap-' . uniqid('', true) . '.gif',
            12, 8, 6, 4
        );

        $probe = new GifDecoder();
        $probe->open($this->tempGifPath, 12, 4, 10.0, Mode::HalfBlock);
        $walked = $this->drain($probe);
        $frameBytes = strlen($walked[0]->bytes);
        $count = count($walked);
        $probe->close();

        // Just-under arm: the full roster fits, so a re-pass serves from cache.
        GifDecoder::setEmitCacheCeilingForTesting($frameBytes * $count);
        $fitting = new GifDecoder();
        $fitting->open($this->tempGifPath, 12, 4, 10.0, Mode::HalfBlock);
        $this->drain($fitting);
        $this->assertSame($frameBytes * $count, $fitting->emitCacheBytes());
        $fitting->reopen($this->tempGifPath, 12, 4, 10.0, Mode::HalfBlock);
        $this->drain($fitting);
        $this->assertSame($count, $fitting->conversionCount(), 'the ceiling-exact roster still serves its second pass');

        // Starved arm: one frame's worth of room can hold only the newest —
        // every older entry is forfeit, so re-passes re-convert (the walk
        // stays correct; the cache just declines to remember).
        GifDecoder::setEmitCacheCeilingForTesting(1);
        $starved = new GifDecoder();
        $starved->open($this->tempGifPath, 12, 4, 10.0, Mode::HalfBlock);
        $this->drain($starved);
        $this->assertSame($frameBytes, $starved->emitCacheBytes(), 'exactly the newest frame stays resident');
        $starved->reopen($this->tempGifPath, 12, 4, 10.0, Mode::HalfBlock);
        $again = $this->drain($starved);
        $this->assertSame($count * 2, $starved->conversionCount(), 'the starved cache holds only the newest frame, which the next insert evicts before a re-pass can ever reach it');
        $this->assertSame($walked[0]->bytes, $again[0]->bytes, 'eviction is a memory decision, never a correctness one');

        $fitting->close();
        $starved->close();
    }

    /**
     * @testdox M40: the documented ceiling constant is pinned against silent drift
     */
    public function testTheEmitCacheCeilingIsTheDocumentedContract(): void
    {
        $this->assertSame(24_000_000, GifDecoder::MAX_EMIT_CACHE_BYTES);
    }

    /**
     * @testdox M40: the row-block upscale paints real pixels into the presentation box
     *
     * The run-length import claims byte-identity with the old per-pixel
     * imagesetpixel walk (proven against the graphics pins pre-change). This
     * is the committed floor of that claim: the PNG that arrives is the full
     * cell box, decodes, and its opposite corners differ — an all-black
     * canvas (a fill that never ran) or a one-pixel smear could not pass.
     */
    public function testTheRunLengthUpscalePaintsRealPixels(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build the animated fixture');
        }

        $this->tempGifPath = $this->createTempColorGif(8, 6);

        $decoder = new GifDecoder(10, 20);
        $decoder->open($this->tempGifPath, 8, 6, 10.0, Mode::Sixel);
        $frame = $decoder->next();
        $decoder->close();

        $this->assertNotNull($frame->png);
        $img = imagecreatefromstring($frame->png);
        $this->assertNotFalse($img);
        $this->assertSame(80, imagesx($img));
        $this->assertSame(120, imagesy($img));

        $corner = imagecolorat($img, 0, 0);
        $opposite = imagecolorat($img, 79, 119);
        $this->assertNotSame($corner, $opposite, 'a gradient must survive the resample');
        imagedestroy($img);
    }

    /**
     * @testdox M40: next() consults the cache before converting, and conversion is counted once
     *
     * Structural tripwire (M16 idiom): the whole row is “serve cached frames”
     * — a refactor that quietly re-converts on every next() would keep these
     * behaviour tests green by accident if it were also cache-write-omitting,
     * so the wiring itself is grepped: one cache read, one counter bump, and
     * the per-pixel imagesetpixel loop is gone from the upscale.
     */
    public function testTheCacheWiringIsSingleSourcedInSource(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__) . '/src/Decode/GifDecoder.php');

        $this->assertSame(1, substr_count($source, '$this->emitCache[$index] ?? null'));
        $this->assertSame(1, substr_count($source, '$this->conversions++;'));
        $this->assertSame(0, substr_count($source, 'imagesetpixel('), 'the run-block import retired the per-pixel GD call');
    }


    /**
     * Walk a decoder to exhaustion (M40 fixtures). GifDecoder satisfies the
     * Decoder contract's getIterator() without being Traversable, so drains
     * go through next() — the exact path the emit cache guards.
     */
    private function drain(Decoder $decoder): array
    {
        $frames = [];
        while (($frame = $decoder->next()) !== null) {
            $frames[] = $frame;
        }
        return $frames;
    }

}
