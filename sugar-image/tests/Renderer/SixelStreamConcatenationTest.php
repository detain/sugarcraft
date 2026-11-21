<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * Finding #17: joining every fragment {@see SixelRenderer::encodeBandStream()}
 * hands the consumer must equal the one-shot {@see SixelRenderer::render()}, on
 * every corpus row. Split from SixelStreamTest for the reason SixelStreamFixtures gives.
 *
 * @covers \SugarCraft\Image\Renderer\SixelRenderer
 */
final class SixelStreamConcatenationTest extends TestCase
{
    use SixelStreamFixtures;

    #[DataProvider('corpus')]
    public function testStreamConcatenationEqualsRender(SixelRenderer $renderer, string $fixture, int $width, ?int $height): void
    {
        $image = ImageSource::fromFile($fixture);
        $chunks = $this->collect($renderer, $image, $width, $height);

        $this->assertSame(
            $renderer->render($image, $width, $height),
            implode('', $chunks),
            'joining every streamed fragment must equal the one-shot render',
        );
    }
}
