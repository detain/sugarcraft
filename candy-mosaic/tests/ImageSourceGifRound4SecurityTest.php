<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Tests\Support\AnimatedImageFixtures as F;

/**
 * Round-4 adversarial-security regressions for the animated-GIF ingress (PR #1442),
 * pinned with the reviewer's OWN attacker artifacts (committed under tests/fixtures/,
 * md5-identical to /tmp/opencode/r4). Each test is the GREEN half of a mutation proof:
 *
 *  - CRITICAL-1 (`resync60.gif`, 1.4 KB → previously ACCEPTED at 1.5 GB / 20 s): the
 *    aggregate cell ceiling was recalibrated from 6 M to 400 k, so a legitimately
 *    structured 60-frame 316×316 GIF (5.99 M cells) is now refused BEFORE flip decodes.
 *  - CRITICAL-2 (`phantom.gif`/`delayforge.gif`, 107 B → previously ACCEPTED with a
 *    silently wrong / forged-delay frame because the reconcile only compared counts):
 *    the loader now reconciles candy-flip's descriptor OFFSET LIST against the honest
 *    container walk, so a phantom descriptor that preserves the frame COUNT while
 *    shifting a POSITION is refused.
 *  - HIGH (TOCTOU): the GIF logical screen and flip's pixel payload are taken from the
 *    bytes read through the single bounded descriptor, never by re-opening `$path`, so
 *    a post-read path swap cannot be re-followed.
 *
 * @covers \SugarCraft\Mosaic\ImageSource
 */
