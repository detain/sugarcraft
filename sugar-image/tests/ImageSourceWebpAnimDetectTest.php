<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\UnsupportedFormatException;

/**
 * E-05: animated WebP must be refused on its container STRUCTURE, before any
 * decoder sees it — on hosts whose libwebp demuxes animation to a first frame,
 * GD used to hand back a happy one-frame still from an animated file (and on
 * every host the alternative was a generic "GD failed to load image"
 * corruption accusation). The walk honours RIFF chunk sizes + the even-padding
 * rule, so a decoy oversized chunk cannot desync it into accusing an innocent
 * sibling; undecidable structures FAIL SAFE (no refusal, bytes keep their
 * pre-walk fate) mirroring detectImageFormat()'s existing posture.
 *
 * Every fixture here is hand-built with pack() — no GD needed for the refusal
 * polarities because the check fires PRE-decode. The static-WebP fixtures that
 * must actually decode through GD's accept path skip honestly when ext-gd
 * lacks WebP (the label pin, in contrast, never needs GD: detect() is pure).
 *
 * @covers \SugarCraft\Image\ImageSource
 * @covers \SugarCraft\Image\UnsupportedFormatException
 */
final class ImageSourceWebpAnimDetectTest extends TestCase
{
    /** A real 4x4 lossy WebP written by GD; null when this GD cannot write WebP. */
    private static ?string $gdStaticWebp = null;

