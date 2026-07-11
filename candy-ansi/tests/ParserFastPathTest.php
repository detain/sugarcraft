<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\DebugHandler;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Ansi\Parser\State;
use PHPUnit\Framework\TestCase;

/**
 * Byte-parity + activation tests for the Ground-state printable-ASCII
 * fast path in {@see Parser::feed()}.
 *
 * The fast path sweeps a run of 0x20-0x7E bytes and emits the run's
 * printChar() calls directly, skipping the per-byte transition-table
 * round-trip. It MUST be byte-for-byte identical to advancing one byte
 * at a time: exactly one single-byte printChar() per byte, in order,
 * with every non-printable boundary byte still routed through the normal
 * state machine. Each test pins the exact DebugHandler log so any
 * divergence fails, and asserts fastPathRuns() to prove the optimization
 * is actually exercised.
 */
final class ParserFastPathTest extends TestCase
{
    public function testLongAsciiRunEmitsPerCharPrintsInOneRun(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $text = str_repeat('The quick brown fox 0123456789! ', 8);
        $parser->feed($text);

        // One printChar per byte, each a single-byte rune, in order.
        $expected = [];
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $expected[] = ['type' => 'print', 'detail' => $text[$i]];
        }
        $this->assertSame($expected, $handler->log);

        // The whole run collapses into exactly one fast-path sweep.
        $this->assertSame(1, $parser->fastPathRuns());
    }

    public function testInterleavedCsiSequencesKeepPerCharParity(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("AB\x1b[31mCD\x1b[0mEF");

        $this->assertSame([
            ['type' => 'print', 'detail' => 'A'],
            ['type' => 'print', 'detail' => 'B'],
            ['type' => 'csi', 'detail' => ['final' => ord('m'), 'params' => [31], 'prefix' => 0, 'intermediate' => 0]],
            ['type' => 'print', 'detail' => 'C'],
            ['type' => 'print', 'detail' => 'D'],
            ['type' => 'csi', 'detail' => ['final' => ord('m'), 'params' => [0], 'prefix' => 0, 'intermediate' => 0]],
            ['type' => 'print', 'detail' => 'E'],
            ['type' => 'print', 'detail' => 'F'],
        ], $handler->log);

        // Three separated printable runs: AB, CD, EF.
        $this->assertSame(3, $parser->fastPathRuns());
    }

    public function testUtf8MultibyteBreaksRunAndIsNotSwept(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        // é is a 2-byte UTF-8 rune (0xC3 0xA9); it must route through the
        // normal path and arrive as one whole printChar, not the fast path.
        $parser->feed("AB\xc3\xa9CD");

        $this->assertSame([
            ['type' => 'print', 'detail' => 'A'],
            ['type' => 'print', 'detail' => 'B'],
            ['type' => 'print', 'detail' => "\xc3\xa9"],
            ['type' => 'print', 'detail' => 'C'],
            ['type' => 'print', 'detail' => 'D'],
        ], $handler->log);

        // AB and CD are fast-path runs; the multibyte rune is not swept.
        $this->assertSame(2, $parser->fastPathRuns());
    }

    public function testC0ControlMidRunRoutesThroughStateMachine(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("AB\x07CD"); // BEL between two ASCII runs

        $this->assertSame([
            ['type' => 'print', 'detail' => 'A'],
            ['type' => 'print', 'detail' => 'B'],
            ['type' => 'execute', 'detail' => 0x07],
            ['type' => 'print', 'detail' => 'C'],
            ['type' => 'print', 'detail' => 'D'],
        ], $handler->log);
        $this->assertSame(2, $parser->fastPathRuns());
    }

    public function testDelByteIsExecutedNotSwept(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        // 0x7F (DEL) is Execute in Ground, one past the 0x7E fast-path bound.
        $parser->feed("AB\x7fCD");

        $this->assertSame([
            ['type' => 'print', 'detail' => 'A'],
            ['type' => 'print', 'detail' => 'B'],
            ['type' => 'execute', 'detail' => 0x7F],
            ['type' => 'print', 'detail' => 'C'],
            ['type' => 'print', 'detail' => 'D'],
        ], $handler->log);
        $this->assertSame(2, $parser->fastPathRuns());
    }

    public function testOscSequenceMidRunKeepsParity(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed("AB\x1b]2;T\x07CD");

        $this->assertSame([
            ['type' => 'print', 'detail' => 'A'],
            ['type' => 'print', 'detail' => 'B'],
            ['type' => 'osc', 'detail' => '2;T'],
            ['type' => 'print', 'detail' => 'C'],
            ['type' => 'print', 'detail' => 'D'],
        ], $handler->log);
        $this->assertSame(2, $parser->fastPathRuns());
    }

    public function testFastPathSpansSeparateFeedCalls(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        $parser->feed('AB');
        $parser->feed('CD');

        $this->assertSame([
            ['type' => 'print', 'detail' => 'A'],
            ['type' => 'print', 'detail' => 'B'],
            ['type' => 'print', 'detail' => 'C'],
            ['type' => 'print', 'detail' => 'D'],
        ], $handler->log);
        // Each feed() begins in Ground → one run apiece.
        $this->assertSame(2, $parser->fastPathRuns());
    }

    public function testPrintableBytesAreNotSweptOutsideGround(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        // Enter CsiEntry, then feed printable-ASCII param/final bytes. They
        // are 0x20-0x7E but the parser is NOT in Ground, so the fast path
        // must not fire — '1' is a param, 'm' dispatches the CSI.
        $parser->feed("\x1b[");
        $this->assertSame(State::CsiEntry->value, $parser->currentState()->value);

        $parser->feed('1m');

        $csis = $handler->filter('csi');
        $this->assertCount(1, $csis);
        $this->assertSame([1], $csis[0]['detail']['params']);
        $this->assertSame(ord('m'), $csis[0]['detail']['final']);
        $this->assertSame([], $handler->filter('print'));
        // Fast path never triggered — every byte went through the machine.
        $this->assertSame(0, $parser->fastPathRuns());
    }

    public function testRunStartingWithSpaceAndBoundaries(): void
    {
        $handler = new DebugHandler();
        $parser  = new Parser($handler);

        // Leading C0, then a run that includes 0x20 (space) and 0x7E (~).
        $parser->feed("\n a~b");

        $this->assertSame([
            ['type' => 'execute', 'detail' => 0x0A],
            ['type' => 'print', 'detail' => ' '],
            ['type' => 'print', 'detail' => 'a'],
            ['type' => 'print', 'detail' => '~'],
            ['type' => 'print', 'detail' => 'b'],
        ], $handler->log);
        // Single run after the newline.
        $this->assertSame(1, $parser->fastPathRuns());
    }
}