final class ImageSourceGifRound4SecurityTest extends TestCase
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

    private function fixture(string $name): string
    {
        $path = __DIR__ . '/fixtures/' . $name;
        $this->assertFileExists($path, "committed regression fixture {$name} is missing");

        return $path;
    }

    private function writeTemp(string $bytes): string
    {
        $path = sys_get_temp_dir() . '/mosaic-r4-' . bin2hex(random_bytes(6)) . '.bin';
        file_put_contents($path, $bytes);
        $this->temp[] = $path;

        return $path;
    }

    /**
     * CRITICAL-1: the 60-frame 316×316 resync bomb (5,991,360 cells) was ACCEPTED under
     * the round-3 6 M ceiling (measured 1.5 GB RSS / 20 s) and is now refused pre-decode
     * by the recalibrated 400 k ceiling. The offsets AGREE for this cleanly-chained GIF,
     * so control reaches the budget gate (not the earlier layout refuse) — proving the
     * numeric ceiling, not a walk mismatch, is what stops it.
     */
    public function testResyncBombIsRefusedByRecalibratedCellBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/of 60 .*frames exceeds the 400000-cell budget/');

        ImageSource::fromAnimatedFile($this->fixture('resync60.gif'));
    }

    /**
     * The resync bomb's honest and flip-mirror descriptor offset lists are EQUAL — it is
     * the aggregate cell budget (CRITICAL-1), not the layout reconcile (CRITICAL-2), that
     * refuses it. Pinning this keeps the two gates from silently trading places if either
     * walker drifts.
     */
    public function testResyncBombReachesTheBudgetGateNotTheLayoutGate(): void
    {
        $bytes = (string) file_get_contents($this->fixture('resync60.gif'));

        $honest = $this->offsets('gifHonestDescriptorOffsets', $bytes);
        $flip   = $this->offsets('gifFlipWalkDescriptorOffsets', $bytes);

        self::assertSame($honest, $flip, 'resync frames are cleanly chained: flip lands on the honest offsets');
        self::assertCount(60, $honest);
        // 60 × 316 × 316 = 5,991,360 cells: under the round-3 6 M ceiling (accepted then),
        // over the recalibrated 400 k ceiling (refused now). That crossing is CRITICAL-1.
        self::assertGreaterThan(400_000, 60 * 316 * 316);
        self::assertLessThan(6_000_000, 60 * 316 * 316);
    }

    /**
     * CRITICAL-2: phantom.gif keeps the frame COUNT at 3 (honest {..68..} vs flip {..56..})
     * while a phantom descriptor replaces a real frame's bytes. A count-only reconcile
     * shipped the phantom as authentic; the offset-list reconcile refuses it.
     */
    public function testPhantomDescriptorGifIsRefusedByLayoutReconcile(): void
    {
        $bytes = (string) file_get_contents($this->fixture('phantom.gif'));

        $honest = $this->offsets('gifHonestDescriptorOffsets', $bytes);
        $flip   = $this->offsets('gifFlipWalkDescriptorOffsets', $bytes);
        self::assertSame(count($honest), count($flip), 'the attack preserves the frame COUNT');
        self::assertNotSame($honest, $flip, '...while shifting a frame POSITION');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/disagrees with the container on 3 frame positions/');

        ImageSource::fromAnimatedFile($this->fixture('phantom.gif'));
    }

    /**
     * CRITICAL-2 (delay/disposal forgery): delayforge.gif rides the same fixed-offset GCE
     * mis-skip to forge per-frame attributes while the counts line up. Refused by the same
     * structural offset reconcile, before flip reads the forged bytes.
     */
    public function testDelayForgeGifIsRefusedByLayoutReconcile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/disagrees with the container on 3 frame positions/');

        ImageSource::fromAnimatedFile($this->fixture('delayforge.gif'));
    }

    /**
     * HIGH: the GIF logical screen and flip's payload are decoded from the ALREADY-READ
     * bytes, never by re-opening `$path`. Handing the loader a validated `$bytes` buffer
     * alongside a path whose target is NOT a GIF must still succeed — proving the original
     * path is opened exactly once (in readBoundedRegularFile) and a post-read swap cannot
     * be re-followed by getimagesize()/file_get_contents().
     */
    public function testGifDecodeUsesValidatedBytesNotAPathReOpen(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd required to decode the staged GIF frame');
        }

        $validBytes = F::gif(2, 2, [[0, 0, 0], [255, 0, 0], [0, 255, 0]], [
            ['pixels' => [0, 1, 2, 1], 'delay' => 10],
        ]);
        // A "swapped" target that is NOT a decodable image — the attacker's post-read rename.
        $hostilePath = $this->writeTemp('NOT-A-GIF' . str_repeat('Z', 64));

        $m = new \ReflectionMethod(ImageSource::class, 'animatedFramesFromGif');
        $m->setAccessible(true);

        /** @var \SugarCraft\Mosaic\Animation $anim */
        $anim = $m->invoke(null, $hostilePath, $validBytes, ImageSource::MAX_PIXELS);

        self::assertSame(1, $anim->frameCount(), 'frames come from the validated bytes, not the re-opened path');
    }

    /**
     * HIGH (still-PNG twin): the degenerate single-frame branch of fromAnimatedFile also
     * runs over the ALREADY-READ bytes. It is routed through `animationFromStillPng()`,
     * which structurally has NO path parameter — the only way it could re-open the caller's
     * path is by reverting the extraction, and the ReflectionMethod signature below pins
     * that it takes exactly `(string $bytes, int $maxPixels)`. Decoding the committed still
     * fixture's bytes through it yields one image/png frame without ever touching a path.
     */
    public function testStillPngDecodesFromBytesWithNoPathParameter(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd required to decode the still PNG frame');
        }

        $rm = new \ReflectionMethod(ImageSource::class, 'animationFromStillPng');
        $rm->setAccessible(true);

        $params = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            $rm->getParameters()
        );
        self::assertSame(['bytes', 'maxPixels'], $params, 'the still-PNG ingress takes bytes only, never a path');

        $bytes = (string) file_get_contents(__DIR__ . '/fixtures/8x4_red.png');
        /** @var \SugarCraft\Mosaic\Animation $anim */
        $anim = $rm->invoke(null, $bytes, ImageSource::MAX_PIXELS);

        self::assertSame(1, $anim->frameCount());
        self::assertSame('image/png', $anim->frames[0]->format);
    }

    /**
     * Round-5 MINOR: a GIF truncated to 10..12 bytes (valid signature, incomplete
     * header+LSD) must refuse cleanly, not trip a raw out-of-range read at byte 10 in
     * either descriptor walker. Under the suite's failOnWarning=true an E_WARNING would
     * surface as a test failure, so expecting the typed InvalidArgumentException here pins
     * BOTH the absence of the warning AND the clean refusal (the honest/flip walkers now
     * short-circuit below 13 bytes, matching the 13-byte header+LSD minimum).
     */
    public function testGifShorterThanLogicalScreenRefusesWithoutWarning(): void
    {
        // 10 bytes: GIF89a signature + 4 trailing bytes — no complete logical screen.
        $path = $this->writeTemp('GIF89a' . "\x0a\x00\x0a\x00");
        self::assertSame(10, strlen((string) file_get_contents($path)));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported image format|supports GIF and APNG/i');

        ImageSource::fromAnimatedFile($path);
    }

    /**
     * @return list<int>
     */
    private function offsets(string $method, string $bytes): array
    {
        $rm = new \ReflectionMethod(ImageSource::class, $method);
        $rm->setAccessible(true);

        /** @var list<int> $r */
        $r = $rm->invoke(null, $bytes);

        return $r;
    }
}
