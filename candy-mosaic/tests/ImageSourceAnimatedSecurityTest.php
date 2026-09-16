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
     * Sanity anchor for the conservative cost bound: on a WELL-FORMED GIF the honest
     * container count, the flip-walk predictor, and candy-flip's actual return all
     * agree. (The predictor is a conservative upper bound used to size the aggregate
     * budget — it is NOT required to equal flip exactly on adversarial streams, which
     * is why the authoritative refusal is the post-decode reconcile, not this clone.)
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
     * round-1 C2-GIF / R3-3: candy-flip's mis-skipped walk silently returns FEWER
     * frames than a valid multi-frame GIF carries (its documented sibling bug — a real
     * 10-frame ffmpeg GIF decodes to ~2). fromAnimatedFile must NEVER emit that
     * silently-short animation; the authoritative post-decode reconcile compares the
     * honest container count against flip's actual return and refuses loudly.
     *
     * An 8-frame GIF is the honest-but-desyncing case: the container carries 8 image
     * descriptors, flip re-synchronises on only a few. The container count (8) and the
     * decoded count disagree, so the animation is refused rather than truncated. (The
     * separate cheap cost bound in the aggregate guard stops the ADVERSARIAL over-count
     * runaway before decode; see testGifAggregateCeilingIsCheckedBeforeDecode.)
     */
    public function testGifDesyncRefusedNotSilentlyTruncated(): void
    {
        $colors = [[0, 0, 0], [255, 0, 0], [0, 255, 0]];
        $frames = [];
        for ($i = 0; $i < 8; $i++) {
            $frames[] = ['pixels' => array_fill(0, 4, ($i % 3) + 1), 'delay' => 10 + $i];
        }
        $gif = F::gif(2, 2, $colors, $frames);

        $predicted = ($this->gifCountProbe('countGifFramesAsFlipWalks'))($gif);
        self::assertLessThan(8, $predicted, "flip's mis-skipped walk finds fewer than the honest 8 frames (got {$predicted})");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/carries 8 frames but the frame decoder returned \d+/');
        ImageSource::fromAnimatedFile($this->write('desync8.gif', $gif));
    }

    /**
     * R3-1: candy-flip's walk reads the descriptor's left/top/width/height (bytes
     * i+1..i+8) UNGUARDED but the packed byte (i+9) with `?? ''`, so it RECORDS a
     * descriptor truncated to exactly nine bytes before terminating. The clone must
     * count that frame too — an earlier revision gated on i+9 and under-counted, which
     * is precisely the direction that could let a real over-production slip past the
     * cost bound. A GIF ending in one full descriptor plus a trailing nine-byte
     * descriptor must therefore predict it as a frame. Isolated: a header followed by a
     * single nine-byte truncated descriptor counts as 1 (matching flip), not 0.
     */
    public function testPredictorCountsTruncatedDescriptorLikeFlip(): void
    {
        // Header (6) + logical screen descriptor (7, no global colour table) = 13 bytes.
        // Then one image descriptor truncated to nine bytes: 0x2C + 8 geometry bytes,
        // with NO packed byte present (the stream ends at i+8).
        $bytes = 'GIF89a'
            . pack('v', 4) . pack('v', 4) . chr(0x00) . chr(0x00) . chr(0x00)
            . chr(0x2C) . pack('v', 0) . pack('v', 0) . pack('v', 1) . pack('v', 1);
        self::assertSame(22, strlen($bytes)); // i=13, descriptor spans 13..21 (nine bytes)

        $predicted = ($this->gifCountProbe('countGifFramesAsFlipWalks'))($bytes);
        self::assertSame(1, $predicted, 'flip records the nine-byte truncated descriptor; the bound must not under-shoot it');
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
     * R3-2: the NEW-5 fix opened the file with @fopen() BEFORE its S_IFREG check, which
     * re-introduced the exact hazard it closed — opening a FIFO that no writer holds
     * blocks forever, so a plain fifo handed to fromAnimatedFile() HUNG instead of
     * refusing in microseconds (round-1's is_file() rejected it without ever opening).
     * The replacement stats the PATH first (stat() never opens, so it cannot block) and
     * only opens a confirmed regular file.
     *
     * If this ever regresses to open-before-check it will block the runner and fail via
     * CI's job timeout — the same convention the pty suites use for unbounded loops.
     */
    public function testFifoIsRefusedWithoutBlocking(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('posix_mkfifo unavailable — cannot stage a FIFO');
        }

        $fifo = sys_get_temp_dir() . '/mosaic-r32-' . bin2hex(random_bytes(6)) . '.fifo';
        if (!@posix_mkfifo($fifo, 0600)) {
            $this->markTestSkipped('posix_mkfifo failed on this platform');
        }

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/Cannot read file/');
            ImageSource::fromAnimatedFile($fifo);
        } finally {
            @unlink($fifo);
        }
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
