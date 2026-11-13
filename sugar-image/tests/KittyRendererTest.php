<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\KittyRenderer;

final class KittyRendererTest extends TestCase
{
    private KittyRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new KittyRenderer();
    }

    public function testName(): void
    {
        $this->assertSame('kitty', $this->renderer->name());
    }

    public function testSupportsAlpha(): void
    {
        $this->assertTrue($this->renderer->supportsAlpha());
    }

    public function testRendersKittyBeginSequence(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8);

        // Begins with APC G (Kitty graphics begin) + the transmit action and
        // width/height params, and the begin frame opens the transaction with
        // m=1 (ANSI audit: the old DCS `ESC P q` header was DECSIXEL and never
        // activated Kitty graphics). The 8x4 source is 2:1, so an 8-cell box
        // is 2 cell rows deep once the ~2:1 cell shape is honoured.
        $this->assertStringStartsWith("\x1b_Ga=T,c=8,r=2,f=100,m=1;\x1b\\", $out);
        $this->assertStringContainsString('c=8', $out);    // cell columns
        $this->assertStringContainsString('r=2', $out);    // cell rows
    }

    /**
     * The plain route (what {@see \SugarCraft\Image\Mosaic::kitty()} and
     * auto() hand out) must carry the SAME transmit action as the options
     * route. It used to emit `c=,r=,f=,m=1` with no `a` at all, which the
     * published Kitty spec resolves to the transmit-without-display action:
     * the payload stored and nothing ever composited.
     */
    public function testPlainTransmitCarriesTheSameActionAsTheOptionsRoute(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $plain  = $this->renderer->render($source, 8);
        $viaOpts = $this->renderer->renderWithOptions(
            $source,
            8,
            null,
            \SugarCraft\Image\KittyOptions::transmit(),
        );

        $beginOf = static function (string $frame): string {
            $end = strpos($frame, "\x1b\\");

            return $end === false ? $frame : substr($frame, 0, $end + 2);
        };

        $plainBegin = $beginOf($plain);
        $optsBegin  = $beginOf($viaOpts);

        $this->assertStringStartsWith("\x1b_Ga=T,", $plainBegin, 'plain route must state the action');
        $this->assertStringStartsWith("\x1b_Ga=T,", $optsBegin);

        // Same action key, same value, in the same position — the two routes
        // differ only in the extras the options path adds (i=, q=).
        $actionOf = static function (string $begin): string {
            preg_match('/\x1b_G([^;]*)/', $begin, $m);
            $pairs = [];
            foreach (explode(',', $m[1] ?? '') as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, null);
                $pairs[$k] = $v;
            }

            return ($pairs['a'] ?? '<absent>') === 'T' ? 'T' : '<absent>';
        };

        $this->assertSame('T', $actionOf($plainBegin));
        $this->assertSame($actionOf($optsBegin), $actionOf($plainBegin));
    }

    /**
     * The generator route (paced pumping) shares one byte source with
     * render(), so the action key must appear in the first frame it yields.
     */
    public function testRenderRowsFirstFrameCarriesTheTransmitAction(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $rows   = $this->renderer->renderRows($source, 8);
        $rows->rewind();

        $this->assertStringStartsWith("\x1b_Ga=T,c=8,r=2,f=100,m=1;\x1b\\", $rows->current());
        $this->assertSame(implode('', iterator_to_array($this->renderer->renderRows($source, 8), false)), $this->renderer->render($source, 8));
    }

    public function testRendersKittyEndSequence(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8);

        // Every Kitty frame — begin, each chunk — is a self-contained APC
        // sequence ending in ST (String Terminator).
        $this->assertStringEndsWith("\x1b\\", $out);
        // The transaction is closed by the final data chunk's m=0, exactly
        // once: no dangling open frame, no duplicate end frames.
        $this->assertSame(1, substr_count($out, 'm=0;'));
        // Frame-structural split: every `ESC _ G` segment up to ST carries
        // only key=value/; characters — no raw payload leaked outside a frame.
        $frames = explode("\x1b_G", $out);
        $this->assertSame('', array_shift($frames), 'output must start with the APC G introducer');
        foreach ($frames as $frame) {
            $this->assertStringEndsWith("\x1b\\", $frame);
            $body = substr($frame, 0, -2);
            $sep = strpos($body, ';');
            $this->assertIsInt($sep, "frame has no data separator: {$body}");
            $attrs = substr($body, 0, $sep);
            $data = substr($body, $sep + 1);
            // Value alphabet is case-inclusive: the action's spec values are
            // uppercase (T) while the rest are lowercase.
            $this->assertMatchesRegularExpression('/^[a-z]=[A-Za-z0-9]+(,[a-z]=[A-Za-z0-9]+)*$/', $attrs);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]*$/', $data);
        }
    }

    public function testPayloadIsBase64Png(): void
    {
        // ImageSource::fromFile re-encodes via GD, so use that re-encoded PNG bytes.
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 8);

        $b64 = base64_encode($source->bytes);
        $this->assertStringContainsString($b64, $out);
    }

    public function testEffectiveHeightComputedFromAspectRatio(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        // Source aspect ratio = 2 (8/4); cells are ~2:1 tall, so width=10
        // derives round(10 / (2·2)) = 3 (rounded up from 2.5) rows.
        $out = $this->renderer->render($source, 10);

        $this->assertStringContainsString('c=10', $out);
        $this->assertStringContainsString('r=3', $out);
    }

    public function testExplicitHeightOverridesComputed(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out    = $this->renderer->render($source, 10, 7);

        $this->assertStringContainsString('c=10', $out);
        $this->assertStringContainsString('r=7', $out);
    }

    public function testZeroWidthThrows(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($source, 0);
    }

    public function testNegativeWidthThrows(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($source, -1);
    }

    public function testZeroHeightThrows(): void
    {
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($source, 8, 0);
    }

    public function testWithOptionsDoorSharesTheWidthHeightLaw(): void
    {
        // SI-24 (finding 018): renderWithOptions() predated the validation
        // trait and re-derived the cell geometry by itself — without the
        // guards — so width=0 emitted a spec-invalid c=0 transmit frame and
        // a bogus height rode the r=1 rescue into an c/r-mismatched payload
        // where render() simply refused. One law, both doors, identical
        // throw grammar.
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        foreach ([[0, null], [-1, null], [8, 0], [8, -1]] as [$w, $h]) {
            $plain = $this->captureThrow(fn (): string => $this->renderer->render($source, $w, $h));
            $typed = $this->captureThrow(fn (): string => $this->renderer->renderWithOptions($source, $w, $h, \SugarCraft\Image\KittyOptions::transmit()));

            $this->assertInstanceOf(\InvalidArgumentException::class, $plain, "render() must refuse width={$w} height=" . var_export($h, true));
            $this->assertInstanceOf(\InvalidArgumentException::class, $typed, "renderWithOptions() must refuse width={$w} height=" . var_export($h, true));
            $this->assertSame($plain->getMessage(), $typed->getMessage(), 'the two doors share one error grammar');
        }
    }

    private function captureThrow(callable $fn): ?\Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }

        $this->fail('expected the geometry law to bite');
    }

    public function testChunkedTransmissionForLargeImage(): void
    {
        // 500×400 noise PNG produces > 4092 bytes of base64 data, exercising chunking.
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/500x400_noise.png');
        $out    = $this->renderer->render($source, 50, 40);

        // Intermediate chunk sets m=1 (more data follows), framed as its
        // own APC sequence with the payload between ';' and ST.
        $this->assertStringContainsString("\x1b_Gm=1;", $out);
        // Final chunk sets m=0 (closes the transaction) — last frame's
        // terminator is the very end of the output.
        $this->assertStringContainsString("\x1b_Gm=0;", $out);
    }
}
