<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Dither;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;
use SugarCraft\Mosaic\Renderer\Renderer;
use SugarCraft\Mosaic\Renderer\SixelRenderer;
use SugarCraft\Mosaic\TmuxPassthroughDecorator;

/**
 * Finding #17: {@see SixelRenderer::encodeBandStream()} must reproduce the
 * one-shot {@see SixelRenderer::render()} byte-for-byte while handing the
 * consumer the encoding in ordered fragments (header → per-band → terminator).
 *
 * @covers \SugarCraft\Mosaic\Renderer\SixelRenderer
 * @covers \SugarCraft\Mosaic\TmuxPassthroughDecorator
 */
final class SixelStreamTest extends TestCase
{
    private const ESC = "\x1b";

    /**
     * Collect every `$write` argument from a band stream in order.
     *
     * @return list<string>
     */
    private function collect(SixelRenderer $renderer, ImageSource $image, int $width, ?int $height = null): array
    {
        $chunks = [];
        $renderer->encodeBandStream($image, $width, static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, $height);

        return $chunks;
    }

    /**
     * @return array<string, array{0: SixelRenderer, 1: string, 2: int, 3: int|null}>
     */
    public static function corpus(): array
    {
        $plain = new SixelRenderer();
        $atkinson = new SixelRenderer(Dither::Atkinson);
        $ordered = new SixelRenderer(Dither::FloydSteinberg);
        $fewColors = new SixelRenderer(Dither::None, 16);
        $truecolorish = new SixelRenderer(Dither::Stucki, 256);
        $tallCells = new SixelRenderer(Dither::None, 256, 10, 20);

        return [
            // width 8 → pixelW 80 (non-multiple of 6), height 4 → partial last band.
            'flat red, default cells' => [$plain, __DIR__ . '/../fixtures/8x4_red.png', 8, 4],
            'opaque noise, truecolor dither' => [$truecolorish, __DIR__ . '/../fixtures/500x400_noise.png', 30, 24],
            'transparent hole, atkinson' => [$atkinson, __DIR__ . '/../fixtures/2x2_transparent_halfblock.png', 12, null],
            'few colours, no dither' => [$fewColors, __DIR__ . '/../fixtures/500x400_noise.png', 17, null],
            'aspect-derived height' => [$ordered, __DIR__ . '/../fixtures/8x4_red.png', 13, null],
            'tall cell box' => [$tallCells, __DIR__ . '/../fixtures/500x400_noise.png', 9, 7],
        ];
    }

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

    public function testBandCountMatchesPixelCanvasHeight(): void
    {
        $renderer = new SixelRenderer(Dither::None, 256, 10, 20);
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/8x4_red.png');

        // 4 cells tall × 20px = 80px → ceil(80/6) = 14 bands, +1 header +1 terminator.
        $chunks = $this->collect($renderer, $image, 8, 4);

        $this->assertCount(14 + 2, $chunks);
    }

    public function testGraphicsNewlineJoinsBandsNotHeader(): void
    {
        $renderer = new SixelRenderer();
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/500x400_noise.png');
        $chunks = $this->collect($renderer, $image, 20, 12);

        $bandBodies = array_slice($chunks, 1, -1);
        // The very first band body follows the palette directly — no graphics
        // newline, which would desync the raster from register 0.
        $this->assertFalse(
            str_starts_with($bandBodies[0], '-'),
            'the first band must NOT be prefixed by the graphics newline',
        );
        // Every band after the first is joined by the graphics newline …
        $this->assertStringStartsWith('-', $bandBodies[1]);
        // … while no band body carries an escape byte of its own.
        $this->assertStringNotContainsString("\x1b", $bandBodies[0], 'band bodies carry no escape bytes');
    }

    public function testTmuxStreamEqualsWrapOfOneShotRender(): void
    {
        $inner = new SixelRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/500x400_noise.png');

        $chunks = [];
        $decorator->encodeBandStream($image, 18, static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, 12);
        $streamed = implode('', $chunks);

        $this->assertSame($decorator->render($image, 18, 12), $streamed);
        $this->assertSame(
            $decorator->wrap($inner->render($image, 18, 12)),
            $streamed,
            'the streamed tmux envelope must match wrapping the whole sixel',
        );
        $this->assertStringStartsWith(self::ESC . 'Ptmux;', $streamed);
        $this->assertStringEndsWith(self::ESC . '\\', $streamed);
    }

    public function testTmuxStreamDoublesEveryInnerEscapeByte(): void
    {
        $inner = new SixelRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/8x4_red.png');

        $chunks = [];
        $decorator->encodeBandStream($image, 8, static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, 4);

        $this->assertStringContainsString(self::ESC . self::ESC . 'P', implode('', $chunks), 'the inner DCS ESC is doubled inside the tmux envelope');
    }

    public function testTmuxStreamRefusesNonSixelRenderer(): void
    {
        $decorator = new TmuxPassthroughDecorator(new HalfBlockRenderer());
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/8x4_red.png');

        $this->expectException(\LogicException::class);
        $decorator->encodeBandStream($image, 8, static function (string $chunk): void {
        }, 4);
    }

    public function testTmuxDecoratorStillImplementsRenderer(): void
    {
        $decorator = new TmuxPassthroughDecorator(new SixelRenderer());
        $this->assertInstanceOf(Renderer::class, $decorator);
    }

    /**
     * Round-1 review M4: if a consumer's `$write` throws mid-stream (a closed pipe
     * during video playback is the realistic trigger), the encoder must still emit
     * the DCS string terminator so the terminal is not left swallowing later output
     * as sixel parameters.
     */
    public function testStreamTerminatesDcsWhenConsumerFailsMidStream(): void
    {
        $renderer = new SixelRenderer();
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/500x400_noise.png');

        $writes = [];
        $boom = new \RuntimeException('pipe closed');
        try {
            $renderer->encodeBandStream($image, 20, static function (string $chunk) use (&$writes, $boom): void {
                $writes[] = $chunk;
                if (count($writes) === 3) {
                    throw $boom;
                }
            }, 12);
            $this->fail('the consumer failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame($boom, $e, 'the original consumer error must surface');
        }

        // The header (write #1) reached the wire, so the finally must have appended
        // the terminator even though band emission was abandoned.
        $this->assertNotEmpty($writes);
        $this->assertStringEndsWith(
            self::ESC . '\\',
            implode('', $writes),
            'a half-written DCS must still be terminated',
        );
    }

    /**
     * Round-1 review M1: when the inner Sixel encode fails BEFORE producing any
     * fragment (a GD load failure), the tmux decorator must not have opened the
     * `\x1bPtmux;` passthrough at all — a dangling opener would swallow the whole
     * terminal. The envelope is opened lazily, on the first real chunk.
     */
    public function testTmuxStreamNeverOpensEnvelopeWhenInnerFailsEarly(): void
    {
        // A source whose bytes are not a decodable image: imagecreatefromstring
        // fails inside encodeInto() before a single write, exactly the pre-first-
        // chunk failure the lazy-open guard targets.
        $broken = new ImageSource('this is not a PNG', 'image/png', 8, 4);
        $decorator = new TmuxPassthroughDecorator(new SixelRenderer());

        $writes = [];
        try {
            $decorator->encodeBandStream($broken, 8, static function (string $chunk) use (&$writes): void {
                $writes[] = $chunk;
            }, 4);
            $this->fail('a corrupt source must throw');
        } catch (\Throwable) {
            // expected — the point is that nothing was written.
        }

        $this->assertSame(
            [],
            $writes,
            'the tmux passthrough envelope must not open when the inner encode never emits',
        );
    }
}
