<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Tests\Support\AnimatedImageFixtures as F;

/**
 * Finding #18: {@see ImageSource::fromAnimatedFile()} is the single entry point
 * that turns a GIF or APNG on disk into a validated {@see
 * \SugarCraft\Mosaic\Animation}, and a still PNG into the degenerate one-frame
 * animation. Frame counts, centisecond→millisecond delay conversion, fully
 * composited frames, and the per-frame / aggregate pixel ceilings are asserted
 * here end-to-end (the decoders themselves carry their own unit tests).
 *
 * @covers \SugarCraft\Mosaic\ImageSource
 */
final class ImageSourceAnimatedFileTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function write(string $name, string $bytes): string
    {
        $path = sys_get_temp_dir() . '/mosaic-anim-' . $name;
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Read a frame's pixel through GD, returning [r,g,b,a] with a in 0..255.
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function pixel(ImageSource $frame, int $x, int $y): array
    {
        $image = imagecreatefromstring($frame->bytes);
        $this->assertNotFalse($image);
        $color = imagecolorat($image, $x, $y);
        imagedestroy($image);

        $a = 127 - (($color >> 24) & 0x7F);          // GD 0=opaque..127=transparent
        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF, (int) round($a * 255 / 127)];
    }

    public function testGifProducesFrameCountDelaysAndOpaquePixels(): void
    {
        $gif = F::gif(2, 2, [[0, 0, 0], [255, 0, 0], [0, 255, 0]], [
            ['pixels' => [1, 1, 1, 1], 'delay' => 15],  // full red · 15cs = 150ms
            ['pixels' => [1, 2, 1, 1], 'delay' => 20],  // green at (1,0) · 20cs = 200ms
        ]);

        $animation = ImageSource::fromAnimatedFile($this->write('opaque.gif', $gif));

        $this->assertSame(2, $animation->frameCount());
        $this->assertSame([150, 200], $animation->delaysMs, 'centiseconds must convert to milliseconds');
        foreach ($animation->frames as $frame) {
            $this->assertSame(2, $frame->width);
            $this->assertSame(2, $frame->height);
            $this->assertSame('image/png', $frame->format);
        }
        $this->assertSame([255, 0, 0, 255], $this->pixel($animation->frames[0], 0, 0));
        $this->assertSame([0, 255, 0, 255], $this->pixel($animation->frames[1], 1, 0));
    }

    public function testApngProducesCompositedFramesWithMillisecondDelays(): void
    {
        $apng = F::apng(2, 2, [
            [
                'w' => 2, 'h' => 2, 'x' => 0, 'y' => 0,
                'delayNum' => 15, 'delayDen' => 100, 'dispose' => 1, 'blend' => 0,
                'pixels' => [[255, 0, 0, 255], [255, 0, 0, 255], [255, 0, 0, 255], [255, 0, 0, 255]],
            ],
            [
                'w' => 1, 'h' => 1, 'x' => 1, 'y' => 1,
                'delayNum' => 25, 'delayDen' => 100, 'dispose' => 0, 'blend' => 1,
                'pixels' => [[0, 0, 255, 255]],
            ],
        ]);

        $animation = ImageSource::fromAnimatedFile($this->write('comp.png', $apng));

        $this->assertSame(2, $animation->frameCount());
        $this->assertSame([150, 250], $animation->delaysMs);
        // frame1 was disposed-to-background before painting, so only (1,1) is opaque blue.
        $this->assertSame([0, 0, 255, 255], $this->pixel($animation->frames[1], 1, 1));
        $this->assertSame(0, $this->pixel($animation->frames[1], 0, 0)[3], 'cleared pixel is transparent');
    }

    public function testStillPngIsDegenerateSingleFrameAnimation(): void
    {
        $animation = ImageSource::fromAnimatedFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->assertSame(1, $animation->frameCount());
        $this->assertSame([0], $animation->delaysMs);
        $this->assertSame(8, $animation->frames[0]->width);
        $this->assertSame(4, $animation->frames[0]->height);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/File not found/i');
        ImageSource::fromAnimatedFile('/no/such/path-anim.gif');
    }

    public function testUnsupportedFormatThrows(): void
    {
        $path = $this->write('bogus.bin', 'this is not an image at all');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/GIF and APNG/i');
        ImageSource::fromAnimatedFile($path);
    }

    public function testGifPerFramePixelCeiling(): void
    {
        $gif = F::gif(2, 2, [[0, 0, 0], [255, 0, 0]], [
            ['pixels' => [1, 1, 1, 1], 'delay' => 5],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed/i');
        ImageSource::fromAnimatedFile($this->write('ceil.gif', $gif), 3);
    }

    public function testApngAggregatePixelCeiling(): void
    {
        $frame = [
            'w' => 2, 'h' => 2, 'x' => 0, 'y' => 0,
            'delayNum' => 1, 'delayDen' => 100, 'dispose' => 0, 'blend' => 0,
            'pixels' => [[255, 0, 0, 255], [255, 0, 0, 255], [255, 0, 0, 255], [255, 0, 0, 255]],
        ];
        $apng = F::apng(2, 2, [$frame, $frame, $frame]);

        // per-frame 4 ≤ 6 passes; aggregate 4 × 3 = 12 > 6 trips.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeding/i');
        ImageSource::fromAnimatedFile($this->write('agg.png', $apng), 6);
    }
}
