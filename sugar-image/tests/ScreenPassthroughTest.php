<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Passthrough\PassthroughDecorator;
use SugarCraft\Image\Passthrough\ScreenPacketizer;
use SugarCraft\Image\Passthrough\ScreenPassthrough;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Image\Renderer\SixelRenderer;
use SugarCraft\Image\TermInfo;
use SugarCraft\Image\TmuxPassthroughDecorator;

/**
 * GNU Screen passthrough envelope — packetization at the 200-byte
 * cap with no ESC-doubling, plus the byte-wise ST workaround.
 *
 * Byte expectations are computed inline (zero-golden law), each shaped by
 * the chicle encoder's SCREEN branch: `ESC P` payload≤200 `ESC \`, and an
 * ST-terminated run additionally emits
 * `ESC P ESC ESC \ ESC P \ ESC \` (end_sixels, chafa-sixel-renderer.c).
 */
final class ScreenPassthroughTest extends TestCase
{
    private const B = "\x1b" . 'P';
    private const E = "\x1b\\";
    private const ST_WORKAROUND = self::B . "\x1b" . self::E . self::B . '\\' . self::E;

    public function testPlainTextAndCsiSgrPassThroughUntouched(): void
    {
        $sp = $this->decorator();

        $this->assertSame('hello world', $sp->wrap('hello world'));
        $this->assertSame("\x1b[31ma\x1b[0m b\x1b[2J", $sp->wrap("\x1b[31ma\x1b[0m b\x1b[2J"));
    }

    public function testDcsRunIsPacketizedThenTerminatedByteWise(): void
    {
        $out = $this->decorator()->wrap("\x1bPq64;1\x1b\\post");

        // Payload = the whole run minus ST, introducer included, undoubled.
        $this->assertSame(
            self::B . "\x1bPq64;1" . self::E . self::ST_WORKAROUND . 'post',
            $out,
        );
    }

    public function testPacketBoundariesAtTheCap198199200201(): void
    {
        // Payload region = 2-byte introducer + N filler, so packets roll at
        // exactly N = 198 (the 200th byte) per chicle append_packetized.
        foreach ([197 => 'single short packet', 198 => 'exact full packet'] as $n => $why) {
            $payload = "\x1bP" . str_repeat('a', $n);
            $expected = self::B . $payload . self::E . self::ST_WORKAROUND;
            $this->assertSame(
                $expected,
                $this->decorator()->wrap($payload . "\x1b\\"),
                $why,
            );
        }

        $payload = "\x1bP" . str_repeat('a', 199);
        $expected =
            self::B . substr($payload, 0, 200) . self::E
            . self::B . substr($payload, 200) . self::E
            . self::ST_WORKAROUND;
        $this->assertSame($expected, $this->decorator()->wrap($payload . "\x1b\\"), 'one byte spills to a second packet');

        $payload = "\x1bP" . str_repeat('a', 399);
        $expected =
            self::B . substr($payload, 0, 200) . self::E
            . self::B . substr($payload, 200, 200) . self::E
            . self::B . substr($payload, 400) . self::E
            . self::ST_WORKAROUND;
        $this->assertSame($expected, $this->decorator()->wrap($payload . "\x1b\\"), 'two full packets then a one-byte tail');
    }

    public function testOscRunTerminatedByBelCarriesTheBelAsPayload(): void
    {
        $out = $this->decorator()->wrap("\x1b]0;title\x07tail");
        $this->assertSame(self::B . "\x1b]0;title\x07" . self::E . 'tail', $out);
    }

    public function testOscRunTerminatedByStGetsTheWorkaround(): void
    {
        $out = $this->decorator()->wrap("\x1b]11;rgb:0000/0000/0000\x1b\\");
        $this->assertSame(self::B . "\x1b]11;rgb:0000/0000/0000" . self::E . self::ST_WORKAROUND, $out);
    }

    public function testBelInsideDcsIsDataNotATerminator(): void
    {
        // DCS/APC runs end at ST only (documented divergence from the tmux
        // decorator's BEL fallback — chicle's fallback cannot be honoured
        // incrementally, and no real DCS uses BEL).
        $out = $this->decorator()->wrap("\x1bPab\x07cd\x1b\\");
        $this->assertSame(self::B . "\x1bPab\x07cd" . self::E . self::ST_WORKAROUND, $out);
    }

    public function testApcRunIsAlsoWrapped(): void
    {
        $out = $this->decorator()->wrap("\x1b_Gi=1,a=T;75=\x1b\\x");
        $this->assertSame(self::B . "\x1b_Gi=1,a=T;75=" . self::E . self::ST_WORKAROUND . 'x', $out);
    }

