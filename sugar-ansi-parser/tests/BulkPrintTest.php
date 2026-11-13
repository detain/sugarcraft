<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use SugarCraft\AnsiParser\Parser\BulkPrintHandler;
use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\AnsiParser\Tests\Support\BulkDispatchRecorder;
use SugarCraft\AnsiParser\Tests\Support\DispatchRecorder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the {@see BulkPrintHandler} capability (finding 002, sap-03).
 *
 * The load-bearing contract: opting into bulk must not move or alter a
 * single observable event — only GROUP adjacent printable-ASCII arrivals.
 * Every test drives the same stream through a classic recorder and a
 * bulk-capable recorder of the identical formatting code (the bulk one
 * EXTENDS the classic one, house precedent: CapableDispatchRecorder for the
 * subparams push), so any difference in their normalized logs can only come
 * from the parser.
 */
final class BulkPrintTest extends TestCase
{
    public function testBulkSinkSeesTheExactlySameEventStreamAsClassic(): void
    {
        $stream = "ab\x1b[31mcd\xc3\xa9ef \x1b]0;t\x07z\nrest\x7f!";

        $classic = new DispatchRecorder();
        (new Parser($classic))->parseComplete($stream);

        $bulk = new BulkDispatchRecorder();
        (new Parser($bulk))->parseComplete($stream);

        $this->assertSame(
            $classic->calls,
            $bulk->calls,
            'a Bulk-capable handler must observe the identical normalized event log',
        );
    }

    public function testRunsAreMaximalContiguousGroupsBrokenByEveryNonPrintableByte(): void
    {
        $bulk = new BulkDispatchRecorder();
        $parser = new Parser($bulk);

        $parser->feed('ab');           // run 'ab' (still open across calls)
        $parser->feed("c\x1b[1md");    // run 'c', CSI dispatch, run 'd'
        $parser->feed("\xc3\xa9e");    // é via printChar, run 'e'

        $this->assertSame(
            ['ab', 'c', 'd', 'e'],
            $bulk->runs,
            'runs must not merge across dispatches, chunk boundaries, or runes',
        );
    }

    public function testFastPathRunCountIsUnchangedForBulkConsumers(): void
    {
        $classic = new DispatchRecorder();
        $classicParser = new Parser($classic);
        $classicParser->feed("one\x1b[0mtwo three");

        $bulk = new BulkDispatchRecorder();
        $bulkParser = new Parser($bulk);
        $bulkParser->feed("one\x1b[0mtwo three");

        // 'one' and 'two three' — the space (0x20) is INSIDE the sweep band,
        // so it does not break the run: two counts, one per contiguous
        // printable-ASCII run on BOTH routes. The diagnostic counts runs, not
        // printChar calls, so sap-03 must not have moved it.
        $this->assertSame(2, $classicParser->fastPathRuns());
        $this->assertSame($classicParser->fastPathRuns(), $bulkParser->fastPathRuns());
    }

    public function testNonBulkHandlersNeverReceivePrintRunAndKeepPerCharLogs(): void
    {
        // The degradation story: a plain Handler keeps compiling AND keeps
        // seeing per-byte printChar calls — DebugHandler (used across the
        // whole suite) does not implement the capability, so the 400+
        // existing pins are themselves the proof; this one pins it for the
        // recorder with an explicit byte-level log.
        $classic = new DispatchRecorder();
        (new Parser($classic))->feed('xyz');

        $this->assertSame(['print:78', 'print:79', 'print:7a'], $classic->calls);
        $this->assertFalse(
            $classic instanceof BulkPrintHandler,
            'the classic recorder must NOT opt into the capability',
        );
    }

    public function testRuneSweepAndBulkSweepCoexistWithoutReordering(): void
    {
        $bulk = new BulkDispatchRecorder();
        $parser = new Parser($bulk);
        $parser->parseComplete("a\xe4\xb8\xadb\xc3\xa9c");

        // Runs split around each whole rune: 'a', 中, 'b', é, 'c' — the
        // printRun/grouping boundary never swallows a Utf8 arrival.
        $this->assertSame(['a', 'b', 'c'], $bulk->runs);
        $this->assertSame(
            ['print:61', 'print:e4b8ad', 'print:62', 'print:c3a9', 'print:63'],
            $bulk->calls,
        );
    }
}
