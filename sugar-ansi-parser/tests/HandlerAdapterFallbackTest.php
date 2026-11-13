<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Parser\CsiHandler;
use SugarCraft\AnsiParser\Parser\Handler;
use SugarCraft\AnsiParser\Parser\HandlerAdapter;
use SugarCraft\AnsiParser\Parser\OscHandler;
use SugarCraft\AnsiParser\Parser\RecordingOscHandler;
use SugarCraft\AnsiParser\Parser\Parser;

/**
 * The optional fallback Handler recovers everything the adapter's sieve
 * drops: the ESC family, DCS, SOS/PM/APC strings, unmapped CSI finals, and
 * unhandled control bytes. The SCO-gated `CSI ? s` / `CSI ? u` discard is
 * deliberately NOT recovered — forwarding it would re-open the cursor-
 * teleport defect the gate exists to close.
 *
 * Also pins the OSC 8 additions: the xterm HVP backtick spelling, and the
 * fail-safe CLOSE for a malformed `8;…` payload (a swallowed close used to
 * bleed following text into a stale link).
 */
final class HandlerAdapterFallbackTest extends TestCase
{
    private CsiHandler $csi;
    private OscHandler $osc;
    private Handler $fallback;
    private HandlerAdapter $adapter;
    private Parser $parser;

    protected function setUp(): void
    {
        $this->csi = $this->createMock(CsiHandler::class);
        $this->osc = $this->createMock(OscHandler::class);
        $this->fallback = $this->createMock(Handler::class);
        $this->adapter = new HandlerAdapter($this->csi, $this->osc, $this->fallback);
        $this->parser = new Parser($this->adapter);
    }

    public function testUnhandledControlByteReachesFallback(): void
    {
        $this->fallback->expects($this->once())->method('execute')->with(0x07);

        $this->parser->feed("\x07"); // BEL — not one of the four routed executes
    }

    public function testRoutedControlByteDoesNotReachFallback(): void
    {
        $this->csi->expects($this->once())->method('cht')->with(1);
        $this->fallback->expects($this->never())->method('execute');

        $this->parser->feed("\x09"); // HT — consumed by the sieve
    }

    public function testUnmappedCsiFinalReachesFallback(): void
    {
        // HPA `CSI 5 G` — in the reference set, not in CsiHandler's working set.
        $this->fallback->expects($this->once())
            ->method('csiDispatch')
            ->with(ord('G'), [5], 0, 0);
        $this->csi->expects($this->never())->method('cup');

        $this->parser->feed("\x1b[5G");
    }

    public function testMappedCsiFinalDoesNotReachFallback(): void
    {
        $this->csi->expects($this->once())->method('cuu')->with(3);
        $this->fallback->expects($this->never())->method('csiDispatch');

        $this->parser->feed("\x1b[3A");
    }

    public function testScoGatedPrivateCursorQueryIsConsideredConsumed(): void
    {
        // `CSI ? u` (kitty keyboard capability query) must reach NEITHER the
        // cursor methods nor the fallback — forwarding it would teleport the
        // render cursor through any fallback that maps unmarked `u` to SCORC.
        $this->csi->expects($this->never())->method('scorc');
        $this->fallback->expects($this->never())->method('csiDispatch');

        $this->parser->feed("\x1b[?u");
    }

    public function testScoGatedPrivateSaveIsConsideredConsumed(): void
    {
        $this->csi->expects($this->never())->method('scosc');
        $this->fallback->expects($this->never())->method('csiDispatch');

        $this->parser->feed("\x1b[?s");
    }

    public function testEscFamilyAlwaysReachesFallback(): void
    {
        // ESC M (RI) — the adapter models no ESC sequences.
        $this->fallback->expects($this->once())
            ->method('escDispatch')
            ->with(ord('M'), 0);

        $this->parser->feed("\x1bM");
    }

    public function testDcsFamilyAlwaysReachesFallback(): void
    {
        $this->fallback->expects($this->once())
            ->method('dcsDispatch')
            ->with(ord('m'), [1, 2, 3], 0, 0, '');

        $this->parser->feed("\x1bP1;2;3m\x1b\\");
    }

    public function testSosPmApcAlwaysReachesFallback(): void
    {
        $this->fallback->expects($this->once())
            ->method('sosPmApcDispatch')
            ->with('sos', 'test');

        $this->parser->feed("\x98test\x9c"); // C1 SOS introducer, C1 ST terminator
    }

    public function testUnknownOscCommandReachesFallback(): void
    {
        $this->fallback->expects($this->once())->method('oscDispatch')->with('4;color');
        $this->osc->expects($this->never())->method('title');

        $this->parser->feed("\x1b]4;color\x07");
    }

    public function testCupClampsZeroToFirstRowAndColumn(): void
    {
        // ECMA 48 CUP: an omitted OR zero subparameter is replaced by 1.
        $this->csi->expects($this->once())->method('cup')->with(1, 1);

        $this->parser->feed("\x1b[0;0H");
    }

    public function testHvpClampsZeroToFirstRowAndColumn(): void
    {
        $this->csi->expects($this->once())->method('hvp')->with(1, 1);

        $this->parser->feed("\x1b[0;0f");
    }

    public function testCupKeepsRealPositionsUntouched(): void
    {
        $this->csi->expects($this->once())->method('cup')->with(10, 20);

        $this->parser->feed("\x1b[10;20H");
    }