    private static function gdLossyWebp(): ?string
    {
        if (!function_exists('imagewebp')) {
            return null;
        }
        if (self::$gdStaticWebp !== null) {
            return self::$gdStaticWebp;
        }
        $im = imagecreatetruecolor(4, 4);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 10, 200, 30));
        ob_start();
        imagewebp($im, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return self::$gdStaticWebp = $bytes;
    }

    /** One RIFF chunk: fourcc, u32-LE payload size, payload, even-padding byte. */
    private static function chunk(string $fourcc, string $payload): string
    {
        return $fourcc . pack('V', strlen($payload)) . $payload . (strlen($payload) % 2 ? "\x00" : '');
    }

    /** A complete WEBP container around the given chunk list. */
    private static function riffWebp(string ...$chunks): string
    {
        $body = 'WEBP' . implode('', $chunks);

        return 'RIFF' . pack('V', strlen($body)) . $body;
    }

    /** VP8X chunk; $anim toggles the ANIM bit (bit 1 of the flags byte). */
    private static function vp8x(bool $anim, int $w = 4, int $h = 4): string
    {
        $flags = $anim ? "\x02" : "\x00";

        return self::chunk('VP8X', $flags . "\x00\x00\x00" . substr(pack('V', $w - 1), 0, 3) . substr(pack('V', $h - 1), 0, 3));
    }

    /** ANMF chunk: 10-byte frame header (X/Y/W-1/H-1/duration/flags) + sub-chunks. */
    private static function anmf(string ...$frameChunks): string
    {
        return self::chunk('ANMF', "\x00\x00\x03\x03\x64\x00\x00\x00\x00\x00" . implode('', $frameChunks));
    }

    /** GD's own VP8/VP8L bitstream for a tiny frame, for embedding in ANMF. */
    private static function gdFrameBits(int $r): string
    {
        $webp = self::gdLossyWebp();
        if ($webp === null) {
            // Structural refusal never decodes the payload, so synthetic bits
            // are a faithful stand-in when GD is WebP-less (skips gate the
            // decode-path pins separately, not these).
            return "\x30\x01\x00\x9d\x01\x2a" . str_repeat("\x00", 8);
        }

        return substr($webp, 20);
    }

    /** The canonical two-frame animated WebP: VP8X(ANIM) + two ANMF frames. */
    private static function twoFrameAnimatedWebp(): string
    {
        return self::riffWebp(
            self::vp8x(true),
            self::anmf(self::chunk('VP8 ', self::gdFrameBits(10))),
            self::anmf(self::chunk('VP8 ', self::gdFrameBits(200))),
        );
    }

    public function testTheRedBeforeStateThisClassCures(): void
    {
        // E-05 pin of the DEFECT SHAPE, host-independent: with the walk neutered
        // (base f90a7d739 behavior) the two-frame ANMF file was either silently
        // decoded to one still or refused as generic corruption — never named.
        // Today it must carry the honest class AND the honest sentence.
        $bytes = self::twoFrameAnimatedWebp();
        try {
            ImageSource::fromString($bytes);
            self::fail('animated WebP reached an accept path at all');
        } catch (UnsupportedFormatException $e) {
            self::assertStringContainsString('needs chafa or ffmpeg', $e->getMessage());
        }
        // The refined class stays inside the "bad input" family callers already
        // catch, so no caller's catch block needs editing to keep working:
        self::assertTrue(is_subclass_of(UnsupportedFormatException::class, \InvalidArgumentException::class));
    }

    public function testTwoFrameAnimatedWebpIsRefusedBeforeDecode(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionMessage('needs chafa or ffmpeg');
        ImageSource::fromString(self::twoFrameAnimatedWebp());
    }

    public function testTheVp8xAnimBitAloneRefusesWithoutAnyAnmfChunk(): void
    {
        // VP8X advertises ANIM but no frames follow: still animated intent —
        // the structural evidence is sufficient on its own.
        $this->expectException(UnsupportedFormatException::class);
        ImageSource::fromString(self::riffWebp(self::vp8x(true)));
    }

    public function testAnAnmfChunkRefusesEvenWhenTheVp8xHeaderStaysQuiet(): void
    {
        // The other half of the either-way rule: VP8X with ANIM CLEARED but an
        // ANMF sibling (a header that failed to advertise its frames).
        $bytes = self::riffWebp(
            self::vp8x(false),
            self::anmf(self::chunk('VP8 ', self::gdFrameBits(10))),
        );
        $this->expectException(UnsupportedFormatException::class);
        ImageSource::fromString($bytes);
    }

    public function testFromAnimatedFileNamesTheMissingCapabilityInsteadOfTheGenericRefusal(): void
    {
        // Pre-E-05 this path answered "Animated loading supports GIF and APNG,
        // not image/webp" only when GD refused; where GD demuxed, callers got a
        // silent one-frame Animation. Now the ingress refuses by name, PRE-decode.
        $tmp = tempnam(sys_get_temp_dir(), 's5webp');
        self::assertIsString($tmp);
        file_put_contents($tmp, self::twoFrameAnimatedWebp());
        try {
            $this->expectException(UnsupportedFormatException::class);
            ImageSource::fromAnimatedFile($tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function testStaticLossyWebpStaysAcceptedWithItsLabelUnmoved(): void
    {
        // NEGATIVE polarity: the whole point of the structural walk is that a
        // static WebP — VP8X without ANIM (ICCP alpha extension included) —
        // keeps its pre-E-05 fate byte-for-byte.
        $webp = self::gdLossyWebp();
        if ($webp === null) {
            self::markTestSkipped('GD built without WebP support');
        }
        $source = ImageSource::fromString($webp);
        self::assertSame('image/webp', $source->format);
        self::assertSame(4, $source->width);
    }

    public function testStaticVp8xWebpWithoutTheAnimBitIsProvenStaticNotRefused(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD built without WebP support');
        }
        $bytes = self::riffWebp(self::vp8x(false, 4, 4), self::chunk('VP8 ', self::gdFrameBits(10)));
        $source = ImageSource::fromString($bytes);
        self::assertSame('image/webp', $source->format);
    }

    public function testAStaticLosslessVp8lContainerKeepsItsWebpLabelUntouched(): void
    {
        // The negative polarity for the OTHER static encoder: a VP8L (lossless)
        // WebP with a padding-honoured sibling chunk must sail past the walk
        // with its label, byte-for-byte as pre-E-05. Judged at the detect stage
        // because a GD build that cannot WRITE lossless WebP has no stake in
        // this — the walk's job is precisely to NOT touch it.
        $bytes = self::riffWebp(
            self::chunk('VP8L', "\x2f\x00\x00\x00\x00"),
            self::chunk('ANIM', "\x00\x00"), // loop-count chunk: NOT animation proof
        );
        $detect = new \ReflectionMethod(ImageSource::class, 'detectImageFormat');
        $detect->setAccessible(true);
        self::assertSame('image/webp', $detect->invoke(null, $bytes, null));
    }

    public function testADecoyOversizedChunkClaimCannotDesyncTheWalkIntoAFalseRefusal(): void
    {
        // First chunk CLAIMS 2 GB of payload. The walk clamps to the RIFF size
        // field, sees the claim run past the container, and gives up with the
        // fail-safe null — no accusation, no desync into reading a fourcc out
        // of the middle of a lied-about payload. GD judges the bytes exactly
        // as it did before E-05 existed (generic corruption refusal here).
        $lied  = 'VP8 ' . pack('V', 0x7FFFFF00) . str_repeat("\x00", 16);
        $bytes = 'RIFF' . pack('V', 4 + strlen('WEBP' . $lied)) . 'WEBP' . $lied;
        $walk  = new \ReflectionMethod(ImageSource::class, 'webpCarriesAnimation');
        $walk->setAccessible(true);
        self::assertNull($walk->invoke(null, $bytes));
        try {
            ImageSource::fromString($bytes);
            self::fail('this fixture must not decode on a spec-conformant GD');
        } catch (UnsupportedFormatException $e) {
            self::fail('oversized claim must fail safe, not refuse: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('GD failed to load image', $e->getMessage());
        }
    }

    public function testAMalformedRiffContainerFailsSafeIntoTodaysGdJudgement(): void
    {
        // "RIFF" + "WEBP" magic with a ZERO size field and garbage inside the
        // declared window: nothing decidable, so no refusal from us — GD's
        // decode gate stays the judge exactly as before E-05 existed.
        $bytes = "RIFF\x00\x00\x00\x00WEBP\xFF\xFF\xFF\xFFjunkjunk";
        try {
            ImageSource::fromString($bytes);
            self::fail('this fixture must not decode on a spec-conformant GD');
        } catch (UnsupportedFormatException $e) {
            self::fail('undecidable structure must not be refused as animated: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('GD failed to load image', $e->getMessage());
        }
    }

    public function testTheRefusalFiresBeforeGdSoNoAnimatedDecodeIsEverAttempted(): void
    {
        // The refusal class proves the ORDER: UnsupportedFormatException (ours)
        // not RuntimeException/InvalidArgumentException-with-GD-wording (theirs),
        // even for bytes no decoder on earth would accept once past the header.
        $bytes = self::riffWebp(self::vp8x(true), self::chunk('ANMF', 'x'));
        try {
            ImageSource::fromString($bytes);
            self::fail('expected refusal');
        } catch (UnsupportedFormatException $e) {
            self::assertStringNotContainsString('GD failed', $e->getMessage());
        }
    }

    public function testTheWalkReturnsTheFailSafeNullForUndecidableAndFalseForProvenStatic(): void
    {
        // Direct tri-state probe of the private decision function (same
        // semantics fromString() consumes; reflection only to read the third
        // state, which no public outcome distinguishes).
        $walk = new \ReflectionMethod(ImageSource::class, 'webpCarriesAnimation');
        $walk->setAccessible(true);

        self::assertTrue($walk->invoke(null, self::twoFrameAnimatedWebp()));
        self::assertFalse($walk->invoke(null, self::riffWebp(self::chunk('VP8 ', 'framebits'))));
        self::assertNull($walk->invoke(null, "RIFF\x00\x00\x00\x00WEBPgarbagegarbage"));
    }

    public function testAWalkPastTheChunkCeilingGivesUpWithoutAccusing(): void
    {
        // MAX_RIFF_CHUNKS is private; widening it at runtime is impossible, so
        // the ceiling is exercised by exceeding 64 with tiny padding chunks.
        // Zero of them carry ANMF or a VP8X, and the budget exhaustion must
        // land in the fail-safe null — i.e. the file keeps GD's judgement.
        $chunks = '';
        for ($n = 0; $n < 70; $n++) {
            $chunks .= self::chunk('VP8K', 'pad'); // odd payload -> 8-byte chunk
        }
        $bytes = self::riffWebp($chunks);
        $walk  = new \ReflectionMethod(ImageSource::class, 'webpCarriesAnimation');
        $walk->setAccessible(true);
        self::assertNull($walk->invoke(null, $bytes));

        // Public outcome: no refusal fires (GD decides, as pre-E-05). The
        // synthetic container itself is unlabelled garbage to GD either way;
        // fromString throws its generic InvalidArgumentException, never ours.
        try {
            ImageSource::fromString($bytes);
        } catch (UnsupportedFormatException $e) {
            self::fail('chunk-budget exhaustion must fail safe, not refuse: ' . $e->getMessage());
        } catch (\InvalidArgumentException) {
            // today's fate, unchanged — pass
        }
    }

    public function testPaddedOddSizeChunksAdvanceTheWalkWithoutFalsePositives(): void
    {
        // Every static chunk here has an ODD payload (padding byte follows).
        // A walker that ignored the pad would land mid-header two bytes over
        // and read garbage fourccs; with the pad honoured the walk proves
        // 'static' cleanly. Positive sibling control: same layout + ANMF = refuse.
        $static = self::riffWebp(
            self::chunk('ICCP', 'icc'),
            self::chunk('VP8 ', 'bits'),
        );
        $walk = new \ReflectionMethod(ImageSource::class, 'webpCarriesAnimation');
        $walk->setAccessible(true);
        self::assertFalse($walk->invoke(null, $static));
        self::assertTrue($walk->invoke(null, self::riffWebp(
            self::chunk('ICCP', 'icc'),
            self::anmf(self::chunk('VP8 ', 'bits')),
        )));
    }

    public function testGifAndPngIngressThroughDetectAreByteUntouched(): void
    {
        // Existing formats keep their labels through the same detect() stage
        // (real GD-written files, honestly skipped when the codecs are absent).
        if (!function_exists('imagegif')) {
            self::markTestSkipped('GD built without GIF support');
        }
        $im = imagecreatetruecolor(4, 4);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 1, 2, 3));
        ob_start();
        imagegif($im);
        $gif = (string) ob_get_clean();
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        self::assertSame('image/gif', ImageSource::fromString($gif)->format);
        self::assertSame('image/png', ImageSource::fromString($png)->format);
    }
}
