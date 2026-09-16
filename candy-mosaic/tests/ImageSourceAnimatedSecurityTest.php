<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flip\Decoder as FlipDecoder;
use SugarCraft\Mosaic\ApngDecoder;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Tests\Support\AnimatedImageFixtures as F;

/**
 * Round-2 review regressions for the animated-file ingress (PR #1442). These pin the
 * bypasses the adversarial re-attack demonstrated survived round 1: candy-flip's
 * mis-skipped header walk buying a multi-hundred-MB decode before any reconcile could
 * stop it (NEW-1), a FIFO/device swapped into the read path blocking the caller
 * (NEW-5), absurd geometry reaching `str_repeat()` as a raw `TypeError` once the pixel
 * budget is disabled (NEW-6), and a non-PNG stream decoding past a missing signature
 * check (NEW-7).
 *
 * @covers \SugarCraft\Mosaic\ImageSource
 * @covers \SugarCraft\Mosaic\ApngDecoder
 */
final class ImageSourceAnimatedSecurityTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        $this->temp = [];
    }

    private function write(string $name, string $bytes): string
    {
        $path = sys_get_temp_dir() . '/mosaic-sec-' . $name;
        file_put_contents($path, $bytes);
        $this->temp[] = $path;

        return $path;
    }

    /**
     * @return \Closure(string):int
     */
    private function gifCountProbe(string $method): \Closure
    {
        $rm = new \ReflectionMethod(ImageSource::class, $method);
        $rm->setAccessible(true);

        return static fn(string $bytes): int => $rm->invoke(null, $bytes);
    }

    /**
     * The pre-decode frame-count oracle that closes the NEW-1 bomb is only safe if it
     * is EXACTLY what candy-flip will return. This pins that invariant against the real
     * sibling decoder on well-formed GIFs: flip's own walk mis-skips image data, yet
     * for a clean stream it re-synchronises on the next descriptor, so honest count,
     * predicted count, and flip's actual return must all agree.
     */
    public function testFlipWalkPredictorEqualsFlipActualReturnOnCleanGifs(): void
    {
        $predicted = $this->gifCountProbe('countGifFramesAsFlipWalks');
        $declared  = $this->gifCountProbe('countGifFrames');
        $colors    = [[0, 0, 0], [255, 0, 0], [0, 255, 0]];

        foreach ([2, 4] as $n) {
            $frames = [];
            for ($i = 0; $i < $n; $i++) {
                $frames[] = ['pixels' => array_fill(0, 4, ($i % 3) + 1), 'delay' => 10 + $i];
            }
            $gif = F::gif(2, 2, $colors, $frames);
            $path = $this->write("clean{$n}.gif", $gif);

            $actual = count(FlipDecoder::decode($path, 2, 2));

            self::assertSame($n, $declared($gif), "honest walk of a {$n}-frame GIF");
            self::assertSame($actual, $predicted($gif), "flip-walk prediction must equal flip's real return ({$n}f)");
        }
    }

    /**
     * NEW-1 (High): flip's desynced walk materialises far more frames than the honest
     * container count before the post-decode reconcile can fire — a few KiB buying a
     * multi-second, multi-hundred-MB decode. fromAnimatedFile must now predict flip's
     * return from the bytes ALONE and refuse BEFORE ever running it.
     *
     * An 8-frame GIF is the honest-but-desyncing case: the container carries 8 image
     * descriptors, flip's mis-skipped walk re-synchronises on only 4. The pre-decode
     * guard sees declared(8) != predicted(4) and refuses; the sibling decoder is never
     * handed the file.
     */
    public function testGifDesyncRefusedBeforeDecode(): void
    {
        $colors = [[0, 0, 0], [255, 0, 0], [0, 255, 0]];
        $frames = [];
        for ($i = 0; $i < 8; $i++) {
            $frames[] = ['pixels' => array_fill(0, 4, ($i % 3) + 1), 'delay' => 10 + $i];
        }
        $gif = F::gif(2, 2, $colors, $frames);

        $predicted = ($this->gifCountProbe('countGifFramesAsFlipWalks'))($gif);
        self::assertLessThan(8, $predicted, "flip's mis-skipped walk must find fewer than the honest 8 frames (got {$predicted})");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/carries 8 frames but the frame decoder returned \d+/');
        ImageSource::fromAnimatedFile($this->write('desync8.gif', $gif));
    }

    /**
     * NEW-5: the read used `is_file()` → `filesize()` → `file_get_contents()` on three
     * separate calls, so a path swapped to a FIFO between check and read blocked the
     * caller forever. The replacement stats the SAME descriptor it reads and demands a
     * regular file. /dev/null is a character device on every supported platform, so it
     * exercises the non-regular rejection without a race to stage.
     */
    public function testNonRegularFileIsRefusedNotHung(): void
    {
        if (!is_readable('/dev/null')) {
            $this->markTestSkipped('/dev/null unavailable on this platform');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Cannot read file/');
        ImageSource::fromAnimatedFile('/dev/null');
    }

    /**
     * NEW-6 / round-1 L3: with the pixel budget disabled (`$maxPixels <= 0`) two huge
     * uint32 IHDR dimensions used to multiply past PHP_INT_MAX and reach str_repeat()
     * as a raw TypeError. A hard format ceiling now rejects absurd geometry with a
     * typed parse error before any allocation.
     */
    public function testHugeApngDimensionsRefusedWithBudgetDisabled(): void
    {
        $png = F::png([
            ['type' => 'IHDR', 'data' => F::ihdr(20000, 20000)],
            ['type' => 'IEND', 'data' => ''],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/outside the supported 1\.\.16384 range/');
        ApngDecoder::decode($png, 0);
    }

    /**
     * NEW-7: decode() is public (@internal is a comment, not an access boundary) and
     * was reachable without a signature check, so an arbitrary 8-byte prefix decoded to
     * a no_ihdr/bad_chunk_crc that blamed the wrong thing.
     */
    public function testNonPngStreamRefusedBySignature(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/signature/i');
        ApngDecoder::decode('GIF89a not-a-png-at-all', 1000);
    }
}