    public function testMalformedOscEightClosesTheLink(): void
    {
        // `8;foo` has neither the second `;` nor the HVP backtick: it used to
        // be silently dropped, leaving any open link open. It now closes.
        $oscImpl = new RecordingOscHandler();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl, $this->fallback);
        $parser = new Parser($adapter);

        $parser->feed("\x1b]8;id=x;https://example.com\x07");
        $this->assertSame('https://example.com', $oscImpl->hyperlinkUri());

        $parser->feed("\x1b]8;foo\x07");

        $this->assertSame('', $oscImpl->hyperlinkUri(), 'malformed OSC 8 closes the stale link');
        $this->assertSame('', $oscImpl->hyperlinkId());
        // The close is consumed by the sieve — the fallback must not also see it.
        $this->fallback->expects($this->never())->method('oscDispatch');
    }

    public function testHvpBacktickSpellingOpensWithUriAndEmptyId(): void
    {
        // xterm HVP without a params field: `ESC ] 8 ; uri ` ` `.
        $this->osc->expects($this->once())
            ->method('hyperlink')
            ->with('https://example.com', '');

        $this->adapter->oscDispatch("8;https://example.com`");
    }

    public function testHvpBacktickWithParamsLosesNoBacktickIntoTheUri(): void
    {
        // The backtick terminates the sequence; it must not end up inside the URI.
        $this->osc->expects($this->once())
            ->method('hyperlink')
            ->with('https://example.com', 'a');

        $this->adapter->oscDispatch("8;id=a;https://example.com`");
    }

    public function testWithoutFallbackTheSieveDropsSilently(): void
    {
        // Null fallback = exactly the pre-existing behaviour: unmapped final
        // and unhandled control byte fire nothing.
        $bare = new HandlerAdapter($this->csi, $this->osc);

        $this->csi->expects($this->never())->method('cup');
        $this->fallback->expects($this->never())->method('execute');

        $bare->execute(0x07);
        $bare->csiDispatch(ord('G'), [5], 0, 0);
    }

    public function testWiredNamedConstructorObservesEveryFamilyBareNewDrops(): void
    {
        // Finding sugar-ansi-parser-007, arm a: wired() makes the fallback a
        // required argument. One stream through the real parser covers all
        // four families the bare shape loses silently — BEL, the ESC family,
        // DCS, SOS — while the sieve-consumed CSI leg keeps routing to the
        // shared CsiHandler side, so wired() is behaviourally the same
        // adapter, only the shape is enforced.
        $csi = $this->createMock(CsiHandler::class);
        $osc = $this->createMock(OscHandler::class);
        $fallback = $this->createMock(Handler::class);

        // escDispatch sees TWO finals on this stream, in feed order: 77 (the
        // ESC M leg) and 92 (the backslash of the DCS string-terminator —
        // the ESC-abort dispatches the in-flight DCS first, then the
        // trailing backslash of ST itself lands as an ESC final: the machine
        // treats ESC inside a string as introducer+final, sap-07 contract).
        $escSeen = [];
        $fallback->expects($this->once())->method('execute')->with(0x07);
        $fallback->expects($this->exactly(2))->method('escDispatch')->willReturnCallback(
            static function (int $final, int $intermediate) use (&$escSeen): void {
                $escSeen[] = [$final, $intermediate];
            }
        );
        $fallback->expects($this->once())->method('dcsDispatch')->with(ord('m'), [1], 0, 0, '');
        $fallback->expects($this->once())->method('sosPmApcDispatch')->with('sos', 'x');
        $csi->expects($this->once())->method('cuu')->with(3);

        $parser = new Parser(HandlerAdapter::wired($csi, $osc, $fallback));
        $parser->feed("\x07\x1bM\x1bP1m\x1b\\\x98x\x9c\x1b[3A");

        $this->assertSame([[77, 0], [92, 0]], $escSeen, 'ESC M, then the ST backslash final after the DCS abort-dispatch');
    }

    public function testWithFallbackReturnsANewAdapterAndLeavesTheOriginalAlone(): void
    {
        // Finding sugar-ansi-parser-007, arm b: the swap constructs rather
        // than mutates. A trace-tagged pair of fallbacks plus a shared CSI
        // side pins all three properties at once: the derived adapter routes
        // the dropped family ONLY to the new fallback, the consumed family
        // still lands on the same CsiHandler object, and the original keeps
        // its own fallback after the derived was created.
        $csi = $this->createMock(CsiHandler::class);
        $osc = $this->createMock(OscHandler::class);
        $old = $this->createMock(Handler::class);
        $new = $this->createMock(Handler::class);

        $trace = [];
        $old->method('escDispatch')->willReturnCallback(
            static function (int $final) use (&$trace): void {
                $trace[] = 'old:esc' . $final;
            }
        );
        $new->method('escDispatch')->willReturnCallback(
            static function (int $final) use (&$trace): void {
                $trace[] = 'new:esc' . $final;
            }
        );
        $csi->method('cuu')->willReturnCallback(
            static function (int $n) use (&$trace): void {
                $trace[] = 'csi:cuu' . $n;
            }
        );

        $original = new HandlerAdapter($csi, $osc, $old);
        $derived = $original->withFallback($new);
        $this->assertNotSame($original, $derived);

        $derivedParser = new Parser($derived);
        $derivedParser->feed("\x1bM");   // dropped family -> only the new fallback
        $derivedParser->feed("\x1b[3A"); // consumed family -> the shared csi side

        (new Parser($original))->feed("\x1bM"); // original still on the old one

        $this->assertSame(['new:esc77', 'csi:cuu3', 'old:esc77'], $trace);
    }
}
