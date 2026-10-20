<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Animation\Decoder as AnimationDecoder;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Tests\Support\AnimatedImageFixtures as F;

/**
 * E-07: the GIF leg of fromAnimatedFile() decodes from the validated bytes
 * exactly once — no temp-file staging, no path re-open (the round-4 HIGH).
 * The structural legs below pin that shape; the parity leg pins the promise
 * that the bytes route reproduces the legacy cell-grid reconstruction frame
 * for frame, byte for byte, so the flip could not have moved a pixel.
 *
 * @covers \SugarCraft\Image\ImageSource
 */
final class ImageSourceGifBytesIngressTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }
        $this->tempPaths = [];
        parent::tearDown();
    }

    private function writeGif(string $bytes): string
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }

        $path = sys_get_temp_dir() . '/sugar-image-e07-' . uniqid('', true) . '.gif';
        $this->tempPaths[] = $path;
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * The route under test: black/red/green 2×2, one transparent pixel in
     * the second frame — every leg (null cells, opaque cells, multi-frame
     * compositing) lands on a different mapping than the trivial one.
     *
     * @return array{0:string,1:string} gif bytes + written path
     */
    private function twoFrameGifWithTransparency(): array
    {
        $gif = F::gif(2, 2, [[0, 0, 0], [255, 0, 0], [0, 255, 0]], [
            ['pixels' => [1, 1, 1, 1], 'delay' => 15],
            ['pixels' => [0, 2, 0, 2], 'delay' => 20, 'transparent' => 0, 'disposal' => 2],
        ]);

        return [$gif, $this->writeGif($gif)];
    }

    public function testTheDecodeHelperTakesBytesOnlyWithNoTempStaging(): void
    {
        $method = new ReflectionMethod(ImageSource::class, 'decodeGifFramesFromBytes');

        $this->assertSame(
            ['bytes', 'w', 'h'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters()),
            'the helper signature must be bytes-first — a path parameter in any slot would re-open the TOCTOU window',
        );

        $this->assertFalse(
            method_exists(ImageSource::class, 'decodeGifFramesFromBytesSafely'),
            'the round-4 temp-staging helper must stay deleted, not renamed back',
        );

        $source = (string) file_get_contents((string) $method->getFileName());
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $body = implode("\n", array_slice(explode("\n", $source), $start - 1, $end - $start + 1));

        $this->assertStringContainsString('AnimationDecoder::decodeBytes', $body, 'the helper must decode from bytes');
        $this->assertStringNotContainsString('tempnam', $body, 'no temp file may be staged inside the decode helper');
        $this->assertStringNotContainsString('$path', $body, 'no path variable may even appear in the decode helper');
    }

    public function testBytesRouteReproducesLegacyCellGridReconstruction(): void
    {
        [$gif, $path] = $this->twoFrameGifWithTransparency();

        $animation = ImageSource::fromAnimatedFile($path);
        $this->assertSame(2, $animation->frameCount());

        // Legacy reconstruction, verbatim pre-E-07 plumbing: path decode of
        // cell grids, cell array → RGBA string (null = fully transparent,
        // coloured = RGB + full alpha), and the same fromRgb PNG encoder.
        $legacyFrames = AnimationDecoder::decode($path, 2, 2);
        $this->assertCount(2, $legacyFrames);

        foreach ($animation->frames as $i => $frame) {
            $reconstructed = ImageSource::fromRgb(
                $this->rgbaFromCells($legacyFrames[$i]->cells),
                2,
                2,
                true,
            );

            $this->assertSame(
                $reconstructed->bytes,
                $frame->bytes,
                "frame $i: the bytes-ingress route must emit the identical PNG the legacy route did — zero digest movement",
            );
        }
    }

    public function testFramesCarryTheSamePixelsAcrossAFileSwap(): void
    {
        [$gif, $path] = $this->twoFrameGifWithTransparency();

        $before = ImageSource::fromAnimatedFile($path);

        // Replace the file with a DIFFERENT valid GIF while nothing else holds
        // it open. fromAnimatedFile is single-shot by contract (sugar-image
        // decodes once into frames), so the observable E-07 promise here is
        // that the first decode never consults the path again: a second call
        // after the swap decodes the NEW file (legit re-open), and the frames
        // already handed back are untouched by it.
        $other = F::gif(2, 2, [[0, 0, 0], [0, 0, 255]], [
            ['pixels' => [1, 1, 1, 1], 'delay' => 10],
        ]);
        file_put_contents($path, $other);

        $this->assertSame(2, $before->frameCount(), 'already-decoded frames survive the file mutation');
        $this->assertSame(
            [255, 0, 0, 255],
            $this->pixel($before->frames[0], 0, 0),
            'frame 0 pixel (0,0) still the original red',
        );

        $after = ImageSource::fromAnimatedFile($path);
        $this->assertSame(1, $after->frameCount(), 'a fresh open of the swapped file sees the swapped content');
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function pixel(ImageSource $frame, int $x, int $y): array
    {
        $image = imagecreatefromstring($frame->bytes);
        $this->assertNotFalse($image);
        $color = imagecolorat($image, $x, $y);
        imagedestroy($image);

        $a = 127 - (($color >> 24) & 0x7F);

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF, (int) round($a * 255 / 127)];
    }

    /**
     * The consumer-side reconstruction pre-E-07 lived by: null cell = fully
     * transparent, coloured cell = its RGB with full alpha, row-major.
     *
     * @param list<list<array{0:int,1:int,2:int}|null>> $cells
     */
    private function rgbaFromCells(array $cells): string
    {
        $out = '';
        foreach ($cells as $row) {
            foreach ($row as $cell) {
                $out .= $cell === null
                    ? "\x00\x00\x00\x00"
                    : chr($cell[0]) . chr($cell[1]) . chr($cell[2]) . "\xff";
            }
        }
        return $out;
    }
}
