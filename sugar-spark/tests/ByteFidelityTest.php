<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Spark\AnsiHandler;
use SugarCraft\Spark\Inspector;
use SugarCraft\Spark\SequenceSegment;
use SugarCraft\Spark\StreamingInspector;
use SugarCraft\Spark\TextSegment;

/**
 * Byte-fidelity goldens for the sugar-spark parser.
 *
 * The inspector's whole value is that what it reports is what the terminal
 * actually sent, so every case here pins exact bytes rather than merely a
 * shape. Four defect families are covered, keyed to the ANSI conformance
 * audit (`docs/research/ansi-tmux-ansicode-audit.md`):
 *
 *  - SP-R5 parameter separators were rewritten (`ESC[4;3m` → `ESC[4:3m`).
 *  - SP-R2 a string terminator leaked out again as a ghost `ESC \` segment.
 *  - SP-R1 a CSI cut short by end-of-stream vanished without a segment.
 *  - SP-R8 an abandoned `ESC O` swallowed the printable that followed it.
 *
 * @see https://www.ecma-international.org/publications-and-standards/standards/ecma-48/
 */
final class ByteFidelityTest extends TestCase
{
    /**
     * Concatenate the raw bytes of every segment — the stream the inspector
     * claims the input contained.
     *
     * @param list<\SugarCraft\Spark\Segment> $segments
     */
    private static function reemit(array $segments): string
    {
        return implode('', array_map(static fn($segment): string => $segment->raw(), $segments));
    }

    // --- SP-R5: parameter separator fidelity -----------------------------

    public function testSemicolonParametersAreNotRewrittenAsColons(): void
    {
        $segments = Inspector::parse("\x1b[4;3m");

        $this->assertCount(1, $segments);
        // `4;3` is two parameters (underline, then italic); rewriting the
        // separator would claim a single curly-underline parameter.
        $this->assertSame("\x1b[4;3m", $segments[0]->raw());
        $this->assertStringContainsString('underline, italic', $segments[0]->describe());
        $this->assertStringNotContainsString('curly', $segments[0]->describe());
    }

    public function testColonSubparametersSurviveAsColons(): void
    {
        $segments = Inspector::parse("\x1b[4:3m");

        $this->assertCount(1, $segments);
        $this->assertSame("\x1b[4:3m", $segments[0]->raw());
        $this->assertStringContainsString('underline curly', $segments[0]->describe());
    }

    public function testBothUnderlineSpellingsRoundTripTheirOwnBytes(): void
    {
        foreach (["\x1b[4;3m", "\x1b[4:3m", "\x1b[4;38;5;200m", "\x1b[4:3;31m"] as $input) {
            $segments = Inspector::parse($input);
            $this->assertSame($input, self::reemit($segments), 'round-trip of ' . strtoupper(bin2hex($input)));
        }
    }

    public function testMixedSeparatorsInOneSequenceKeepTheirPositions(): void
    {
        $segments = Inspector::parse("\x1b[1;4:3;31m");

        $this->assertCount(1, $segments);
        $this->assertSame("\x1b[1;4:3;31m", $segments[0]->raw());
        $this->assertStringContainsString('bold, underline curly, foreground red', $segments[0]->describe());
    }

    public function testOmittedParameterKeepsItsEmptySlot(): void
    {
        // ECMA-48 §5.4.1: an omitted parameter is the default, expressed as
        // *nothing* between the separators — collapsing it to "0" would claim
        // an explicit SGR reset that the sender never sent.
        $segments = Inspector::parse("\x1b[;31m");

        $this->assertCount(1, $segments);
        $this->assertSame("\x1b[;31m", $segments[0]->raw());
    }

    public function testTrueColorSemicolonFormIsNotColonised(): void
    {
        $segments = Inspector::parse("\x1b[38;2;80;160;240m");

        $this->assertCount(1, $segments);
        $this->assertSame("\x1b[38;2;80;160;240m", $segments[0]->raw());
        $this->assertStringContainsString('foreground rgb(80,160,240)', $segments[0]->describe());
    }

