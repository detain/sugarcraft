<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\DebugHandler;
use SugarCraft\Ansi\Parser\Parser;
use PHPUnit\Framework\TestCase;

final class Utf8PolicyTest extends TestCase
{
    /**
     * With replaceMalformed=false (default), invalid UTF-8 is silently dropped.
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
}
