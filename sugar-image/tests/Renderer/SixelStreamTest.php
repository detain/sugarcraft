<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\ColorDistance;
use SugarCraft\ColorProfile\PaletteIndex;
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

    /**
     * F-03 helper: chunk stream of an $bands-row canvas at one pixel per cell
     * where rows $redFrom..$redTo (absolute pixel rows) are opaque red and
     * every other row is a fully-transparent hole.
     *
     * @return list<string>
     */
    private function f03Chunks(SixelRenderer $renderer, int $pixelW, int $bands, int $redFrom, int $redTo): array
    {
        $bytes = $this->pngFromGd($pixelW, $bands * 6, function (\GdImage $im) use ($pixelW, $redFrom, $redTo): void {
            $red = imagecolorallocate($im, 255, 0, 0);
            $this->assertNotFalse($red);
            imagefilledrectangle($im, 0, $redFrom, $pixelW - 1, $redTo, $red);
        });
        $image = new ImageSource($bytes, 'image/png', $pixelW, $bands * 6);

        return $this->collect($renderer, $image, $pixelW, $bands);
    }

    /**
     * F-03: with the quirk OFF (the shipped default) fully-transparent edge
     * bands encode to NOTHING — the first band lands as an empty chunk and the
     * last as a bare graphics-newline. This is the mlterm-defect shape and it
     * is byte-frozen: arming the quirk must be the only thing that changes it.
     */
    public function testEmptyEdgeBandsStayEmptyByDefault(): void
    {
        // 4 bands: top and bottom fully transparent, middle two opaque red.
        $plain = array_slice($this->f03Chunks(new SixelRenderer(Dither::None, 256, 1, 6), 4, 4, 6, 17), 1, -1);
        $explicitOff = array_slice($this->f03Chunks((new SixelRenderer(Dither::None, 256, 1, 6))->withMltermEdgeRows(false), 4, 4, 6, 17), 1, -1);

        $this->assertSame(['', '-#1!4~', '-#1!4~', '-'], $plain, 'edge bands must collapse to empty bodies today');
        $this->assertSame($plain, $explicitOff, 'the explicit-off wither must be the default');
    }

    /**
     * F-03: armed, a fully-transparent GLOBAL FIRST band draws a full-width
     * zero-bit run on the background register — chafa's force_full_width pen
     * (chafa-sixel-renderer.c:330-410), flushed with one pen like chafa's
     * trailing '?' run. Width is honoured verbatim (W=4 → !4, W=10 → !10), so
     * the run spans the whole row the way mlterm's bug demands.
     */
    public function testArmedQuirkDrawsFullWidthRunOnEmptyFirstBand(): void
    {
        $armed = (new SixelRenderer(Dither::None, 256, 1, 6))->withMltermEdgeRows();

        $chunks = $this->f03Chunks($armed, 4, 4, 6, 17);
        $this->assertSame('#0!4?', $chunks[1], 'first empty band must carry the full-width background run');

        $wide = $this->f03Chunks($armed, 10, 2, 6, 11);
        $this->assertSame('#0!10?', $wide[1], 'the run must span the canvas width, not a constant');
        $this->assertStringEndsWith('-#1!10~', $wide[2], 'the opaque band keeps its normal body after the widened run');
    }

    /**
     * F-03: armed, the GLOBAL LAST empty band gets the same run (chafa applies
     * force_full_width to is_global_first_row || is_global_last_row,
     * chafa-sixel-renderer.c:415-440) while INTERIOR empty bands stay exactly
     * as bare as the default — the quirk is an edge repair, not a global
     * padding policy.
     */
    public function testArmedQuirkHitsOnlyGlobalEdgeBands(): void
    {
        // 5 bands: b0 empty (first), b1 red, b2/b3 empty (interior), b4 empty (last).
        $armed = (new SixelRenderer(Dither::None, 256, 1, 6))->withMltermEdgeRows();
        $chunks = $this->f03Chunks($armed, 4, 5, 6, 11);

        $this->assertSame(
            ['#0!4?', '-#1!4~', '-', '-', '-#0!4?'],
            array_slice($chunks, 1, -1),
            'edges carry the run; interior empties keep the bare newline',
        );
    }

    /**
     * F-03: the quirk never fires without a background register (offset 0 —
     * emitBand cannot produce an empty body there), so an opaque canvas is
     * byte-identical armed or not, on the corpus and on a solid fixture.
     */
    public function testQuirkIsByteInertOnOpaqueCanvases(): void
    {
        $plain = new SixelRenderer(Dither::None, 256, 1, 6);
        $armed = $plain->withMltermEdgeRows();

        $solid = $this->f03Chunks($plain, 6, 2, 0, 11);
        $armedSolid = $this->f03Chunks($armed, 6, 2, 0, 11);
        $this->assertSame($solid, $armedSolid, 'a canvas with no holes has no empty band to force');

        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/8x4_red.png');
        $this->assertSame($plain->render($image, 8, 4), $armed->render($image, 8, 4));
        $this->assertSame(
            (new SixelRenderer())->render($image, 8, 4),
            (new SixelRenderer())->withMltermEdgeRows()->render($image, 8, 4),
            'default cells must be inert too',
        );
    }

    /**
     * F-03: the streaming path and the one-shot render must agree under the
     * quirk exactly as they do without it (both funnel through encodeInto).
     */
    public function testStreamAndRenderAgreeUnderQuirk(): void
    {
        $armed = (new SixelRenderer(Dither::None, 256, 1, 6))->withMltermEdgeRows();
        $bytes = $this->pngFromGd(4, 24, function (\GdImage $im): void {
            $red = imagecolorallocate($im, 255, 0, 0);
            $this->assertNotFalse($red);
            imagefilledrectangle($im, 0, 6, 3, 17, $red);
        });
        $image = new ImageSource($bytes, 'image/png', 4, 24);

        $this->assertSame($armed->render($image, 4, 4), implode('', $this->collect($armed, $image, 4, 4)));
        // And arming moved bytes versus the default on this hole-bearing canvas.
        $this->assertNotSame((new SixelRenderer(Dither::None, 256, 1, 6))->render($image, 4, 4), $armed->render($image, 4, 4));
    }

    /**
     * F-03: the flag is configuration — it rides the cache fingerprint (a
     * quirk-built blob must never serve a plain cache entry) and survives
     * every other wither.
     */
    public function testQuirkRidesFingerprintAndWithersCarryIt(): void
    {
        $renderer = new SixelRenderer(Dither::FloydSteinberg, 3, 1, 1);
        $this->assertStringEndsWith('|mlterm=1', $renderer->withMltermEdgeRows()->configFingerprint());
        $this->assertStringNotContainsString('mlterm', $renderer->configFingerprint(), 'the default fingerprint stays untouched');

        $stacked = $renderer->withSerpentine()->withMltermEdgeRows()->withAlphaThreshold(90)->withDither(Dither::Stucki);
        $this->assertTrue($stacked->mltermEdgeRows());
        $this->assertTrue($stacked->serpentine(), 'mlterm wither must not drop the snake');
        $this->assertSame(90, $stacked->alphaThreshold());
        $this->assertSame(Dither::Stucki, $stacked->dither());
        $this->assertStringContainsString('|serpentine=1|mlterm=1', $stacked->configFingerprint());
    }

    /**
     * B-14: the default renderer carries no rung and its fingerprint stays
     * byte-identical to the pre-dial pinned string — the quality segment may
     * appear only when explicitly armed (C-04/B-10 discipline).
     */
    public function testQualityDefaultIsNullAndFingerprintUntouched(): void
    {
        $renderer = new SixelRenderer();
        $this->assertNull($renderer->quality());
        $this->assertSame(
            'dither=floyd-steinberg|maxColors=256|cellWidth=10|cellHeight=20',
            $renderer->configFingerprint(),
        );
    }

    /**
     * B-14: arming the dial on a sparse census must NOT change bytes — the
     * router sends samples ≤ budget back through median-cut, so the opt-in
     * is inert without merge mass. On a dense census (4096 noise samples vs
     * 256 colours) the PNN path fires and replaces the median-cut palette.
     */
    public function testQualityArmedRoutesOnlyWhenTheCensusOutweighsTheBudget(): void
    {
        $flat = ImageSource::fromFile(__DIR__ . '/../fixtures/8x4_red.png');
        $armedFlat = (new SixelRenderer())->withQuality(5)->render($flat, 8, 4);
        $this->assertStringStartsWith(self::ESC . 'P', $armedFlat);
        $this->assertSame(
            sha1($armedFlat),
            'd883ac34846a2a92d12e03f16e4f793e354a4ad9',
            'quality-armed sparse render keeps the median-cut bytes',
        );

        $noise = ImageSource::fromFile(__DIR__ . '/../fixtures/500x400_noise.png');
        $median = (new SixelRenderer(Dither::Stucki, 256))->render($noise, 30, 24);
        $pnn = (new SixelRenderer(Dither::Stucki, 256))->withQuality(5)->render($noise, 30, 24);

        $this->assertStringStartsWith(self::ESC . 'P', $pnn);
        $this->assertSame(self::ESC . '\\', substr($pnn, -2), 'the PNN render is a well-formed DCS');
        $this->assertNotSame($median, $pnn, 'the dense census must route to the PNN palette');
        $this->assertSame(sha1($median), 'c79e84bfb8cfee4e0b240e73f62a5908b84f98c4', 'the unarmed render keeps its base bytes');
    }

    /**
     * B-14: every wither carries the rung, in both stacking orders, and an
     * out-of-range rung is refused at the boundary (constructor and wither).
     */
    public function testQualityWithersCarryAndBoundaryRefuses(): void
    {
        $stacked = (new SixelRenderer(Dither::FloydSteinberg, 3, 1, 1))
            ->withQuality(3)->withSerpentine()->withMltermEdgeRows()->withAlphaThreshold(90)->withDither(Dither::Stucki);
        $this->assertSame(3, $stacked->quality());
        $this->assertStringEndsWith('|quality=3', $stacked->configFingerprint());

        $reverse = $stacked->withQuality(9);
        $this->assertSame(9, $reverse->quality(), 're-dialing keeps every other setting');
        $this->assertTrue($reverse->serpentine());
        $this->assertTrue($reverse->mltermEdgeRows());
        $this->assertSame(90, $reverse->alphaThreshold());
        $this->assertSame(Dither::Stucki, $reverse->dither());

        $this->expectException(\InvalidArgumentException::class);
        (new SixelRenderer(Dither::FloydSteinberg, 256, 10, 20, null, 127, null, false, false, 0));
    }

    /**
     * B-14: the wither refuses rung 10 before any copy escapes.
     */
    public function testWithQualityRefusesHighRung(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SixelRenderer())->withQuality(10);
    }

    /**
     * F-14: representative render corpus with base-tree digests (captured
     * BEFORE the band-diff landed, stream == render on every row). The
     * changed-columns bitset + PaletteIndex palette-hash memo are an
     * optimisation only: any byte moving here is a visual regression, not
     * a speed-up. Covers holes (transparent Atkinson), trailing zero runs
     * (tall-cells None), full-width masks (flat red), 16-colour budgets,
     * and both frozen gradient digests.
     *
     * @return array<string, array{0: string, 1: string, 2: int, 3: int|null, 4: string, 5: int}>
     */
    public static function bandDiffCorpus(): array
    {
        return [
            'flat red, default cells' => ['d883ac34846a2a92d12e03f16e4f793e354a4ad9', '8x4_red.png', 8, 4, 'floyd-steinberg', 256],
            'opaque noise, truecolor dither' => ['c79e84bfb8cfee4e0b240e73f62a5908b84f98c4', '500x400_noise.png', 30, 24, 'stucki', 256],
            'transparent hole, atkinson' => ['3cfb0a6f317d70ca178fd3731fe3346a6e4589ad', '2x2_transparent_halfblock.png', 12, null, 'atkinson', 256],
            'few colours, no dither' => ['84610010b5e8708a6a58b464658afc50593be96f', '500x400_noise.png', 17, null, 'none', 16],
            'aspect-derived height' => ['cba4a1239583bc5108ca41104e511079773721b6', '8x4_red.png', 13, null, 'floyd-steinberg', 256],
            'tall cell box' => ['aa3e43fa4ad1f7e34db6685a683f23adb1af9464', '500x400_noise.png', 9, 7, 'none', 256],
            'gradient, no dither' => ['c65c8465e501645f8eb6c76bb46a97cf38e948d0', 'gradient_64x64.png', 16, 12, 'none', 256],
            'gradient, floyd-steinberg' => ['47d7e3d7b2e1a1a3e21e363f836d6d03410987f8', 'gradient_64x64.png', 16, 12, 'floyd-steinberg', 256],
        ];
    }

    #[DataProvider('bandDiffCorpus')]
    public function testBandDiffGuardKeepsRepresentativeBytesFrozen(
        string $digest, string $file, int $width, ?int $height, string $dither, int $maxColors,
    ): void {
        $renderer = new SixelRenderer(Dither::from($dither), $maxColors);
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/' . $file);

        $this->assertSame($digest, sha1($renderer->render($image, $width, $height)), $file . ' ' . $dither);
        $this->assertSame($digest, sha1(implode('', $this->collect($renderer, $image, $width, $height))), 'streamed concat rides the same bytes');
    }

    /**
     * F-14: the PaletteIndex (Euclidean) that now answers the B-13 memo
     * misses must agree with the retired linear nearestColor walk on EVERY
     * pixel of a fixed synthetic palette sweep — including the lowest-index
     * tie leg (a duplicated palette entry sits equidistant from probe (5,0,0)).
     * This is the differential proof that the nearest-path fold changed no
     * index, hence no byte.
     */
    public function testPaletteIndexAnswersTheOldBruteForceWalk(): void
    {
        $palette = [[250, 0, 0], [255, 7, 7], [0, 0, 0], [10, 0, 0], [0, 20, 0], [0, 0, 30]];
        for ($i = 0; $i < 18; $i++) {
            $palette[] = [$i * 14 & 0xFF, ($i * 37 + 8) & 0xFF, ($i * 91 + 200) & 0xFF];
        }
        $palette[] = [10, 0, 0]; // duplicate of index 3 → forced tie at (5,0,0)

        $index = PaletteIndex::fromPalette(
            array_map(static fn(array $rgb): Color => new Color($rgb[0], $rgb[1], $rgb[2]), $palette),
            ColorDistance::Euclidean,
        );

        // Exact tie legs: at (5,0,0) the black and the red edge sit both 25
        // away — lowest index (the black at 2) must answer. At (10,0,0) the
        // duplicate of index 3 is equidistant (0) — index 3 still answers,
        // the duplicate at 12 must not.
        $this->assertSame(2, self::bruteNearest(5, 0, 0, $palette));
        $this->assertSame(2, $index->nearest(new Color(5, 0, 0)), 'lowest index wins exact ties');
        $this->assertSame(3, self::bruteNearest(10, 0, 0, $palette));
        $this->assertSame(3, $index->nearest(new Color(10, 0, 0)), 'duplicate entry never steals its own index');

        $seed = 12345;
        for ($q = 0; $q < 512; $q++) {
            $seed = ($seed * 1103515245 + 12345) % 2147483648;
            $r = ($seed >> 16) & 0xFF;
            $g = ($seed >> 8) & 0xFF;
            $b = $seed & 0xFF;
            $this->assertSame(
                self::bruteNearest($r, $g, $b, $palette),
                $index->nearest(new Color($r, $g, $b)),
                'LCG probe ' . $q . " ($r,$g,$b) diverged",
            );
        }
    }

    /**
     * F-14: in-test replica of the retired private nearestColor walk —
     * integer squared-Euclidean, strict < so the lowest index wins ties.
     *
     * @param list<array{int,int,int}> $palette
     */
    private static function bruteNearest(int $r, int $g, int $b, array $palette): int
    {
        $best     = 0;
        $bestDist = PHP_INT_MAX;
        foreach ($palette as $i => $entry) {
            $dr   = $r - $entry[0];
            $dg   = $g - $entry[1];
            $db   = $b - $entry[2];
            $dist = $dr * $dr + $dg * $dg + $db * $db;
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best     = $i;
            }
        }
        return $best;
    }
}
