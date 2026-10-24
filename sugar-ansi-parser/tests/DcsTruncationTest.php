<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Parser\DebugHandler;
use SugarCraft\AnsiParser\Parser\Parser;

/**
 * F-16: DCS truncation / recovery semantics, pinned at the byte level.
 *
 * The contract (documented on Parser::flush() and Handler::dcsDispatch()):
 * - a DCS with a final byte but no ST dispatches the bytes that arrived, so
 *   sixel detection still works at end-of-stream;
 * - a bare ESC inside the DCS body re-syncs: the collected payload dispatches
 *   first, then the interrupting escape sequence parses normally. This is
 *   deliberately NOT park-until-ST (parking would swallow the next sequence);
 * - a bare ESC inside the PRELUDE aborts the sequence (no dispatch — the raw
 *   bytes are the caller's tail, which is how candy-ansi-inspect reports a
 *   truncated prelude);
 * - a DCS still in its prelude at end-of-stream is likewise aborted;
 * - CAN/SUB inside the body dispatches the payload collected so far, then
 *   aborts to Ground (the cancel byte is consumed as the terminator);
 * - the VT500 DcsEntry quirk (control bytes 0x08-0x0D, or a tmux-quirk ESC,
 *   going straight into the payload) dispatches with the `final === 0`
 *   no-final-byte sentinel.
 */
final class DcsTruncationTest extends TestCase
{
    public function testPreludeCutAtEndOfStreamDispatchesNothing(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->parseComplete("\x1bP1;2");

        self::assertCount(0, $handler->filter('dcs'), 'an aborted prelude carries no payload to dispatch');
        self::assertSame([], $handler->log, 'the raw tail belongs to the caller, not a fabricated dispatch');
    }

    public function testSixelWithNoStKeepsItsFinalByteAndPayloadAtEndOfStream(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->parseComplete("\x1bP0;1;0q\"1;1;100;50ABCD");

        $dcs = $handler->filter('dcs');
        self::assertCount(1, $dcs);
        self::assertSame(\ord('q'), $dcs[0]['detail']['final'], 'a real final byte survives truncation');
        self::assertSame([0, 1, 0], $dcs[0]['detail']['params']);
        self::assertSame('"1;1;100;50ABCD', $dcs[0]['detail']['data']);
    }

    public function testBareEscInsideTheBodyDispatchesThenResyncsIntoTheNextSequence(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->parseComplete("\x1bP0;1;0qABCD\x1b[31m");

        $types = array_column($handler->log, 'type');
        self::assertSame(['dcs', 'csi'], $types, 'payload dispatches before the interrupting CSI');
        self::assertSame(\ord('q'), $handler->log[0]['detail']['final']);
        self::assertSame('ABCD', $handler->log[0]['detail']['data']);
        self::assertSame(109, $handler->log[1]['detail']['final'], 'the CSI still parses (no park-until-ST)');
    }

    public function testBareEscInsideThePreludeAbortsWithoutADcsDispatch(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->parseComplete("\x1bP1;2\x1b[31m");

        self::assertCount(0, $handler->filter('dcs'), 'an aborted prelude carries no payload to report');
        $csi = $handler->filter('csi');
        self::assertCount(1, $csi);
        self::assertSame(109, $csi[0]['detail']['final']);
    }

    public function testCanInsideTheBodyDispatchesThePayloadBeforeAborting(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->parseComplete("\x1bP0;1;0qABC\x18");

        $dcs = $handler->filter('dcs');
        self::assertCount(1, $dcs, 'A cancelled DCS must dispatch what it collected, not vanish');
        self::assertSame(0x71, $dcs[0]['detail']['final'], 'final byte is the q');
        self::assertSame('ABC', $dcs[0]['detail']['data'], 'truncated body surfaces');
        self::assertSame([], $handler->filter('execute'), 'the CAN byte is consumed, never echoed');
    }

    public function testStTerminatesTheDcsBeforeTheTrailingEscDispatch(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->parseComplete("\x1bP0;1;0qABCD\x1b\\");

        $types = array_column($handler->log, 'type');
        self::assertSame(['dcs', 'esc'], $types, 'ESC \\ is ST: DCS dispatch, then the Escape byte dispatch');
        self::assertSame('ABCD', $handler->log[0]['detail']['data']);
        self::assertSame(\ord('\\'), $handler->log[1]['detail']['final']);
    }

    public function testTruncatedDcsSurvivesChunkedFeeds(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->feed("\x1bP0;1;0q");
        $parser->feed('ABCD');
        $parser->flush();

        $dcs = $handler->filter('dcs');
        self::assertCount(1, $dcs);
        self::assertSame(\ord('q'), $dcs[0]['detail']['final']);
        self::assertSame('ABCD', $dcs[0]['detail']['data']);
    }

    /**
     * The VT500 DcsEntry Put edges (0x08-0x0D and the tmux-quirk ESC) enter the
     * payload without ever seeing a final byte. Such a sequence dispatches
     * with final 0 — the sentinel the codec labels "truncated".
     */
    public function testDcsWithNoFinalByteDispatchesTheZeroSentinel(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->parseComplete("\x1bP\x08abc");

        $dcs = $handler->filter('dcs');
        self::assertCount(1, $dcs);
        self::assertSame(0, $dcs[0]['detail']['final']);
        self::assertSame("\x08abc", $dcs[0]['detail']['data']);
    }

    public function testEscQuirkPayloadAlsoDispatchesTheZeroSentinel(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->parseComplete("\x1bP\x1babc");

        $dcs = $handler->filter('dcs');
        self::assertCount(1, $dcs);
        self::assertSame(0, $dcs[0]['detail']['final']);
        self::assertSame("\x1babc", $dcs[0]['detail']['data']);
    }
}
