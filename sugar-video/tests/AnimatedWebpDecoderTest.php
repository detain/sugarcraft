<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Decode\AnimatedWebpDecoder;
use SugarCraft\Video\Lang;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Tests\Concerns\CapturesErrorLog;
use SugarCraft\Video\Tests\Support\AnimatedWebpBytes;

/**
 * E-05.2: animated WebP decoder — container truth, compositing, bounds.
 *
 * Fixtures are RFC-shaped containers assembled by AnimatedWebpBytes around
 * real ffmpeg/libwebp-encoded still bitstreams (hex-embedded; provenance in
 * that class' docblock).
 */
final class AnimatedWebpDecoderTest extends TestCase
{
    use CapturesErrorLog;

    /** @var list<string> temp dirs to prune */
    private array $dirs = [];

    protected function tearDown(): void
    {
        AnimatedWebpDecoder::setWebpCapableForTesting(null);
        foreach ($this->dirs as $dir) {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($dir . '/' . $entry);
                }
            }
            @rmdir($dir);
        }
        $this->dirs = [];
        parent::tearDown();
    }

    private function file(string $bytes, string $name): string
    {
        $path = AnimatedWebpBytes::write($bytes, $name);
        $this->dirs[] = dirname($path);

        return $path;
    }

    /** Three-frame story: full red, partial 16x16 red that DISPOSES, alpha blue over the persisted red at (16,16). */
    private function storyContainer(): string
    {
        return AnimatedWebpBytes::animContainer(32, 32, [
            ['still' => hex2bin(AnimatedWebpBytes::RED_32), 'duration' => 100, 'blend' => true],
            ['still' => hex2bin(AnimatedWebpBytes::RED_16), 'duration' => 100, 'dispose' => true, 'blend' => true],
            ['still' => hex2bin(AnimatedWebpBytes::BLUE_ALPHA_16), 'x' => 16, 'y' => 16, 'duration' => 100],
        ], loop: 3);
    }

    public function testIsAnimatedSniffsTheContainerHeadersOnly(): void
    {
        $anim = $this->file($this->storyContainer(), 'anim.webp');
        self::assertTrue(AnimatedWebpDecoder::isAnimated($anim));

        // A still (VP8X present but NO animation bit) and a plain lossy file
        // both answer false — the sniff must not decode anything.
        self::assertFalse(AnimatedWebpDecoder::isAnimated($this->file(hex2bin(AnimatedWebpBytes::BLUE_ALPHA_16), 'still.webp')));
        self::assertFalse(AnimatedWebpDecoder::isAnimated($this->file(hex2bin(AnimatedWebpBytes::RED_32), 'lossy.webp')));
        self::assertFalse(AnimatedWebpDecoder::isAnimated($this->file('not a webp at all', 'junk.webp')));
        self::assertFalse(AnimatedWebpDecoder::isAnimated($this->file('', 'empty.webp')));
        self::assertFalse(AnimatedWebpDecoder::isAnimated('/does/not/exist.webp'));
    }

    public function testVp8xAnimationBitWithoutAnyAnmfStillSniffsAnimatedButRefusesOpen(): void
    {
        // The sniff is header-only (sugar-image E-0.1 detector parity: it
        // trusts the flag bit). Decoding without a single ANMF frame is the
        // loud refusal — never a silently empty playback.
        $bytes = AnimatedWebpBytes::container([
            ['fourcc' => 'VP8X', 'payload' => chr(0x02) . "\x00\x00\x00" . "\x1f\x00\x00" . "\x1f\x00\x00"],
        ]);
        $path = $this->file($bytes, 'flagonly.webp');
        self::assertTrue(AnimatedWebpDecoder::isAnimated($path));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no ANMF frames');
        (new AnimatedWebpDecoder())->open($path, 8, 4, 10.0);
    }

    public function testDecodesFrameCountDelaysAndLoopCount(): void
    {
        $d = new AnimatedWebpDecoder();
        $d->open($this->file($this->storyContainer(), 'meta.webp'), 8, 4, 10.0);

        self::assertSame([100, 100, 100], $d->frameDelaysMs());
        self::assertSame(3, $d->loopCount());
        self::assertSame(3, iterator_count($d->getIterator()));
        self::assertNull($d->next(), 'the sequence is terminal — the Player loop re-opens via the factory');
    }

    public function testLoopCountZeroIsForeverAndIsExposedVerbatim(): void
    {
        $bytes = AnimatedWebpBytes::animContainer(32, 32, [
            ['still' => hex2bin(AnimatedWebpBytes::RED_32), 'duration' => 42, 'blend' => true],
        ], loop: 0);
        $d = new AnimatedWebpDecoder();
        $d->open($this->file($bytes, 'loop0.webp'), 8, 4, 10.0);
        self::assertSame(0, $d->loopCount());
        self::assertSame([42], $d->frameDelaysMs());
    }

    public function testTextModeFramesFillTheCellGridBoxWithOpaqueRgb24(): void
    {
        $d = new AnimatedWebpDecoder();
        // HalfBlock packing (null mode = 1 col x 2 rows per cell): 8x4 cells -> 8x8 px box.
        $d->open($this->file($this->storyContainer(), 'text.webp'), 8, 4, 10.0);
        $f0 = $d->next();

        self::assertSame(8, $f0->w);
        self::assertSame(8, $f0->h);
        self::assertNull($f0->png);
        self::assertSame(8 * 8 * 3, strlen($f0->bytes));

        // The 32x32 red canvas letterboxes 1:1 into the 8x8 box; the lossy
        // encode's measured red is ~0xFE0001 — assert on the red channel.
        $gd = $f0->toGd();
        self::assertGreaterThan(200, (imagecolorat($gd, 4, 4) >> 16) & 0xFF);
    }

    public function testGraphicsModeFramesAreAlphaPngsAtTheCellPixelBox(): void
    {
        $d = new AnimatedWebpDecoder(4, 4);
        // Sixel is a graphics mode: 8x8 cells x 4x4 px = 32x32 box.
        $d->open($this->file($this->storyContainer(), 'gfx.webp'), 8, 8, 10.0, Mode::Sixel);
        $d->next();
        $d->next();
        $f3 = $d->next();

        self::assertNotNull($f3->png);
        self::assertSame('', $f3->bytes);
        $g = imagecreatefromstring($f3->png);
        self::assertSame(32, imagesx($g));
        self::assertSame(32, imagesy($g));
    }

    public function testAlphaBlendingDisposalAndBackdropFollowTheWebpSpec(): void
    {
        $d = new AnimatedWebpDecoder(4, 4);
        $d->open($this->file($this->storyContainer(), 'comp.webp'), 8, 8, 10.0, Mode::Sixel);
        $d->next();
        $d->next();
        $g = imagecreatefromstring($d->next()->png);

        // (1) Disposal is to the BACKDROP, not to the previous pixels: the
        // 16x16 dispose rect reverts to transparent even though a red frame
        // painted under it first.
        self::assertSame(127, (imagecolorat($g, 4, 4) >> 24) & 0x7F, 'inside the disposed rect: transparent backdrop');

        // (2) Outside the dispose rect the first frame persists: red at the
        // far corner untouched by the third frame's (16,16)+16x16 box.
        $persist = imagecolorat($g, 30, 2);
        self::assertSame(0, ($persist >> 24) & 0x7F, 'persisted red stays opaque');
        self::assertGreaterThan(200, ($persist >> 16) & 0xFF);

        // (3) The third frame has no blend bit: alpha is COMPOSITED against
        // the persisted red, so it is opaque and neither pure blue nor pure
        // red — a 50% mix.
        $mix = imagecolorat($g, 24, 24);
        self::assertSame(0, ($mix >> 24) & 0x7F, 'blend over opaque red stays opaque');
        $r = ($mix >> 16) & 0xFF;
        $b = $mix & 0xFF;
        self::assertGreaterThan(60, $r, 'red contributes');
        self::assertGreaterThan(60, $b, 'blue contributes');
        self::assertLessThan(200, $r, 'not pure red (the blue frame did paint here)');
        self::assertLessThan(200, $b, 'not pure blue (raw copy would be ~255)');
    }

    public function testNoBlendFramesCopyRawIncludingTheirAlpha(): void
    {
        // Same partial alpha frame, but with the no-blend bit set: its ~50%
        // alpha lands on the canvas UNCOMPOSITED (backdrop was transparent,
        // so the copy must NOT become opaque).
        $bytes = AnimatedWebpBytes::animContainer(32, 32, [
            ['still' => hex2bin(AnimatedWebpBytes::BLUE_ALPHA_16), 'x' => 16, 'y' => 16, 'blend' => true],
        ]);
        $d = new AnimatedWebpDecoder(4, 4);
        $d->open($this->file($bytes, 'rawcopy.webp'), 8, 8, 10.0, Mode::Sixel);
        $g = imagecreatefromstring($d->next()->png);

        $raw = imagecolorat($g, 24, 24);
        $a = ($raw >> 24) & 0x7F;
        self::assertGreaterThan(20, $a, 'raw-copied ~50% alpha stays semi-transparent (measured 0x40-ish)');
        self::assertLessThan(110, $a);
    }

    public function testStartSecSeeksByCumulativeContainerDurations(): void
    {
        $bytes = AnimatedWebpBytes::animContainer(32, 32, [
            ['still' => hex2bin(AnimatedWebpBytes::RED_32), 'duration' => 100, 'blend' => true],
            ['still' => hex2bin(AnimatedWebpBytes::BLUE_32), 'duration' => 300, 'blend' => true],
        ]);
        $path = $this->file($bytes, 'seek.webp');

        $d = new AnimatedWebpDecoder();
        $d->open($path, 8, 4, 10.0, null, 0.15); // 150 ms lands inside frame 2
        $hit = imagecolorat($d->next()->toGd(), 4, 4);
        self::assertLessThan(100, ($hit >> 16) & 0xFF, 'seek past 100 ms shows the blue frame');

        // A seek past the end lands on the LAST frame (GifDecoder E-11
        // polarity), not back at the start.
        $d->open($path, 8, 4, 10.0, null, 99.0);
        $end = imagecolorat($d->next()->toGd(), 4, 4);
        self::assertLessThan(100, ($end >> 16) & 0xFF);
        self::assertNull($d->next());
    }

    public function testTheLossyGeometryFieldIsIgnoredInFavorOfTheBitstream(): void
    {
        // The ANMF width/height field cannot spell even dimensions exactly
        // (half-unit encoding) — garbage there must not move the painted box:
        // the frame decodes at its bitstream size and lands at x=0 y=0 fully.
        $good = AnimatedWebpBytes::animContainer(32, 32, [
            ['still' => hex2bin(AnimatedWebpBytes::RED_32), 'duration' => 100, 'blend' => true],
        ]);
        $off = strpos($good, 'ANMF') + 8 + 6; // the width-1 field
        $garbage = substr($good, 0, $off) . "\xff\xff\xff\xff\xff\xff" . substr($good, $off + 6);

        $d = new AnimatedWebpDecoder();
        $d->open($this->file($garbage, 'garb.webp'), 8, 4, 10.0);
        self::assertSame(1, iterator_count($d->getIterator()));
    }

    public function testMissingGdWebpCapabilityRefusesLoudlyNotDegraded(): void
    {
        AnimatedWebpDecoder::setWebpCapableForTesting(false);
        $d = new AnimatedWebpDecoder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(Lang::t('decoder.webp_gd_missing'));
        $d->open($this->file($this->storyContainer(), 'nogd.webp'), 8, 4, 10.0);
    }

    public function testFrameCanvasAndTotalPixelCeilingsFailClosedBeforeCompositing(): void
    {
        // Frame count ceiling: one frame over MAX_FRAMES, refused on the
        // COUNT before decoding a single still (reusing one tiny still keeps
        // the fixture bytes small — the rung must trip regardless).
        $frames = array_fill(0, AnimatedWebpDecoder::MAX_FRAMES + 1, [
            'still' => hex2bin(AnimatedWebpBytes::RED_32), 'blend' => true,
        ]);
        $d = new AnimatedWebpDecoder();
        try {
            $d->open($this->file(AnimatedWebpBytes::animContainer(32, 32, $frames), 'many.webp'), 8, 4, 10.0);
            self::fail('frame ceiling did not trip');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('frame ceiling', $e->getMessage());
        }

        // Canvas area ceiling: 2317x2317 > MAX_CANVAS_PIXELS.
        $one = [['still' => hex2bin(AnimatedWebpBytes::RED_32), 'blend' => true]];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('canvas/pixel ceilings');
        $d->open($this->file(AnimatedWebpBytes::animContainer(2317, 2317, $one), 'huge.webp'), 8, 4, 10.0);
    }

    public function testTotalPixelsCeilingTripsWithinTheFrameCeiling(): void
    {
        // 2048x2048 canvas = exactly MAX_CANVAS_PIXELS (under it); 9 frames
        // blow MAX_TOTAL_PIXELS while the 4096 frame count stays legal —
        // proves the total budget is an independent rung, not the frame cap.
        $one = ['still' => hex2bin(AnimatedWebpBytes::RED_32), 'blend' => true];
        $d = new AnimatedWebpDecoder();
        try {
            $d->open($this->file(AnimatedWebpBytes::animContainer(2048, 2048, array_fill(0, 9, $one)), 'tot.webp'), 8, 4, 10.0);
            self::fail('total pixel ceiling did not trip');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('canvas/pixel ceilings', $e->getMessage());
        }
    }

    public function testTruncatedContainerFailsClosed(): void
    {
        $bytes = $this->storyContainer();
        $cut = (int) (strlen($bytes) * 0.75); // lands inside a frame payload
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('truncated');
        (new AnimatedWebpDecoder())->open($this->file(substr($bytes, 0, $cut), 'trunc.webp'), 8, 4, 10.0);
    }

    public function testLocalSourceHeadersAreLoggedThenDroppedLikeEveryOtherDecoder(): void
    {
        $path = $this->file($this->storyContainer(), 'hdr.webp');
        $d = new AnimatedWebpDecoder();
        $log = $this->captureErrorLog(static fn () => $d->open($path, 8, 4, 10.0, null, 0.0, ['Authorization' => 'Bearer x']));
        self::assertStringContainsString('ignoring 1 HTTP request header', $log);
        self::assertNotNull($d->next());
    }

    public function testReopenResetsCursorAndCloseFreesFrames(): void
    {
        $path = $this->file($this->storyContainer(), 'cycle.webp');
        $d = new AnimatedWebpDecoder();
        $d->open($path, 8, 4, 10.0);
        self::assertNotNull($d->next());
        self::assertNotNull($d->next());
        self::assertNotNull($d->next());
        self::assertNull($d->next());

        $d->reopen($path, 8, 4, 10.0);
        self::assertGreaterThan(200, ((imagecolorat($d->next()->toGd(), 4, 4)) >> 16) & 0xFF, 'reopen replays from frame 1');

        $d->close();
        self::assertSame([], $d->frameDelaysMs());
        self::assertNull($d->next(), 'close() is terminal until reopened');
        $d->close(); // idempotent
    }

    public function testReopensInPlaceIsFalseBecauseGeometryRebuildIsAFullOpen(): void
    {
        self::assertFalse((new AnimatedWebpDecoder())->reopensInPlace());
    }
}
