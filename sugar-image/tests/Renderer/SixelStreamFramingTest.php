<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * Finding #17: the band stream really is fragmented, opens with the DCS header
 * and closes with the string terminator, on every corpus row. Split from
 * SixelStreamTest for the reason SixelStreamFixtures gives.
 *
 * @covers \SugarCraft\Image\Renderer\SixelRenderer
 */
final class SixelStreamFramingTest extends TestCase
{
    use SixelStreamFixtures;

    #[DataProvider('corpus')]
    public function testStreamIsActuallyChunked(SixelRenderer $renderer, string $fixture, int $width, ?int $height): void
    {
        $image = ImageSource::fromFile($fixture);
        $chunks = $this->collect($renderer, $image, $width, $height);

        // header + at least one band + terminator, i.e. genuinely fragmented.
        $this->assertGreaterThan(2, count($chunks), 'a multi-band image must produce several writes');
    }

    #[DataProvider('corpus')]
    public function testHeaderFirstAndTerminatorLast(SixelRenderer $renderer, string $fixture, int $width, ?int $height): void
    {
        $image = ImageSource::fromFile($fixture);
        $chunks = $this->collect($renderer, $image, $width, $height);

        $this->assertStringStartsWith(self::ESC . 'P', $chunks[0], 'the first fragment opens the DCS');
        $this->assertStringContainsString('q"', $chunks[0], 'raster attributes ride in the header fragment');
        $this->assertSame(self::ESC . '\\', end($chunks), 'the final fragment is the string terminator');
    }
}
