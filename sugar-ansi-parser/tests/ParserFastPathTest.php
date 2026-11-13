<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use SugarCraft\AnsiParser\Parser\DebugHandler;
use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\AnsiParser\Parser\State;
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

    /**
     * sap-02 equivalence pin (findings 010+011, acceptance per the annotation):
     * the whole-chunk sweeps — inline UTF-8 lead scan and bulk string-payload
     * append — must be dispatch-identical to the per-byte route. A byte-per-
     * byte feed can never engage the rune scan (a 1-byte chunk holds no
     * multi-byte rune) and engages the payload sweep at exactly one byte —
     * the same append put() performed — so this pin proves chunk-size
     * independence for every split (whole, 1-byte, 3-byte); the pre-sweep
     * old-vs-new equality is separately verified against the previous
     * implementation (step-report differential, not committed). Fixed-seed
     * xorshift corpora hit the full byte space (scan breaks, invalid leads,
     * lone continuations, random terminators); structured legs hit valid
     * runes, terminators and the C1 edges; the tiny-cap rides pin the
     * clip-to-free + skip-the-rest drop semantics against the per-byte
     * put() drop.
     */
    public function testBulkSweepsMatchPerBytePathOnSeededCorpora(): void
    {
        $random = self::xorshiftStream(4096, 0xC0FFEE);
        $corpora = [
            'raw-random' => $random,
            'ground-cjk-mix' => str_repeat("plain \xe4\xb8\xad\xe6\x96\x87 \xff \xc3\xa9 caf\xe9; ", 150) . $random,
            'string-heavy' => str_repeat(
                "\x1b]0;ttl\xc3\xa9\xff\x07abc\x1bP1;qpassthrough\x1b\\\x9ein-flight\x9c\x1b_1337;data\x1b\\ \x1b[1;2m",
                120,
            ) . $random,
            'combined' => $random . str_repeat("\x1b]8;;http://e\xc2\xa0x/\x07\xe4\xb8\xad", 200),
        ];

        foreach ([false, true] as $replaceMalformed) {
            foreach ([65536, 5, 16] as $cap) {
                foreach ($corpora as $label => $bytes) {
                    $case = sprintf('corpus=%s cap=%d malformed=%d', $label, $cap, (int) $replaceMalformed);

                    $bulk = new Parser($bulkHandler = new DebugHandler(), $replaceMalformed, $cap);
                    $byte = new Parser($byteHandler = new DebugHandler(), $replaceMalformed, $cap);
                    $chunked = new Parser($chunkedHandler = new DebugHandler(), $replaceMalformed, $cap);

                    $bulk->feed($bytes);
                    foreach (str_split($bytes) as $b) {
                        $byte->feed($b);
                    }
                    foreach (str_split($bytes, 3) as $c) {
                        $chunked->feed($c);
                    }
                    $bulk->flush();
                    $byte->flush();
                    $chunked->flush();

                    $this->assertSame($byteHandler->log, $bulkHandler->log, "whole-chunk sweep diverged from per-byte path ($case)");
                    $this->assertSame($byteHandler->log, $chunkedHandler->log, "3-byte chunking diverged from per-byte path ($case)");
                }
            }
        }
    }

    /**
     * Deterministic byte source (xorshift32): stable across PHP builds and
     * versions, so the equivalence matrix above is a permanent pin rather
     * than a luck-of-mt_rand snapshot.
     */
    private static function xorshiftStream(int $length, int $seed): string
    {
        $out = '';
        $x = $seed;
        for ($i = 0; $i < $length; $i++) {
            $x ^= ($x << 13) & 0xFFFFFFFF;
            $x ^= $x >> 17;
            $x ^= ($x << 5) & 0xFFFFFFFF;
            $x &= 0xFFFFFFFF;
            $out .= chr($x & 0xFF);
        }
        return $out;
    }
}
