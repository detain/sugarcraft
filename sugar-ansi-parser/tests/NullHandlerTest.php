<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Parser\BulkPrintHandler;
use SugarCraft\AnsiParser\Parser\HandlerAdapter;
use SugarCraft\AnsiParser\Parser\NullHandler;
use SugarCraft\AnsiParser\Parser\OscHandlerImpl;
use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\AnsiParser\Support\RecordingCsiHandler;

/**
 * The shipped null sink (finding sugar-ansi-parser-005): seven no-ops that
 * count, exact per-event-family totals walked by a real stream, and the
 * documented decision to stay OUT of the bulk-print capability so the
 * counters keep rune truth.
 */
final class NullHandlerTest extends TestCase
{
    public function testCountersTrackEveryEventFamilyOfAFedStream(): void
    {
        $null = new NullHandler();
        $parser = new Parser($null);

        // 'a','b' prints; BEL executes; CSI 3A dispatches; ESC M dispatches;
        // OSC closes on BEL (in-string BEL is a terminator, not an execute);
        // DCS closed by C1 ST (an ESC-backslash ST would ALSO count its
        // backslash as an esc dispatch — the sap-12 discovery — so this leg
        // deliberately uses \x9c to keep the esc total unambiguous).
        $parser->feed("ab\x07\x1b[3A\x1bM\x1b]0;t\x07\x1bP1qd\x9c\x98x\x9c");

        $this->assertSame(2, $null->printCount());
        $this->assertSame(1, $null->executeCount());
        $this->assertSame(1, $null->csiCount());
        $this->assertSame(1, $null->escCount());
        $this->assertSame(1, $null->oscCount());
        $this->assertSame(1, $null->dcsCount());
        $this->assertSame(1, $null->sosPmApcCount());
    }

    public function testNullSinkStaysOutOfTheBulkCapabilitySoPrintCountsRunes(): void
    {
        // The documented choice (finding 005): a null sink wants byte-level
        // per-char truth, so it implements the classic Handler only. If it
        // ever gained printRun(), a bulk stream would count RUNS here and
        // silently change the accessor's meaning.
        $this->assertNotInstanceOf(BulkPrintHandler::class, new NullHandler());

        $null = new NullHandler();
        (new Parser($null))->feed("two words");
        $this->assertSame(9, $null->printCount(), 'every printable byte arrived per-char, space included');
    }

    public function testWorksAsTheAdapterFallbackCountingWhatTheSieveDrops(): void
    {
        // Dogfood pairing: the just-shipped RecordingCsiHandler on the typed
        // side, NullHandler as $fallback — the sieve's consumed leg records,
        // the dropped leg counts, and neither double sees the other's events.
        $rec = new RecordingCsiHandler();
        $null = new NullHandler();
        $parser = new Parser(new HandlerAdapter($rec, new OscHandlerImpl(), $null));

        $parser->feed("\x09ab\x07\x1bM");

        $this->assertSame([
            ['type' => 'cht', 'detail' => 1],
            ['type' => 'printable', 'detail' => 'a'],
            ['type' => 'printable', 'detail' => 'b'],
        ], $rec->log);
        $this->assertSame(1, $null->executeCount()); // BEL — dropped by the sieve, counted here
        $this->assertSame(1, $null->escCount());     // ESC M — same
        $this->assertSame(0, $null->printCount());   // prints were consumed, not dropped
    }
}
