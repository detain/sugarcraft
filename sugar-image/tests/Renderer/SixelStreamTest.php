<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\Renderer;
use SugarCraft\Image\Renderer\SixelRenderer;
use SugarCraft\Image\TmuxPassthroughDecorator;

/**
 * Finding #17: {@see SixelRenderer::encodeBandStream()} must reproduce the
 * one-shot {@see SixelRenderer::render()} byte-for-byte while handing the
 * consumer the encoding in ordered fragments (header → per-band → terminator).
 *
 * @covers \SugarCraft\Image\Renderer\SixelRenderer
 * @covers \SugarCraft\Image\TmuxPassthroughDecorator
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

    /**
     * Round-2 review NEW-3 / M-1: a real pty consumer FORWARDS the bytes it is handed
     * and only THEN dies (a write() that partially succeeds before EPIPE). Recording
     * before throwing models exactly that. Because the "DCS opened" flag is set before
     * delivery (not after), even a first-chunk forward-then-throw must still get the
     * terminator — otherwise the very first band strands the terminal inside a DCS.
     */
    public function testSixelStreamTerminatesWhenConsumerThrowsOnFirstChunk(): void
    {
        $renderer = new SixelRenderer();
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/500x400_noise.png');

        $writes = [];
        $boom = new \RuntimeException('pty died mid-write');
        try {
            $renderer->encodeBandStream($image, 20, static function (string $chunk) use (&$writes, $boom): void {
                $writes[] = $chunk; // delivered to the wire...
                throw $boom;         // ...then the consumer dies
            }, 12);
            $this->fail('the consumer failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame($boom, $e);
        }

        $this->assertNotEmpty($writes, 'the head was already delivered before the throw');
        $this->assertStringEndsWith(
            self::ESC . '\\',
            implode('', $writes),
            'a DCS whose first write was delivered then failed must still be terminated',
        );
    }

    /**
     * Same first-write boundary for the tmux envelope: a consumer that flushes the
     * `\x1bPtmux;` introducer then throws must still receive the closing ST, because
     * the open flag is set before delivery. The alternative strands the whole session
     * inside tmux passthrough.
     */
    public function testTmuxStreamTerminatesWhenConsumerThrowsOnFirstChunk(): void
    {
        $decorator = new TmuxPassthroughDecorator(new SixelRenderer());
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/500x400_noise.png');

        $writes = [];
        $boom = new \RuntimeException('pty died mid-write');
        try {
            $decorator->encodeBandStream($image, 20, static function (string $chunk) use (&$writes, $boom): void {
                $writes[] = $chunk;
                throw $boom;
            }, 12);
            $this->fail('the consumer failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame($boom, $e);
        }

        $this->assertNotEmpty($writes);
        $this->assertStringStartsWith(self::ESC . 'Ptmux;', $writes[0]);
        $this->assertStringEndsWith(
            self::ESC . '\\',
            implode('', $writes),
            'an opened tmux envelope must be closed even when the first delivered write fails',
        );
    }

    /**
     * F-09: DECSIXEL repeat counts are capped at 255 — a decoder reading a
     * pre-fix `!1024` sees `!1` and swallows `024` as resync/literal noise,
     * corrupting the whole band. Per chafa `format_schar_reps`
     * (chafa/internal/chafa-sixel-renderer.c:218-251) runs of 255+ split into
     * repeated `!255` chunks plus a legal remainder, and runs of 1..3 stay
     * bare literals.
     */
    public function testSolidBandOf1024PixelsSplitsIntoLegalRepeats(): void
    {
        // 1024 opaque columns: 4 chunks of !255 + remainder 4 (still >= 4,
        // so the remainder itself rides as !4, not four literals).
        $stream = $this->renderRedColumnsBand(1024, 0, 1023);

        $this->assertStringContainsString('#0!255~!255~!255~!255~!4~', $stream, 'the solid run must arrive split at the 255 boundary');
        $this->assertStringNotContainsString('!1024', $stream);
        $this->assertEveryRepeatIsLegal($stream);
    }

    /**
     * F-09: the width-1024 empty-alpha band — a wide transparent region is
     * the routine trigger (widescreen content on a narrower canvas). The hole
     * columns ride as a long run of the register-0 byte `?` (chr 63) and the
     * run must split legally.
     */
    public function testEmptyAlphaBandOf1024PixelsSplitsIntoLegalRepeats(): void
    {
        // 64 opaque red columns, then 960 transparent holes.
        $hole = chr(63);
        $stream = $this->renderRedColumnsBand(1024, 0, 63);

        $this->assertStringContainsString('#1!64~', $stream, 'register 0 is the transparent background, so red lives at register 1');
        $expectedHoles = '!255' . $hole . '!255' . $hole . '!255' . $hole . '!195' . $hole;
        $this->assertStringContainsString($expectedHoles, $stream, 'the 960-column transparent run must split into 3x!255 + !195');
        $this->assertStringNotContainsString('!960', $stream);
        $this->assertEveryRepeatIsLegal($stream);
    }

    /**
     * F-09: a run whose length is an exact multiple of 255 leaves no
     * remainder byte — the chunk loop covers it entirely.
     */
    public function testExactMultipleOf255EmitsChunksOnly(): void
    {
        $stream = $this->renderRedColumnsBand(510, 0, 509);

        $this->assertStringContainsString('#0!255~!255~', $stream);
        $this->assertStringNotContainsString('!510', $stream);
        $this->assertEveryRepeatIsLegal($stream);
    }

    /**
     * F-09: chafa keeps runs of 1..3 as bare repeated bytes (`!2`/`!3` cost
     * more than the literals and buy nothing); 4 is the first count that
     * rides as `!4`.
     */
    public function testShortRunsStayLiteralAndFourStaysCounted(): void
    {
        $hole = chr(63);
        // 2 opaque red columns, then 4 transparent holes on a 6px band.
        $stream = $this->renderRedColumnsBand(6, 0, 1);

        $this->assertStringContainsString('#1~~!4' . $hole, $stream, 'a run of two repeats literally, a run of four counts');
        $this->assertStringNotContainsString('!2~', $stream);
        // The count-1 case: a band of alternating columns can never carry a
        // `!1` — singles are bare bytes (and !1 is a decoder no-op at best).
        $this->assertDoesNotMatchRegularExpression('/![1-3](?![0-9])/', $stream, 'runs under 4 must not emit !N repeats');
    }

    /**
     * Build a one-band canvas at one pixel per cell: rows are a single 6-row
     * band, columns 0..$pixelW-1 start fully transparent and [$redStart,
     * $redEnd] is painted opaque red (redStart 0, redEnd pixelW-1 = solid).
     */
    private function renderRedColumnsBand(int $pixelW, int $redStart, int $redEnd): string
    {
        $bytes = $this->pngFromGd($pixelW, 6, function (\GdImage $im) use ($redStart, $redEnd): void {
            $red = imagecolorallocate($im, 255, 0, 0);
            $this->assertNotFalse($red);
            imagefilledrectangle($im, $redStart, 0, $redEnd, imagesy($im) - 1, $red);
        });
        $image = new ImageSource($bytes, 'image/png', $pixelW, 6);

        // cellWidth 1 → the requested width is the pixel width verbatim;
        // cellHeight 6 → one band exactly covers the 6 source rows.
        return (new SixelRenderer(Dither::None, 256, 1, 6))->render($image, $pixelW, 1);
    }

    /**
     * GD-authored PNG bytes (house idiom per RenderAllocationGuardTest):
     * truecolor canvas pre-filled fully transparent, painter composes on top.
     */
    private function pngFromGd(int $width, int $height, callable $painter): string
    {
        $im = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($im);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $hole = imagecolorallocatealpha($im, 0, 0, 0, 127);
        $this->assertNotFalse($hole);
        imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, $hole);
        $painter($im);

        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /**
     * Roadmap pin (chafa_improvemnts.md :959): every `!N` repeat token in the
     * emitted stream must be a whole, legal 1..255 count.
     */
    private function assertEveryRepeatIsLegal(string $stream): void
    {
        $matches = [];
        $count = preg_match_all('/!\d+/', $stream, $matches);
        $this->assertGreaterThan(0, $count, 'the fixture must actually produce !N repeats to pin');
        foreach ($matches[0] as $repeat) {
            $this->assertSame(
                1,
                preg_match('/^!([1-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/', $repeat),
                'illegal sixel repeat count in ' . $repeat,
            );
        }
    }
}
