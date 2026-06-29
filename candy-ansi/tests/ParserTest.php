<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\CsiHandler;
use SugarCraft\Ansi\Parser\DebugHandler;
use SugarCraft\Ansi\Parser\Handler;
use SugarCraft\Ansi\Parser\OscHandler;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Ansi\Parser\State;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testFeedParsesSgrSequence(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("hello\x1b[31mworld\x1b[0m");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis, 'Expected at least one CSI dispatch');

        $sgr31 = array_filter($csis, static fn($e) =>
            $e['detail']['final'] === ord('m')
            && in_array(31, $e['detail']['params'], true)
        );
        $this->assertNotEmpty($sgr31, 'Expected sgr([31]) invocation');

        $sgrReset = array_filter($csis, static fn($e) =>
            $e['detail']['final'] === ord('m')
            && in_array(0, $e['detail']['params'], true)
        );
        $this->assertNotEmpty($sgrReset, 'Expected sgr([0]) reset');
    }

    public function testPartialInputResumesCorrectly(): void
    {
        $handler = new DebugHandler();
        $parser   = new Parser($handler);

        $parser->feed("\x1b[");
        $this->assertSame(1, $parser->currentState()->value);

        $parser->feed("31m");
        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame(ord('m'), $csis[0]['detail']['final']);
    }

    public function testUtf8RuneArrivesWhole(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\xc3\xa9");

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints, 'UTF-8 rune should arrive as a single printChar call');
        $this->assertSame("é", $prints[0]['detail']);
    }

    public function testResetClearsState(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[31mred");
        $parser->reset();

        $this->assertSame(0, $parser->currentState()->value);
    }

    public function testResetClearsStaleParams(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        // Feed partial CSI with params
        $parser->feed("\x1b[31;5");
        $parser->reset();
        // Feed CSI final byte after reset — stale params must not leak
        $parser->feed("\x1b[m");

        $csis = $handler->filter('csi');
        // After reset, \x1b[m should dispatch with empty params ([]),
        // proving the old [31, 5] did not leak
        $this->assertNotEmpty($csis, 'Should have a CSI dispatch after reset');
        $this->assertSame([], $csis[count($csis) - 1]['detail']['params']);
    }

    public function testFlushDispatchesInFlightString(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b]2;Hello");
        $parser->flush();

        $oscs = $handler->filter('osc');
        $this->assertNotEmpty($oscs);
        $this->assertSame('2;Hello', $oscs[0]['detail']);
    }

    public function testExecuteC0ControlChars(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("a\x07b");

        $executes = $handler->filter('execute');
        $this->assertNotEmpty($executes, 'BEL (0x07) should produce an execute action');
    }

    public function testGroundStatePrintsPrintableAscii(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("ABC");

        $prints = $handler->filter('print');
        $this->assertCount(3, $prints);
        $this->assertSame('A', $prints[0]['detail']);
        $this->assertSame('B', $prints[1]['detail']);
        $this->assertSame('C', $prints[2]['detail']);
    }

    public function testGroundStateExecutesC0Controls(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x00\x01\x02"); // SOH, STX, ETX

        $executes = $handler->filter('execute');
        $this->assertCount(3, $executes);
    }

    public function testEscapeStateTransitions(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b"); // ESC

        $this->assertSame(State::Escape->value, $parser->currentState()->value);
    }

    public function testEscapeDispatchSequence(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1bD"); // IND (index)

        $esc = $handler->filter('esc');
        $this->assertNotEmpty($esc, 'ESC D should dispatch');
        $this->assertSame(ord('D'), $esc[0]['detail']['final']);
        $this->assertSame(0, $esc[0]['detail']['intermediate']);
    }

    public function testCsiEntryState(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b["); // ESC [

        $this->assertSame(State::CsiEntry->value, $parser->currentState()->value);
    }

    public function testCsiDispatchWithParams(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[1;2m");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame([1, 2], $csis[0]['detail']['params']);
        $this->assertSame(ord('m'), $csis[0]['detail']['final']);
    }

    public function testCsiDispatchWithPrivateMarker(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[?25h");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame(ord('?'), $csis[0]['detail']['prefix']);
        $this->assertSame(25, $csis[0]['detail']['params'][0]);
        $this->assertSame(ord('h'), $csis[0]['detail']['final']);
    }

    public function testCsiDispatchCUU(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[3A");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame(ord('A'), $csis[0]['detail']['final']);
        $this->assertSame([3], $csis[0]['detail']['params']);
    }

    public function testCsiDispatchCUD(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[5B");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame(ord('B'), $csis[0]['detail']['final']);
        $this->assertSame([5], $csis[0]['detail']['params']);
    }

    public function testCsiDispatchCUF(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[2C");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame(ord('C'), $csis[0]['detail']['final']);
        $this->assertSame([2], $csis[0]['detail']['params']);
    }

    public function testCsiDispatchCUB(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[4D");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame(ord('D'), $csis[0]['detail']['final']);
        $this->assertSame([4], $csis[0]['detail']['params']);
    }

    public function testCsiDispatchCUP(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[10;20H");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame(ord('H'), $csis[0]['detail']['final']);
        $this->assertSame([10, 20], $csis[0]['detail']['params']);
    }

    public function testCsiDispatchEraseDisplay(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[2J");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame(ord('J'), $csis[0]['detail']['final']);
        $this->assertSame([2], $csis[0]['detail']['params']);
    }

    public function testCsiDispatchEraseLine(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[K");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame(ord('K'), $csis[0]['detail']['final']);
    }

    public function testOscStringWithBelTerminator(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b]2;Hello World\x07");

        $oscs = $handler->filter('osc');
        $this->assertNotEmpty($oscs, 'OSC should be dispatched');
        $this->assertSame('2;Hello World', $oscs[0]['detail']);
    }

    public function testOscStringWithStTerminator(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b]2;Hello World\x1b\\");

        $oscs = $handler->filter('osc');
        $this->assertNotEmpty($oscs, 'OSC with ST terminator should be dispatched');
        $this->assertSame('2;Hello World', $oscs[0]['detail']);
    }

    public function testOscHyperlink(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b]8;;https://example.com\x1b\\");

        $oscs = $handler->filter('osc');
        $this->assertNotEmpty($oscs);
        $this->assertSame('8;;https://example.com', $oscs[0]['detail']);
    }

    public function testDcsStringPassthrough(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1bP1;2;3mystring\x1b\\");

        $dcs = $handler->filter('dcs');
        $this->assertNotEmpty($dcs, 'DCS should be dispatched');
        $this->assertSame(ord('m'), $dcs[0]['detail']['final']);
        $this->assertSame([1, 2, 3], $dcs[0]['detail']['params']);
        $this->assertSame('ystring', $dcs[0]['detail']['data']);
    }

    public function testSosPmApcDispatch(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1bXtest string\x1b\\");

        $sos = $handler->filter('sos');
        $this->assertNotEmpty($sos, 'SOS should be dispatched');
        $this->assertSame('test string', $sos[0]['detail']);
    }

    public function testPmString(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b^pm string\x1b\\");

        $pm = $handler->filter('pm');
        $this->assertNotEmpty($pm);
        $this->assertSame('pm string', $pm[0]['detail']);
    }

    public function testApcString(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b_apc string\x1b\\");

        $apc = $handler->filter('apc');
        $this->assertNotEmpty($apc);
        $this->assertSame('apc string', $apc[0]['detail']);
    }

    public function testUtf8TwoByteSequence(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\xc3\xa9");

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints);
        $this->assertSame("é", $prints[0]['detail']);
    }

    public function testUtf8ThreeByteSequence(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\xe2\x82\xac");

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints);
        $this->assertSame("€", $prints[0]['detail']);
    }

    public function testUtf8FourByteSequence(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\xf0\x9f\x98\x80");

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints);
        $this->assertSame("😀", $prints[0]['detail']);
    }

    public function testUtf8InterleavedWithEscapes(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\xc3\xa9\x1b[31m");

        $prints = $handler->filter('print');
        $csis = $handler->filter('csi');

        $this->assertCount(1, $prints, 'UTF-8 rune should arrive before escape');
        $this->assertNotEmpty($csis, 'SGR should be dispatched after UTF-8');
    }

    public function testPrematureStTerminatesOsc(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b]2;Hello\x1b\\");
        $parser->feed(" World");

        $oscs = $handler->filter('osc');
        $this->assertCount(1, $oscs, 'OSC should be dispatched once on ST');
        $this->assertSame('2;Hello', $oscs[0]['detail']);
    }

    public function testRunawayCsiAbortsOnOtherCsi(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b["); // enters CsiEntry
        $parser->feed("xxx"); // stays in CsiParam (no dispatch final byte)
        $parser->feed("\x1b["); // new CSI aborts the previous one

        $csis = $handler->filter('csi');
        // Should have only one CSI dispatch when the second CSI arrives
        // because "xxx" are not valid final bytes in CsiParam
        $this->assertCount(1, $csis, 'Runaway CSI should be aborted by new ESC [');
    }

    public function testEmbedded7BitC1EscapedForm(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[1m");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame(ord('m'), $csis[0]['detail']['final']);
    }

    public function test8BitC1DirectForm(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x9b1m");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis, '8-bit CSI (0x9B) should be treated same as ESC [');
        $this->assertSame(ord('m'), $csis[0]['detail']['final']);
    }

    public function testTabCharacterExecutes(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\t");

        $executes = $handler->filter('execute');
        $this->assertNotEmpty($executes, 'Tab (0x09) should produce an execute action');
    }

    public function testBackspaceExecutes(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x08");

        $executes = $handler->filter('execute');
        $this->assertNotEmpty($executes, 'Backspace (0x08) should produce an execute action');
    }

    public function testCsiWithSubparamSeparator(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[1:2:3m");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        // ':' is a sub-parameter separator, params should be [1, 2, 3]
        $this->assertSame([1, 2, 3], $csis[0]['detail']['params']);
    }

    public function testFlushInGroundStateIsNoOp(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("hello");
        $parser->flush();

        $this->assertSame(State::Ground->value, $parser->currentState()->value);
        $prints = $handler->filter('print');
        $this->assertCount(5, $prints);
    }

    public function testFlushInOscStringDispatches(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b]2;Unterminated");
        $this->assertSame(State::OscString->value, $parser->currentState()->value);

        $parser->flush();

        $this->assertSame(State::Ground->value, $parser->currentState()->value);
        $oscs = $handler->filter('osc');
        $this->assertNotEmpty($oscs);
        $this->assertSame('2;Unterminated', $oscs[0]['detail']);
    }

    public function testC1DirectFormsAnywhereTransition(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("A\x9b1mB");

        $csis = $handler->filter('csi');
        $prints = $handler->filter('print');

        $this->assertCount(2, $prints, 'A and B should be printed');
        $this->assertNotEmpty($csis, '8-bit CSI should trigger CSI dispatch');
    }

    public function testAnywhereEscapeAbortsCsiAndEntersEscape(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[31m");
        $this->assertSame(State::Ground->value, $parser->currentState()->value);

        $parser->feed("\x1b"); // anywhere ESC
        $this->assertSame(State::Escape->value, $parser->currentState()->value);
    }

    public function testAnywhereCsiEntryFor8BitC1(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[31m\x9b1m");

        $csis = $handler->filter('csi');
        $this->assertCount(2, $csis);
    }

    public function testAnywhereOscStringFor8BitC1(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b]2;Hello\x07\x9d1;2m");

        $oscs = $handler->filter('osc');
        $this->assertCount(1, $oscs, 'First OSC should be dispatched');
        $this->assertSame('2;Hello', $oscs[0]['detail']);
        // The second OSC (after 0x9D) is still in-flight and not dispatched
        // because there's no BEL/ST terminator
    }

    public function testUtf8InvalidContinuationDropsAndRetries(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\xc3\xc3"); // two lead bytes, second drops first and enters Utf8

        $prints = $handler->filter('print');
        $this->assertCount(0, $prints, 'Incomplete UTF-8 is dropped on flush');
    }

    public function testUtf8IncompleteSequenceAtEndOfInput(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\xc3"); // only lead byte, no continuation

        $parser->flush();

        $prints = $handler->filter('print');
        $this->assertCount(0, $prints, 'Incomplete UTF-8 should be dropped on flush');
    }

    public function testCsiWithIntermediateBytes(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[1;2;3 X");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis, 'CSI with intermediates should dispatch');
        $this->assertSame(ord(' '), $csis[0]['detail']['intermediate']);
    }

    public function testDcsWithIntermediateBytes(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1bP1;2;3 X\x1b\\");

        $dcs = $handler->filter('dcs');
        $this->assertNotEmpty($dcs, 'DCS with intermediates should dispatch');
        $this->assertSame(ord(' '), $dcs[0]['detail']['intermediate']);
    }

    public function testEmptyFirstParamDefaultsToOne(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("\x1b[;2m");

        $csis = $handler->filter('csi');
        $this->assertNotEmpty($csis);
        $this->assertSame([-1, 2], $csis[0]['detail']['params']);
    }

    public function testParseCompleteFlushesTrailingOsc(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        // Unterminated OSC — parseComplete should still dispatch it
        $parser->parseComplete("\x1b]2;Title");

        $oscs = $handler->filter('osc');
        $this->assertCount(1, $oscs, 'Unterminated OSC should be dispatched on parseComplete');
        $this->assertSame('2;Title', $oscs[0]['detail']);
    }
}