    public function testTrueColorColonFormReportsItsOwnBytesAndColour(): void
    {
        // xterm ctlseqs: `CSI 38 : 2 : cs : r : g : b m` — the slot after the
        // mode is a colour-space id, not the red component.
        $segments = Inspector::parse("\x1b[38:2::80:160:240m");

        $this->assertCount(1, $segments);
        $this->assertSame("\x1b[38:2::80:160:240m", $segments[0]->raw());
        $this->assertStringContainsString('foreground rgb(80,160,240)', $segments[0]->describe());
    }

    public function testColonExtendedColourWithoutIdSlotStillResolves(): void
    {
        // Some senders omit xterm's colour-space id slot entirely; the three
        // components are then the whole tail of the group.
        $segments = Inspector::parse("\x1b[38:2:80:160:240m");

        $this->assertCount(1, $segments);
        $this->assertSame("\x1b[38:2:80:160:240m", $segments[0]->raw());
        $this->assertStringContainsString('foreground rgb(80,160,240)', $segments[0]->describe());
    }

    public function testColonBackgroundColourResolves(): void
    {
        $segments = Inspector::parse("\x1b[48:2::1:2:3m");

        $this->assertStringContainsString('background rgb(1,2,3)', $segments[0]->describe());
    }

    public function testColonTruncatedExtendedColoursAreReportedAsTruncated(): void
    {
        $this->assertStringContainsString(
            'foreground truncated truecolor',
            Inspector::parse("\x1b[38:2::1m")[0]->describe(),
        );
        $this->assertStringContainsString(
            'foreground truncated 256-color',
            Inspector::parse("\x1b[38:5:m")[0]->describe(),
        );
    }

    public function testColonSeparatorsAreFaithfulOutsideSgr(): void
    {
        // Separator fidelity is a CSI property, not an SGR one.
        $segments = Inspector::parse("\x1b[1:2:3p");

        $this->assertCount(1, $segments);
        $this->assertSame("\x1b[1:2:3p", $segments[0]->raw());
    }

    public function testPrivatePrefixWithMixedSeparatorsRoundTrips(): void
    {
        $segments = Inspector::parse("\x1b[?1;2:3c");

        $this->assertSame("\x1b[?1;2:3c", $segments[0]->raw());
    }

    // --- SP-R2: string terminators are consumed exactly once --------------

