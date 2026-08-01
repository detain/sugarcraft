<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\CsiHandler;
use SugarCraft\Ansi\Parser\DebugHandler;
use SugarCraft\Ansi\Parser\HandlerAdapter;
use SugarCraft\Ansi\Parser\OscHandler;
use SugarCraft\Ansi\Parser\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Tests to maximize code coverage for edge cases and uncovered paths.
 */
final class CoverageMaximizerTest extends TestCase
{
    /**
     * Test interrupted UTF-8 sequence with replaceMalformed=false.
     * When a non-continuation byte interrupts a UTF-8 sequence and
     * replaceMalformed is false, the incomplete rune is silently dropped.
     * This exercises lines 213-220 in Parser.php.
     */
    public function testInterruptedUtf8WithReplaceMalformedFalse(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, false);

        // \xc3 is a 2-byte UTF-8 lead (needs one continuation)
        // 'A' (0x41) is not a continuation byte (0x80-0xBF)
        $parser->feed("\xc3A");

        $prints = $handler->filter('print');
        // Only 'A' should be printed; the incomplete \xc3 is dropped
        $this->assertCount(1, $prints);
        $this->assertSame('A', $prints[0]['detail']);
    }

    /**
     * Test that with replaceMalformed=true, interrupted UTF-8 emits replacement.
     * This exercises lines 214-216 in Parser.php (the replaceMalformed branch).
     */
    public function testInterruptedUtf8WithReplaceMalformedTrue(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        // \xc3 is a 2-byte UTF-8 lead; 'A' interrupts
        $parser->feed("\xc3A");

        $prints = $handler->filter('print');
        // Replacement character for interrupted rune, then 'A'
        $this->assertCount(2, $prints);
        $this->assertSame("\xEF\xBF\xBD", $prints[0]['detail']);
        $this->assertSame('A', $prints[1]['detail']);
    }

    /**
     * Test that three-byte UTF-8 lead interrupted by non-continuation emits replacement.
     */
    public function testThreeByteUtf8InterruptedWithReplaceMalformedTrue(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        // \xe2\x82\xac is Euro sign; 'B' interrupts after first byte
        $parser->feed("\xe2B");

        $prints = $handler->filter('print');
        // Should have replacement for interrupted rune + 'B'
        $this->assertCount(2, $prints);
        $this->assertSame("\xEF\xBF\xBD", $prints[0]['detail']);
        $this->assertSame('B', $prints[1]['detail']);
    }

    /**
     * Test that four-byte UTF-8 lead interrupted by non-continuation emits replacement.
     */
    public function testFourByteUtf8InterruptedWithReplaceMalformedTrue(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        // \xf0\x9f\x98\x80 is emoji; 'C' interrupts after first byte
        $parser->feed("\xf0C");

        $prints = $handler->filter('print');
        $this->assertCount(2, $prints);
        $this->assertSame("\xEF\xBF\xBD", $prints[0]['detail']);
        $this->assertSame('C', $prints[1]['detail']);
    }

    /**
     * Test that isValidUtf8Rune correctly validates valid 2-byte sequences.
     * Uses mb_check_encoding path at line 180.
     */
    public function testValidTwoByteUtf8PassesThrough(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, false);

        // Valid 2-byte UTF-8: \xc3\xa9 = é
        $parser->feed("\xc3\xa9");

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints);
        $this->assertSame('é', $prints[0]['detail']);
    }

    /**
     * Test valid 3-byte UTF-8 sequence.
     */
    public function testValidThreeByteUtf8PassesThrough(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, false);

        // Valid 3-byte UTF-8: \xe2\x82\xac = €
        $parser->feed("\xe2\x82\xac");

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints);
        $this->assertSame('€', $prints[0]['detail']);
    }

    /**
     * Test valid 4-byte UTF-8 sequence.
     */
    public function testValidFourByteUtf8PassesThrough(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, false);

        // Valid 4-byte UTF-8: \xf0\x9f\x98\x80 = 😀
        $parser->feed("\xf0\x9f\x98\x80");

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints);
        $this->assertSame('😀', $prints[0]['detail']);
    }

    /**
     * Test that escDispatch on HandlerAdapter is actually invoked.
     * Uses DebugHandler to verify the method was called.
     */
    public function testEscDispatchIsCalledForEscapeSequence(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // ESC D (IND - Index) triggers escDispatch
        $parser->feed("\x1bD");

        $esc = $handler->filter('esc');
        $this->assertNotEmpty($esc, 'escDispatch should be called for ESC D');
        $this->assertSame(ord('D'), $esc[0]['detail']['final']);
    }

    /**
     * Test that sosPmApcDispatch is called for SOS sequences.
     * SOS starts with ESC X and ends with ST (ESC \).
     */
    public function testSosPmApcDispatchIsCalledForSos(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // SOS: ESC X ... ST
        $parser->feed("\x1bXsos content\x1b\\");

        $sos = $handler->filter('sos');
        $this->assertNotEmpty($sos, 'sosPmApcDispatch should be called for SOS');
        $this->assertSame('sos content', $sos[0]['detail']);
    }

    /**
     * Test PM (Privacy Message) sequence.
     */
    public function testSosPmApcDispatchIsCalledForPm(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // PM: ESC ^ ... ST
        $parser->feed("\x1b^pm content\x1b\\");

        $pm = $handler->filter('pm');
        $this->assertNotEmpty($pm, 'sosPmApcDispatch should be called for PM');
        $this->assertSame('pm content', $pm[0]['detail']);
    }

    /**
     * Test APC (Application Program Command) sequence.
     */
    public function testSosPmApcDispatchIsCalledForApc(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // APC: ESC _ ... ST
        $parser->feed("\x1b_apc content\x1b\\");

        $apc = $handler->filter('apc');
        $this->assertNotEmpty($apc, 'sosPmApcDispatch should be called for APC');
        $this->assertSame('apc content', $apc[0]['detail']);
    }

    /**
     * Test that dcsDispatch is called for DCS sequences via DebugHandler.
     * DCS starts with ESC P and ends with ST (ESC \).
     */
    public function testDcsDispatchIsCalledForDcsSequence(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // DCS: ESC P ... ST
        $parser->feed("\x1bP1;2;3mydata\x1b\\");

        $dcs = $handler->filter('dcs');
        $this->assertNotEmpty($dcs, 'dcsDispatch should be called for DCS');
        $this->assertSame(ord('m'), $dcs[0]['detail']['final']);
        $this->assertSame([1, 2, 3], $dcs[0]['detail']['params']);
    }

    /**
     * Test parseHyperlinkId with multiple key-value pairs where id is not first.
     * This exercises the loop in parseHyperlinkId that skips non-id pairs.
     */
    public function testParseHyperlinkIdSkipsNonIdParams(): void
    {
        $oscImpl = new \SugarCraft\Ansi\Parser\OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser = new Parser($adapter);

        // Params field has "foo=bar:id=myid" - id is second pair
        $parser->feed("\x1b]8;foo=bar:id=myid;https://example.com\x07");

        $this->assertSame('myid', $oscImpl->hyperlinkId());
    }

    /**
     * Test parseHyperlinkId with id that has empty value.
     */
    public function testParseHyperlinkIdWithEmptyIdValue(): void
    {
        $oscImpl = new \SugarCraft\Ansi\Parser\OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser = new Parser($adapter);

        // id= with empty value
        $parser->feed("\x1b]8;id=;https://example.com\x07");

        $this->assertSame('', $oscImpl->hyperlinkId());
    }

    /**
     * Test HandlerAdapter::execute with a byte that hits the default case.
     * Bytes 0x09 (TAB), 0x0A (LF), 0x0D (CR), 0x08 (BS) are handled.
     * Other bytes like 0x00 (NUL) should hit default => null.
     */
    public function testExecuteDefaultCaseHit(): void
    {
        $csi = $this->createMock(CsiHandler::class);
        $csi->expects($this->never())->method('printable');
        $csi->expects($this->never())->method('cub');
        $csi->expects($this->never())->method('cud');
        $csi->expects($this->never())->method('cuf');
        $csi->expects($this->never())->method('cuu');
        $csi->expects($this->never())->method('cht');
        $csi->expects($this->never())->method('cr');
        $csi->expects($this->never())->method('lf');

        $osc = $this->createMock(OscHandler::class);
        $adapter = new HandlerAdapter($csi, $osc);
        $parser = new Parser($adapter);

        // NUL (0x00) is not in {0x09, 0x0A, 0x0D, 0x08}
        // so it should hit the default => null case in execute()
        $parser->feed("\x00");

        // No methods should be called on CsiHandler
        // The assertion is implicit - mock will fail if any method is called
        $this->assertTrue(true, 'default case should not call any CsiHandler method');
    }

    /**
     * Test that execute() with BEL (0x07) hits the default case.
     */
    public function testExecuteBelHitsDefaultCase(): void
    {
        $csi = $this->createMock(CsiHandler::class);
        $csi->expects($this->never())->method('printable');
        $csi->expects($this->never())->method('cr');
        $csi->expects($this->never())->method('lf');
        $csi->expects($this->never())->method('cht');
        $csi->expects($this->never())->method('cub');

        $osc = $this->createMock(OscHandler::class);
        $adapter = new HandlerAdapter($csi, $osc);
        $parser = new Parser($adapter);

        // BEL (0x07) is not in {0x09, 0x0A, 0x0D, 0x08}
        $parser->feed("\x07");

        $this->assertTrue(true);
    }

    /**
     * Test hyperlink with URI containing equals sign.
     * The parseHyperlinkId should still find id= correctly.
     */
    public function testHyperlinkWithEqualsInUri(): void
    {
        $oscImpl = new \SugarCraft\Ansi\Parser\OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser = new Parser($adapter);

        // URI contains = which is not a param separator
        $parser->feed("\x1b]8;id=link;https://example.com/path?foo=bar\x07");

        $this->assertSame('https://example.com/path?foo=bar', $oscImpl->hyperlinkUri());
        $this->assertSame('link', $oscImpl->hyperlinkId());
    }

    /**
     * Test that the fast path in feed() is exercised for long ASCII runs.
     */
    public function testFastPathForLongAsciiRun(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // Feed a long string of printable ASCII
        $longString = str_repeat('x', 1000);
        $parser->feed($longString);

        $prints = $handler->filter('print');
        $this->assertCount(1000, $prints);

        // Verify fast path was used
        $this->assertGreaterThan(0, $parser->fastPathRuns());
    }

    /**
     * Test that csiDispatch default case in HandlerAdapter does nothing.
     * Unknown CSI final bytes should not call any CsiHandler method.
     */
    public function testUnknownCsiFinalDoesNothingInAdapter(): void
    {
        $csi = $this->createMock(CsiHandler::class);
        $csi->expects($this->never())->method('cuu');
        $csi->expects($this->never())->method('cud');
        $csi->expects($this->never())->method('cuf');
        $csi->expects($this->never())->method('cub');
        $csi->expects($this->never())->method('cup');
        $csi->expects($this->never())->method('sgr');
        $csi->expects($this->never())->method('ed');
        $csi->expects($this->never())->method('el');
        $csi->expects($this->never())->method('decset');
        $csi->expects($this->never())->method('decrst');
        $csi->expects($this->never())->method('decstbm');
        $csi->expects($this->never())->method('tbc');
        $csi->expects($this->never())->method('cbt');
        $csi->expects($this->never())->method('cht');
        $csi->expects($this->never())->method('su');
        $csi->expects($this->never())->method('sd');
        $csi->expects($this->never())->method('il');
        $csi->expects($this->never())->method('dl');
        $csi->expects($this->never())->method('ich');
        $csi->expects($this->never())->method('dch');
        $csi->expects($this->never())->method('rep');
        $csi->expects($this->never())->method('scosc');
        $csi->expects($this->never())->method('scorc');
        $csi->expects($this->never())->method('hvp');

        $osc = $this->createMock(OscHandler::class);
        $adapter = new HandlerAdapter($csi, $osc);
        $parser = new Parser($adapter);

        // CSI with unknown final byte 'q'
        $parser->feed("\x1b[1;2q");

        $this->assertTrue(true);
    }

    /**
     * Test that consecutive UTF-8 lead bytes properly handle the second lead.
     */
    public function testConsecutiveUtf8LeadBytes(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, false);

        // Two UTF-8 lead bytes without continuation
        $parser->feed("\xc3\xc3");

        $prints = $handler->filter('print');
        // Both should be dropped as incomplete
        $this->assertCount(0, $prints);
    }

    /**
     * Test flush() when in DcsString state dispatches DCS with its params.
     */
    public function testFlushInDcsStringDispatchesDcsWithParams(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // Feed incomplete DCS (without ST terminator)
        $parser->feed("\x1bP1;2;3mydata");
        $this->assertSame(\SugarCraft\Ansi\Parser\State::DcsString->value, $parser->currentState()->value);

        // Flush should dispatch
        $parser->flush();

        $dcs = $handler->filter('dcs');
        $this->assertNotEmpty($dcs, 'DCS should be dispatched on flush');
        // Data is just what was accumulated after the final byte
        $this->assertSame('ydata', $dcs[0]['detail']['data']);
    }

    /**
     * Test flush() when in SosString state dispatches SOS.
     */
    public function testFlushInSosStringDispatchesSos(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->feed("\x1bXsos data");
        $this->assertSame(\SugarCraft\Ansi\Parser\State::SosString->value, $parser->currentState()->value);

        $parser->flush();

        $sos = $handler->filter('sos');
        $this->assertNotEmpty($sos);
        $this->assertSame('sos data', $sos[0]['detail']);
    }

    /**
     * Test flush() when in PmString state dispatches PM.
     */
    public function testFlushInPmStringDispatchesPm(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->feed("\x1b^pm data");
        $this->assertSame(\SugarCraft\Ansi\Parser\State::PmString->value, $parser->currentState()->value);

        $parser->flush();

        $pm = $handler->filter('pm');
        $this->assertNotEmpty($pm);
        $this->assertSame('pm data', $pm[0]['detail']);
    }

    /**
     * Test flush() when in ApcString state dispatches APC.
     */
    public function testFlushInApcStringDispatchesApc(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->feed("\x1b_apc data");
        $this->assertSame(\SugarCraft\Ansi\Parser\State::ApcString->value, $parser->currentState()->value);

        $parser->flush();

        $apc = $handler->filter('apc');
        $this->assertNotEmpty($apc);
        $this->assertSame('apc data', $apc[0]['detail']);
    }

    /**
     * Test that parseComplete() properly handles unterminated OSC.
     */
    public function testParseCompleteDispatchesUnterminatedOsc(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // OSC without terminator
        $parser->parseComplete("\x1b]2;Unterminated Title");

        $oscs = $handler->filter('osc');
        $this->assertCount(1, $oscs);
        $this->assertSame('2;Unterminated Title', $oscs[0]['detail']);
    }

    /**
     * Test that parseComplete() properly handles unterminated DCS.
     */
    public function testParseCompleteDispatchesUnterminatedDcs(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->parseComplete("\x1bP1;2;3mydata");

        $dcs = $handler->filter('dcs');
        $this->assertCount(1, $dcs);
        // Data is just 'ydata' - the bytes after the final 'm'
        $this->assertSame('ydata', $dcs[0]['detail']['data']);
    }

    /**
     * Test parseComplete() with unterminated UTF-8 sequence.
     */
    public function testParseCompleteWithIncompleteUtf8(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        // Incomplete 3-byte sequence
        $parser->parseComplete("\xe2\x82");

        $prints = $handler->filter('print');
        // With replaceMalformed=true, incomplete sequence emits replacement
        $this->assertCount(1, $prints);
        $this->assertSame("\xEF\xBF\xBD", $prints[0]['detail']);
    }

    /**
     * Test that gridRows() is called when CSI 'r' has second param as -1.
     */
    public function testDecstbmCallsGridRowsForMissingBottomParam(): void
    {
        $csi = $this->createMock(CsiHandler::class);
        $csi->expects($this->once())->method('gridRows')->willReturn(24);
        $csi->expects($this->once())->method('decstbm')->with(5, 24);

        $osc = $this->createMock(OscHandler::class);
        $adapter = new HandlerAdapter($csi, $osc);
        $parser = new Parser($adapter);

        // CSI 'r' with only top param (bottom should default to gridRows())
        $parser->feed("\x1b[5r");
    }
}