    public function testTruncatedRunFlushesOneOpenPacketAtEnd(): void
    {
        // chicle encoder_end flushes: no ST means no workaround.
        $out = $this->decorator()->wrap("\x1bPabc");
        $this->assertSame(self::B . "\x1bPabc" . self::E, $out);
    }

    public function testTwoRunsShareNoStateAcrossBoundaries(): void
    {
        $out = $this->decorator()->wrap("\x1bPA\x1b\\\x1b]B\x07");
        $this->assertSame(
            self::B . "\x1bPA" . self::E . self::ST_WORKAROUND . self::B . "\x1b]B\x07" . self::E,
            $out,
        );
    }

    public function testPacketizerSplitsAByteSplitTerminatorAcrossChunks(): void
    {
        // The hold-a-trailing-ESC path: ST arriving as "\x1b" | "\" must
        // still produce the workaround, not packetize ESC as payload.
        $m = new ScreenPacketizer();
        $m->feed("\x1bPq#0;2;0");
        $m->feed("\x1b");
        $m->feed("\\");
        $this->assertSame(
            self::B . "\x1bPq#0;2;0" . self::E . self::ST_WORKAROUND,
            $m->finish(),
        );
    }

    public function testStreamIsByteIdenticalToWrapOnSixel(): void
    {
        $raw = (new SixelRenderer(Dither::None))->render($this->redBlock(16, 16), 16);
        $sp = $this->decorator();

        $chunks = [];
        $sp->encodeBandStream($this->redBlock(16, 16), 16, static function (string $c) use (&$chunks): void {
            $chunks[] = $c;
        });

        $this->assertSame($sp->wrap($raw), implode('', $chunks));
        $this->assertStringStartsWith(self::B . "\x1bP", $chunks[0], "stream opens with the enveloped DCS header");
    }

    public function testStreamKeepsTheParkingNewlineOutsideTheEnvelope(): void
    {
        // chicle writes the parking newline raw, after end_sixels; our
        // wrap/stream equivalence machine reproduces that exactly: bytes
        // after the run's ST are copied, not packetized.
        $inner = (new SixelRenderer(Dither::None))->withQuirks(TermInfo::QUIRK_NONE);
        $raw = $inner->render($this->redBlock(16, 16), 16);
        $this->assertStringEndsWith("\x1b\\\n", $raw);

        $sp = new ScreenPassthrough($inner);
        $chunks = [];
        $sp->encodeBandStream($this->redBlock(16, 16), 16, static function (string $c) use (&$chunks): void {
            $chunks[] = $c;
        });

        $stream = implode('', $chunks);
        $this->assertStringEndsWith(self::ST_WORKAROUND . "\n", $stream);
        $this->assertSame($sp->render($this->redBlock(16, 16), 16), $stream);
    }

    public function testDelegationSurface(): void
    {
        $sp = new ScreenPassthrough(new SixelRenderer());

        $this->assertSame('screen(sixel)', $sp->name());
        $this->assertTrue($sp->supportsAlpha());
        $this->assertFalse($sp->isInline());
        $this->assertInstanceOf(SixelRenderer::class, $sp->inner());
    }

    public function testStreamingRefusesNonSixelInner(): void
    {
        $sp = new ScreenPassthrough(new KittyRenderer());

        $this->expectException(\LogicException::class);
        $sp->encodeBandStream($this->redBlock(2, 2), 2, static function (): void {});
    }

    public function testBothDecoratorsImplementTheSharedInterface(): void
    {
        $tmux = new TmuxPassthroughDecorator(new SixelRenderer());
        $screen = new ScreenPassthrough(new SixelRenderer());

        $this->assertInstanceOf(PassthroughDecorator::class, $tmux);
        $this->assertInstanceOf(PassthroughDecorator::class, $screen);

        // decorate() re-wraps in the decorator's OWN flavour — the re-wrap
        // sites Mosaic uses after configuring an inner renderer.
        $retuned = $screen->decorate(new SixelRenderer(Dither::Atkinson));
        $this->assertInstanceOf(ScreenPassthrough::class, $retuned);
        $this->assertSame('screen(sixel)', $retuned->name());
        $this->assertSame(
            'tmux(sixel)',
            $tmux->decorate(new SixelRenderer())->name(),
        );
    }

    private function decorator(): ScreenPassthrough
    {
        return new ScreenPassthrough(new SixelRenderer());
    }

    private function redBlock(int $w = 2, int $h = 2): ImageSource
    {
        $gd = imagecreatetruecolor($w, $h);
        $this->assertNotFalse($gd);
        $red = imagecolorallocate($gd, 255, 0, 0);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                imagesetpixel($gd, $x, $y, $red);
            }
        }
        return ImageSource::fromGd($gd, 'image/png');
    }
}
