<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use SugarCraft\AnsiParser\Parser\DebugHandler;
use SugarCraft\AnsiParser\Parser\Parser;
use PHPUnit\Framework\TestCase;

final class Utf8PolicyTest extends TestCase
{
    /**
     * With replaceMalformed=false (default), a COMPLETED invalid rune
     * sequence is silently dropped (with the flag on it becomes U+FFFD —
     * see the replacement tests below).
     */
    public function testInvalidContinuationDroppedWhenFlagOff(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, false);

        // Two lead bytes - second drops first as incomplete
        $parser->feed("\xc3\xc3");

        $prints = $handler->filter('print');
        $this->assertCount(0, $prints, 'Invalid UTF-8 should be dropped when flag is off');
    }

    public function testIncompleteSequenceAtEndDroppedWhenFlagOff(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, false);

        // lone lead byte without continuation
        $parser->feed("\xc3");
        $parser->flush();

        $prints = $handler->filter('print');
        $this->assertCount(0, $prints, 'Incomplete UTF-8 should be dropped on flush when flag is off');
    }

    /**
     * With replaceMalformed=true, malformed sequences emit U+FFFD.
     */
    public function testOverlongSequenceEmitsReplacementWhenFlagOn(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        // Overlong: U+0041 (A) encoded as 3 bytes \xE0\x80\x81 instead of \x41
        $parser->feed("\xe0\x80\x81");

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints, 'Overlong sequence should emit one replacement');
        $this->assertSame("\xEF\xBF\xBD", $prints[0]['detail']);
    }

    public function testSurrogateSequenceEmitsReplacementWhenFlagOn(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        // Surrogate U+D800 encoded as 3 bytes \xED\xA0\x80
        $parser->feed("\xed\xa0\x80");

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints, 'Surrogate sequence should emit one replacement');
        $this->assertSame("\xEF\xBF\xBD", $prints[0]['detail']);
    }

    public function testTruncatedSequenceEmitsReplacementOnFlushWhenFlagOn(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        // lone lead byte without continuation, flushed at end-of-stream
        $parser->feed("\xc3");
        $parser->flush();

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints, 'Truncated UTF-8 should emit replacement on flush');
        $this->assertSame("\xEF\xBF\xBD", $prints[0]['detail']);
    }

    public function testInterruptedSequenceEmitsReplacementThenReprocessesWhenFlagOn(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        // '\xc3' starts a 2-byte rune but 'A' (0x41) interrupts
        $parser->feed("\xc3A");

        $prints = $handler->filter('print');
        $this->assertCount(2, $prints, 'Should emit U+FFFD for interrupted rune, then A');
        $this->assertSame("\xEF\xBF\xBD", $prints[0]['detail']);
        $this->assertSame('A', $prints[1]['detail']);
    }

    /**
     * Valid UTF-8 sequences pass through unchanged regardless of flag.
     */
    public function testValidTwoByteRunePassesThrough(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        $parser->feed("\xc3\xa9"); // 'é'

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints);
        $this->assertSame("é", $prints[0]['detail']);
    }

    public function testValidThreeByteRunePassesThrough(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        $parser->feed("\xe2\x82\xac"); // '€'

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints);
        $this->assertSame("€", $prints[0]['detail']);
    }

    public function testValidFourByteRunePassesThrough(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        $parser->feed("\xf0\x9f\x98\x80"); // '😀'

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints);
        $this->assertSame("😀", $prints[0]['detail']);
    }

    public function testByteCheckEmitsCorrectReplacementBytes(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        $parser->feed("\xe0\x80\x80"); // overlong NUL

        $prints = $handler->filter('print');
        $this->assertCount(1, $prints);
        // U+FFFD is the UTF-8 encoding \xEF\xBF\xBD
        $this->assertSame("\xef\xbf\xbd", $prints[0]['detail']);
    }

    /**
     * The bytes the state table truly drops — invalid leads 0xF5-0xFF and
     * lone continuations 0xA0-0xBF — vanish silently in BOTH modes:
     * replaceMalformed substitutes only completed invalid encodings.
     */
    public function testDroppedBytesNeverSurfaceAsReplacementInEitherMode(): void
    {
        foreach ([false, true] as $flag) {
            $handler = new DebugHandler();
            $parser = new Parser($handler, $flag);

            $parser->feed("\xf5A\xffB\xa0C\xb0D");

            $prints = $handler->filter('print');
            $this->assertCount(4, $prints, 'flag=' . var_export($flag, true));
            $this->assertSame(['A', 'B', 'C', 'D'], array_map(static fn ($e) => $e['detail'], $prints));
        }
    }

    /**
     * The 0x80-0x9F band is live C1 control, never dropped text, even under
     * replaceMalformed: 0x92 executes and 0x9D introduces an OSC string.
     */
    public function testC1BandStaysLiveControlsUnderReplacementMode(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler, true);

        $parser->feed("\x92\x9d0;hi\x07");

        $executes = $handler->filter('execute');
        $this->assertCount(1, $executes);
        $this->assertSame(0x92, $executes[0]['detail']);

        $osc = $handler->filter('osc');
        $this->assertCount(1, $osc);
        $this->assertSame('0;hi', $osc[0]['detail']);

        $this->assertCount(0, $handler->filter('print'), 'C1 bytes must not print or replace');
    }
}