    public function testDcsTerminatorDoesNotReappearAsGhostSegment(): void
    {
        $segments = Inspector::parse("\x1bP q 0;1;0q\x1b\\done");

        // Regression: 3 segments — DCS, a phantom `ESC \`, then the text.
        $this->assertCount(2, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertStringEndsWith("\x1b\\", $segments[0]->raw());
        $this->assertSame(1, substr_count(self::reemit($segments), "\x1b\\"));
        $this->assertInstanceOf(TextSegment::class, $segments[1]);
        $this->assertSame('done', $segments[1]->raw());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function stTerminatedStringProvider(): array
    {
        return [
            'DCS sixel'  => ["\x1bP q A\x1b\\"],
            'APC kitty'  => ["\x1b_Ga=d,p=1\x1b\\"],
            'SOS'        => ["\x1bXpayload\x1b\\"],
            'PM'         => ["\x1b^payload\x1b\\"],
            'OSC'        => ["\x1b]0;title\x1b\\"],
        ];
    }

    /**
     * @dataProvider stTerminatedStringProvider
     */
    public function testStTerminatedStringYieldsOneSegment(string $input): void
    {
        $segments = Inspector::parse($input . 'T');

        $this->assertCount(2, $segments);
        foreach ($segments as $segment) {
            $this->assertNotSame("\x1b\\", $segment->raw(), 'ghost terminator segment for ' . strtoupper(bin2hex($input)));
        }
        $this->assertSame('T', $segments[1]->raw());
    }

    public function testLoneStringTerminatorOutsideAStringIsStillReported(): void
    {
        // The swallow window must be exactly "the terminator of the string we
        // just dispatched" — a real `ESC \` in the middle of the stream is a
        // sequence of its own and may not be eaten by hindsight.
        $segments = Inspector::parse("\x1b[31mA\x1b\\B");

        $this->assertCount(4, $segments);
        $this->assertSame("\x1b[31m", $segments[0]->raw());
        $this->assertSame('A', $segments[1]->raw());
        $this->assertSame("\x1b\\", $segments[2]->raw());
        $this->assertSame('B', $segments[3]->raw());
        $this->assertSame("\x1b[31mA\x1b\\B", self::reemit($segments));
    }

    public function testOscTerminatedByStIsNormalizedToBelWithoutGhost(): void
    {
        // Pre-existing documented normalization: the OSC segment ends in BEL.
        // What matters here is that the ST is accounted for exactly once.
        $segments = Inspector::parse("\x1b]0;T\x1b\\Z");

        $this->assertCount(2, $segments);
        $this->assertSame("\x1b]0;T\x07", $segments[0]->raw());
        $this->assertSame('Z', $segments[1]->raw());
    }

    // --- SP-R1: an unterminated sequence is never silently dropped ---------

    public function testTruncatedCsiIsSurfacedAtEndOfStream(): void
    {
        $segments = Inspector::parse("\x1b[31");

        // Regression: 0 segments — the bytes vanished, contradicting the
        // documented "never silently swallows" contract.
        $this->assertCount(1, $segments);
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertSame("\x1b[31", $segments[0]->raw());
        $this->assertStringContainsString('truncated CSI', $segments[0]->describe());
    }

    public function testTruncatedCsiKeepsTextThatPrecededIt(): void
    {
        $segments = Inspector::parse('abc' . "\x1b[31");

        $this->assertCount(2, $segments);
        $this->assertSame('abc', $segments[0]->raw());
        $this->assertSame("\x1b[31", $segments[1]->raw());
        $this->assertSame('abc' . "\x1b[31", self::reemit($segments));
    }

    public function testTruncatedCsiShapes(): void
    {
        $cases = [
            'introducer only'   => ["\x1b[", "\x1b["],
            'private prefix'    => ["\x1b[?1", "\x1b[?1"],
            'intermediate byte' => ["\x1b[1 ", "\x1b[1 "],
        ];

        foreach ($cases as $name => [$input, $expected]) {
            $segments = Inspector::parse($input);
            $this->assertCount(1, $segments, $name);
            $this->assertSame($expected, $segments[0]->raw(), $name);
            $this->assertStringContainsString('truncated CSI', $segments[0]->describe(), $name);
        }
    }

    public function testTruncatedEscIntermediateAndDcsPrelude(): void
    {
        $esc = Inspector::parse("\x1b/");
        $this->assertCount(1, $esc);
        $this->assertSame("\x1b/", $esc[0]->raw());
        $this->assertStringContainsString('truncated ESC', $esc[0]->describe());

        $dcs = Inspector::parse("\x1bP1");
        $this->assertCount(1, $dcs);
        $this->assertSame("\x1bP1", $dcs[0]->raw());
        $this->assertStringContainsString('truncated DCS', $dcs[0]->describe());
    }

    public function testTruncatedCsiIsReportedAcrossChunkBoundaries(): void
    {
        $inspector = new StreamingInspector();
        $this->assertCount(0, $inspector->feed("\x1b[3"));
        $this->assertCount(0, $inspector->feed('1'));

        $segments = $inspector->finish();
        $this->assertCount(1, $segments);
        $this->assertSame("\x1b[31", $segments[0]->raw());
        $this->assertStringContainsString('truncated CSI', $segments[0]->describe());
    }

    public function testCompletedSequenceThenTruncatedOneReportsBoth(): void
    {
        // A dispatch must not blind the handler to the *next* incomplete
        // sequence that shares the same chunk.
        $segments = Inspector::parse("\x1bO\x1b[31mX\x1b[3");

        $this->assertSame("\x1bO\x1b[31mX\x1b[3", self::reemit($segments));
        $this->assertStringContainsString('truncated CSI', $segments[3]->describe());
    }

    public function testBareTrailingEscKeepsItsLongStandingReport(): void
    {
        $segments = Inspector::parse("hi\x1b");

        $this->assertCount(2, $segments);
        $this->assertSame('hi', $segments[0]->raw());
        $this->assertSame("\x1b", $segments[1]->raw());
        // Exactly one bare-ESC segment: the generic tail mechanism must not
        // report the same dangling introducer a second time.
        $this->assertSame(1, array_reduce(
            $segments,
            static fn(int $carry, $segment): int => $carry + ($segment->raw() === "\x1b" ? 1 : 0),
            0,
        ));
        $this->assertSame("hi\x1b", self::reemit($segments));
    }

    public function testTerminatedStringAtEndOfStreamIsNotDoubleReported(): void
    {
        // flush() dispatches the unterminated OSC payload once; the tail
        // mechanism must not add a second copy of the same bytes.
        $segments = Inspector::parse("\x1b]0;unfinished");

        $this->assertCount(1, $segments);
        $this->assertStringContainsString('set window title', $segments[0]->describe());
    }

    // --- SP-R8: an abandoned SS3 must not steal following bytes ------------

    public function testValidSs3FinalIsStillAttached(): void
    {
        $segments = Inspector::parse("\x1bOP");

        $this->assertCount(1, $segments);
        $this->assertSame("\x1bOP", $segments[0]->raw());
        $this->assertStringContainsString('F1', $segments[0]->describe());
    }

    public function testSs3FinalRangeUpperBoundIsAccepted(): void
    {
        // ECMA-48 §5.6: Fb is 0x40–0x7E, so `~` (0x7E) is a legal final byte.
        $segments = Inspector::parse("\x1bO~");

        $this->assertCount(1, $segments);
        $this->assertSame("\x1bO~", $segments[0]->raw());
    }

    public function testByteBelowSs3FinalRangeIsNotConsumed(): void
    {
        // 0x3F `?` sits just under the Fb range: it is ordinary text.
        $segments = Inspector::parse("\x1bO?");

        $this->assertCount(2, $segments);
        $this->assertSame("\x1bO", $segments[0]->raw());
        $this->assertSame('?', $segments[1]->raw());
        $this->assertSame("\x1bO?", self::reemit($segments));
    }

    public function testAbandonedSs3DoesNotSwallowAFollowingSequenceAndText(): void
    {
        $segments = Inspector::parse("\x1bO\x1b[31mX");

        // Regression: 2 segments — a bogus `ESCOX` "SS3 X" that ate the text.
        $this->assertCount(3, $segments);
        $this->assertSame("\x1bO", $segments[0]->raw());
        $this->assertStringContainsString('SS3 O', $segments[0]->describe());
        $this->assertSame("\x1b[31m", $segments[1]->raw());
        $this->assertInstanceOf(TextSegment::class, $segments[2]);
        $this->assertSame('X', $segments[2]->raw());
        $this->assertSame("\x1bO\x1b[31mX", self::reemit($segments));
    }

    public function testAbandonedSs3BeforePlainText(): void
    {
        $segments = Inspector::parse("\x1bO hello");

        $this->assertCount(2, $segments);
        $this->assertSame("\x1bO", $segments[0]->raw());
        $this->assertSame(' hello', $segments[1]->raw());
    }

    public function testAbandonedSs3BeforeMultibyteRune(): void
    {
        $segments = Inspector::parse("\x1bOé");

        $this->assertCount(2, $segments);
        $this->assertSame("\x1bO", $segments[0]->raw());
        $this->assertSame('é', $segments[1]->raw());
        $this->assertSame("\x1bOé", self::reemit($segments));
    }

    public function testAbandonedSs3BeforeControlByte(): void
    {
        $segments = Inspector::parse("\x1bO\x07X");

        $this->assertCount(3, $segments);
        $this->assertSame("\x1bO", $segments[0]->raw());
        $this->assertSame("\x07", $segments[1]->raw());
        $this->assertStringContainsString('C0', $segments[1]->describe());
        $this->assertSame('X', $segments[2]->raw());
    }

    public function testTwoAbandonedSs3SequencesEachReportOnce(): void
    {
        $segments = Inspector::parse("\x1bO\x1bO");

        $this->assertCount(2, $segments);
        $this->assertSame("\x1bO", $segments[0]->raw());
        $this->assertSame("\x1bO", $segments[1]->raw());
    }

    public function testAbandonedSs3KeepsItsPlaceAheadOfTheTailThatFollows(): void
    {
        $cases = [
            'then a bare ESC'   => ["\x1bO\x1b", ["\x1bO", "\x1b"]],
            'then a truncated CSI' => ["\x1bO\x1b[3", ["\x1bO", "\x1b[3"]],
            'between two sequences' => ["\x1b[31m\x1bO\x1b[?25l", ["\x1b[31m", "\x1bO", "\x1b[?25l"]],
        ];

        foreach ($cases as $name => [$input, $expected]) {
            $segments = Inspector::parse($input);
            $this->assertSame($expected, array_map(static fn($s): string => $s->raw(), $segments), $name);
            $this->assertSame($input, self::reemit($segments), $name);
        }
    }

    public function testAbandonedSs3StreamingKeepsOrderAcrossChunks(): void
    {
        $inspector = new StreamingInspector();
        $first = $inspector->feed("\x1bO");
        $this->assertCount(0, $first, 'a bare ESC O stays pending until the stream decides');

        $second = $inspector->feed("\x1b[31m");
        $this->assertCount(2, $second);
        $this->assertSame("\x1bO", $second[0]->raw());
        $this->assertSame("\x1b[31m", $second[1]->raw());

        $third = $inspector->feed('X');
        $this->assertCount(0, $third, 'text is held until a sequence or end of stream');

        $tail = $inspector->finish();
        $this->assertCount(1, $tail);
        $this->assertSame('X', $tail[0]->raw());
    }

    // --- whole-stream fidelity over a corpus ------------------------------

    public function testCorpusReEmitsInputByteForByte(): void
    {
        $corpus = [
            'plain text'            => 'hello world',
            'styled run'            => "\x1b[1;31mred bold\x1b[0m plain",
            'underline spellings'   => "\x1b[4;3ma\x1b[4:3mb\x1b[m",
            'cursor + erase'        => "\x1b[2J\x1b[H\x1b[3;7H",
            'private mode'          => "\x1b[?25l\x1b[?2004h",
            'ss3 keys'              => "\x1bOP\x1bOQ\x1bOR",
            'crlf + tabs'           => "a\r\nb\tc",
            'truncated csi tail'    => "text\x1b[38;5",
            'abandoned ss3'         => "\x1bOtext",
            'decscusr intermediate' => "\x1b[6 q",
        ];

        foreach ($corpus as $name => $input) {
            $this->assertSame($input, self::reemit(Inspector::parse($input)), $name);
        }
    }

    public function testStreamingCorpusMatchesOneShotSegments(): void
    {
        $inputs = [
            "\x1b[1;4;31mhot\x1b[0m",
            "\x1bP q data\x1b\\after",
            "abc\x1b[31",
            "\x1bO\x1b[31mX",
        ];

        foreach ($inputs as $input) {
            $oneShot = Inspector::parse($input);

            // Feed one byte at a time: the worst case for tail bookkeeping.
            $inspector = new StreamingInspector();
            $streamed = [];
            for ($offset = 0, $length = strlen($input); $offset < $length; $offset++) {
                $streamed = array_merge($streamed, $inspector->feed($input[$offset]));
            }
            $streamed = array_merge($streamed, $inspector->finish());

            $this->assertSame(self::reemit($oneShot), self::reemit($streamed), strtoupper(bin2hex($input)));
            $this->assertCount(count($oneShot), $streamed, strtoupper(bin2hex($input)));
        }
    }

    public function testHandlerReusedAcrossParseCallsCarriesNoStaleTail(): void
    {
        $handler = new AnsiHandler();

        $this->assertSame("\x1b[31", self::reemit($handler->parse("\x1b[31")));
        $this->assertSame('clean', self::reemit($handler->parse('clean')));
    }
}
