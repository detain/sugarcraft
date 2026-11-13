<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiInspect\AnsiHandler;
use SugarCraft\AnsiInspect\Inspector;
use SugarCraft\AnsiInspect\SequenceSegment;
use SugarCraft\AnsiInspect\StreamingInspector;
use SugarCraft\AnsiInspect\TextSegment;

/**
 * Byte-fidelity goldens for the candy-ansi-inspect parser.
 *
 * The inspector's whole value is that what it reports is what the terminal
 * actually sent, so every case here pins exact bytes rather than merely a
 * shape. Five defect families are covered, keyed to the ANSI conformance
 * audit (`docs/research/ansi-tmux-ansicode-audit.md`) and the step 10.16
 * C1 rollout:
 *
 *  - SP-R5 parameter separators were rewritten (`ESC[4;3m` → `ESC[4:3m`).
 *  - SP-R2 a string terminator leaked out again as a ghost `ESC \` segment.
 *  - SP-R1 a CSI cut short by end-of-stream vanished without a segment.
 *  - SP-R8 an abandoned `ESC O` swallowed the printable that followed it.
 *  - SP-R9 the 8-bit C1 controls replay in 7-bit form, and DEL survives.
 *
 * @see https://www.ecma-international.org/publications-and-standards/standards/ecma-48/
 */
final class ByteFidelityTest extends TestCase
{
    /**
     * Concatenate the raw bytes of every segment — the stream the inspector
     * claims the input contained.
     *
     * @param list<\SugarCraft\AnsiInspect\Segment> $segments
     */
    private static function reemit(array $segments): string
    {
        return implode('', array_map(static fn($segment): string => $segment->raw(), $segments));
    }

    /**
     * Identity of a segment list: which class each segment is, its exact bytes and
     * its description. Stricter than re-assembly, which can hide a segment that
     * split or merged with a neighbour while preserving the concatenated stream.
     *
     * @param list<\SugarCraft\AnsiInspect\Segment> $segments
     * @return list<string>
     */
    private static function fingerprint(array $segments): array
    {
        return array_map(
            static fn($segment): string => get_class($segment) . '|' . $segment->raw() . '|' . $segment->describe(),
            $segments
        );
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

        // The exact bytes candy-codeshot resolves to `#000001` by falling back to
        // the flat parameter list. Here the same input is labelled for what it
        // says and never guessed into a colour, which is the divergence both
        // libs' docblocks promise is pinned on each side — so pin it literally,
        // not merely as one of the split-invariance corpus inputs.
        $segments = Inspector::parse("\x1b[38:2::;1;2;3m");

        $this->assertCount(1, $segments);
        $this->assertSame("\x1b[38:2::;1;2;3m", $segments[0]->raw());
        $this->assertStringContainsString('foreground truncated truecolor', $segments[0]->describe());
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

    public function testTerminatorWindowClosesOnAnyOtherByte(): void
    {
        // An OSC already closed by BEL must not leave the window open for some
        // later standalone `ESC \` — the old `oscInProgress` latch did exactly
        // that and swallowed the terminator forever.
        $afterBel = Inspector::parse("\x1b]0;T\x07\x1b\\B");
        $this->assertCount(3, $afterBel);
        $this->assertSame("\x1b]0;T\x07", $afterBel[0]->raw());
        $this->assertSame("\x1b\\", $afterBel[1]->raw());
        $this->assertSame('B', $afterBel[2]->raw());
        $this->assertSame("\x1b]0;T\x07\x1b\\B", self::reemit($afterBel));

        // Same for a DCS closed by the 8-bit ST (0x9C): the `ESC \` that
        // follows is its own sequence, not the terminator of a finished string.
        $afterC1 = Inspector::parse("\x1bP q A\x9c\x1b\\B");
        $this->assertCount(3, $afterC1);
        $this->assertSame("\x1b\\", $afterC1[1]->raw());
        $this->assertSame('B', $afterC1[2]->raw());
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
        // The documented normalisation: an OSC ended by the stream instead of by
        // BEL or ST is re-emitted with a BEL terminator, so the byte account is
        // exact and pinned here rather than only implied by the count.
        $this->assertSame("\x1b]0;unfinished\x07", $segments[0]->raw());
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
            'c1 csi introducer'     => "\x9b31mred\x9b0m",
        ];

        foreach ($corpus as $name => $input) {
            $this->assertSame($input, self::reemit(Inspector::parse($input)), $name);
        }
    }

    public function testStringFamilyC1IntroducersReEmitInSevenBitNormalisedForm(): void
    {
        // Deviation 9's enumerated half of finding 058: the OSC/SOS/PM/APC
        // dispatchers normalise their 8-bit introducer (and C1 ST) to the
        // 7-bit spelling, so those inputs are NOT byte-for-byte corpora —
        // they are pinned here to the exact normalised shape the docs
        // promise, keeping the whole fidelity sweep C1-covered.
        $normalisations = [
            "\x9d0;title\x07" => "\x1b]0;title\x07",
            // ST-pending DCS keeps the template rebuild (deviation 9): the
            // 'q' intermediate's slot is not carried into the 7-bit form,
            // which is exactly the documented non-verbatim territory.
            "\x90q abc\x9c"   => "\x1bP abc\x1b\\",
            "\x98ab\x9c"      => "\x1bXab\x1b\\",
            "\x9fcd\x1b\\"    => "\x1b_cd\x1b\\",
        ];

        foreach ($normalisations as $input => $expected) {
            $this->assertSame($expected, self::reemit(Inspector::parse($input)), strtoupper(bin2hex($input)));
        }
    }

    public function testStreamingCorpusMatchesOneShotSegments(): void
    {
        $inputs = [
            "\x1b[1;4;31mhot\x1b[0m",
            "\x1bP q data\x1b\\after",
            "abc\x1b[31",
            "\x1bO\x1b[31mX",
            "\x9b31mred\x9b0m",
            "\x9d1;t\x07",
            "\x90q data\x1b\\",
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

    /**
     * The handler drives the parser one byte at a time, so where a chunk happens to
     * end must be irrelevant. The test above covers the two extremes — the whole
     * stream at once, and one byte per call; this pins everything in between: every
     * single split point of every corpus input, plus deterministic 2- to 5-way
     * splits, compared on segment class, raw bytes and description rather than on
     * the reassembled stream alone. Anything whose result depends on where a chunk
     * ends — the one-byte terminator window above all — surfaces here at the exact
     * boundary that broke. A slip in the in-flight byte window that corrupts the
     * one-shot parse just as badly is invisible to a differential test like this
     * one; the absolute raw pins elsewhere in this file own that case.
     */
    public function testChunkBoundariesDoNotChangeTheSegmentList(): void
    {
        $corpus = [
            "\x1b[31;-2m\x1b[3",
            "text\x1b[4;3mmixed separators\x1b[0m",
            "\x1b[1;4:3;31m\x1b[;31mempty slots\x1b[m",
            "\x1b[38:2::80:160:240mtruecolor\x1b[38:2:1:2:3mcount rule\x1b[0m",
            "\x1bP q 0;1m\x1b\\status\x1b]0;title\x1b\\done",
            "\x1bP1:2;3 data\x1b\\after",
            "abc\x1b[31",
            "\x1b[?1",
            "\x1b/",
            "\x1bP1",
            "\x1bO\x1b[31mX",
            "\x1bOP\x1bO hello\x1b[A",
            "\x1b[31mA\x1b\\lone st\x1b\\again",
            "\x1b]0;T\x07\x1b\\B",
            "\x1bX\x1b\\\x1b_\x1b\\\x1b^pm\x1b\\",
            "caf\xc3\xa9\x1b[32mgreen\x1b[0mna\xc3\xafve",
            "\x1b[38:2::;1;2;3mhybrid\x1b[3m",
            "\x1b[1:2:3p\x1b[?1;2:3c",
        ];

        foreach ($corpus as $input) {
            $expected = self::fingerprint(Inspector::parse($input));
            $length = strlen($input);
            $splits = [];

            for ($offset = 1; $offset < $length; $offset++) {
                $splits[] = [$offset];
            }

            for ($parts = 2; $parts <= 5; $parts++) {
                $points = [];
                for ($part = 1; $part < $parts; $part++) {
                    $points[] = intdiv($length * $part, $parts);
                }
                $splits[] = array_values(array_unique($points));
            }

            foreach ($splits as $points) {
                $inspector = new StreamingInspector();
                $streamed = [];
                $offset = 0;
                foreach ($points as $boundary) {
                    $streamed = array_merge($streamed, $inspector->feed(substr($input, $offset, $boundary - $offset)));
                    $offset = $boundary;
                }
                $streamed = array_merge($streamed, $inspector->feed(substr($input, $offset)));
                $streamed = array_merge($streamed, $inspector->finish());

                $this->assertSame(
                    $expected,
                    self::fingerprint($streamed),
                    strtoupper(bin2hex($input)) . ' split at ' . implode(',', $points)
                );
            }
        }
    }

    public function testHandlerReusedAcrossParseCallsCarriesNoStaleTail(): void
    {
        $handler = new AnsiHandler();

        $this->assertSame("\x1b[31", self::reemit($handler->parse("\x1b[31")));
        $this->assertSame('clean', self::reemit($handler->parse('clean')));
    }

    // --- the public driving surface, used directly ------------------------

    public function testHandlerFeedAndFinishCanBeDrivenWithoutAnInspector(): void
    {
        // `feed()`/`finish()` are public so a front-end is not obliged to go
        // through StreamingInspector; they must hold text and report the tail
        // exactly as the inspector-driven path does.
        $handler = new AnsiHandler();
        $handler->feed('red' . "\x1b[31");

        $this->assertSame([], $handler->drainSegments(), 'text is held until a sequence or end of stream');

        $handler->finish();
        $segments = $handler->drainSegments();

        $this->assertCount(2, $segments);
        $this->assertSame('red', $segments[0]->raw());
        $this->assertSame("\x1b[31", $segments[1]->raw());
        $this->assertSame('red' . "\x1b[31", self::reemit($segments));
    }

    public function testFinishIsIdempotentOnAnEmptyTail(): void
    {
        $handler = new AnsiHandler();
        $handler->feed("\x1b[31");
        $handler->finish();

        $this->assertCount(1, $handler->drainSegments());
        // Nothing left in flight: a second finish() must invent no segment.
        $handler->finish();
        $this->assertSame([], $handler->drainSegments());
    }

    public function testDescribeTruncatedNamesTheFamilyFromTheIntroducer(): void
    {
        $cases = [
            "\x1b[3" => 'truncated CSI (unterminated)',
            "\x1bP1" => 'truncated DCS (unterminated)',
            "\x1bO"  => 'truncated SS3 (unterminated)',
            "\x1bX"  => 'truncated SOS (unterminated)',
            "\x1b^"  => 'truncated PM (unterminated)',
            "\x1b_"  => 'truncated APC (unterminated)',
            "\x1b]"  => 'truncated OSC (unterminated)',
            "\x1b/"  => 'truncated ESC (unterminated)',
        ];

        foreach ($cases as $bytes => $expected) {
            $this->assertSame($expected, Inspector::describeTruncated($bytes), strtoupper(bin2hex($bytes)));
        }
    }

    // --- SP-R5 beyond CSI: the same replay rule applies to DCS parameters ----

    public function testDcsParametersKeepTheirOwnSeparators(): void
    {
        // `Action::Param` in sugar-ansi-parser feeds DCS sub-parameters through the
        // very same slot/flag arrays as a CSI, so a colon inside a DCS prelude
        // must survive exactly like one inside an SGR — and an omitted
        // parameter must stay an empty slot rather than the literal `-1` master
        // printed.
        //
        // The expected bytes still show the one documented pre-existing
        // normalisation: a re-emitted DCS drops the prelude's final byte (`q`
        // here), which is outside this PR and recorded in CALIBER_LEARNINGS.
        // The `plain semicolons` case is a regression guard rather than a fix —
        // a single non-empty parameter needs no separator, so master reproduced
        // that one too.
        $cases = [
            'colon sub-parameters' => ["\x1bP1:2q data\x1b\\", "\x1bP1:2 data\x1b\\"],
            'omitted parameter' => ["\x1bP;5q data\x1b\\", "\x1bP;5 data\x1b\\"],
            'plain semicolons' => ["\x1bP1;2q data\x1b\\", "\x1bP1;2 data\x1b\\"],
            'mixed separators' => ["\x1bP0;1:2;3q data\x1b\\", "\x1bP0;1:2;3 data\x1b\\"],
        ];

        foreach ($cases as $label => [$input, $expected]) {
            $raw = Inspector::parse($input)[0]->raw();
            $this->assertSame($expected, $raw, $label);
            $this->assertStringNotContainsString('-1', $raw, $label);
        }
    }

    public function testDcsPreludeRebuildHoistsTheIntermediateAheadOfTheParameters(): void
    {
        // The other half of the documented DCS lossiness, pinned rather than
        // merely mentioned: sugar-ansi-parser collects `1` as a parameter and `$` as an
        // intermediate, but `dcsDispatch()` rebuilds the prelude as
        // `intermediate . params`, so the hoist and the dropped prelude final
        // byte (`r`) always travel together. `ESC P 1 $ r ST` arrives as
        // `ESC P $ 1 ST` — reordered and one byte shorter, but nothing lost
        // without a segment to show for it.
        $input = "\x1bP1\$r\x1b\\";

        $segments = Inspector::parse($input);

        $this->assertCount(1, $segments);
        $this->assertSame("\x1bP\$1\x1b\\", $segments[0]->raw());
        $this->assertNotSame($input, $segments[0]->raw());
    }

    // --- documented limit: sugar-ansi-parser's 32-parameter cap --------------------

    public function testParameterCapIsInvisibleInTheVerbatimCsiReplay(): void
    {
        // `Parser::MAX_PARAMS` is 32: past the cap the separator is dropped and
        // further digits keep accumulating into the last slot, so `32;33`
        // reaches the dispatch fields as the single parameter `3233`. Since the
        // window-tail rebuild (finding 016) the report reads the wire bytes
        // instead: the raw column replays all 33 parameters exactly as sent —
        // the parser's internal merge is no longer observable in a CSI at all.
        $input = "\x1b[" . implode(';', range(1, 33)) . 'm';
        $segments = Inspector::parse($input);

        $this->assertCount(1, $segments);
        $this->assertSame($input, $segments[0]->raw(), 'the window replays the sender\'s bytes verbatim');
        $this->assertSame(strlen($input), strlen($segments[0]->raw()));

        // At the cap itself the round-trip is still exact.
        $within = "\x1b[" . implode(';', range(1, 32)) . 'm';
        $this->assertSame($within, Inspector::parse($within)[0]->raw());
    }

    public function testCancelledSequenceIsLostExactlyAsTheParserLosesIt(): void
    {
        // Candy-ansi cancels a CSI on an illegal parameter byte, on the
        // anywhere-ESC edge, or on CAN/SUB and discards the sequence in flight;
        // no segment is ever invented for a sequence that never dispatched.
        // The handler's byte window, however, kept every fed byte, so the
        // next dispatch re-reads it and reports the abandoned prelude ahead of
        // itself as a `truncated … — cancelled` segment (AIP-01; see
        // testEscCancelledSequenceIsReportedAsCancelledResidue for the pure
        // ESC-cancel shapes). The shapes below pin the asymmetric survivors:
        // a cancel whose residue never reaches a later dispatch or tail still
        // ends unreported (`mX` cases), because CAN's `execute` callback
        // flushes the window and end-of-stream in Ground drops it — README
        // deviation 7's documented floor. A cancelled prelude followed by a
        // truncated tail replays inside that tail (nothing told the handler
        // to reset the window); a cancel followed by CAN does not, because
        // `execute` flushes the window and an illegal byte leaves no callback
        // behind. The truncated tail itself is exactly what `master` dropped
        // (SP-R1) — on `master` the two last inputs yielded only `m` and only
        // CAN. A deliberate guard on the `inFlightBytes` bookkeeping.
        $cases = [
            // Cancelled mid-CSI; the final byte falls through to Ground as text.
            "\x1b[31;-2mX" => ['mX'],
            "\x1b[-1m" => ['m'],
            "\x1b[38:2::-1:2:3m" => [':2:3m'],
            // CAN/SUB is executable, so it survives as its own C0 segment.
            "\x1b[31\x18mX" => ["\x18", 'mX'],
            "\x1b[31\x1amX" => ["\x1a", 'mX'],
            // The case that actually guards the byte account: after a cancellation
            // the next sequence must still report cleanly — and from AIP-01 on it
            // also reports the cancelled residue ahead of itself, so no byte of
            // the discarded prelude is lost.
            "\x1b[31;-2m\x1b[32mY" => ["\x1b[31;-2", 'm', "\x1b[32m", 'Y'],
            // A cancel followed by a truncated CSI replays the cancelled prelude in
            // the tail report; a cancel followed by CAN does not, because `execute`
            // flushes the window and an illegal byte leaves no callback behind.
            "\x1b[31;-2m\x1b[3" => ['m', "\x1b[31;-2m\x1b[3"],
            "\x1b[31\x18\x1b[3" => ["\x18", "\x1b[3"],
        ];

        foreach ($cases as $input => $expected) {
            $segments = Inspector::parse($input);

            $this->assertSame(
                $expected,
                array_map(static fn($segment): string => $segment->raw(), $segments),
                bin2hex($input),
            );
        }
    }

    public function testEscCancelledSequenceIsReportedAsCancelledResidue(): void
    {
        // AIP-01 / finding 001: the anywhere-ESC edge cancels an in-flight
        // CSI/DCS with no handler callback, but the byte window holds the
        // abandoned prelude until the next dispatch reads it back. Every byte
        // of every shape below reaches the report: a `truncated … — cancelled`
        // residue segment ordered ahead of the dispatch that revealed it.
        $cases = [
            "\x1b[31\em"      => ["\x1b[31", "\x1bm"],
            "\x1b[?1;2\x1bZ"  => ["\x1b[?1;2", "\x1bZ"],
            "\x1bP12;3\x1bX"  => ["\x1bP12;3", "\x1bX"],
            "\x1b[1;2\x1b7"   => ["\x1b[1;2", "\x1b7"],
        ];

        foreach ($cases as $input => $expected) {
            $segments = Inspector::parse($input);

            $this->assertSame(
                $expected,
                array_map(static fn($segment): string => $segment->raw(), $segments),
                bin2hex($input),
            );
            $this->assertSame(
                $input,
                implode('', array_map(static fn($segment): string => $segment->raw(), $segments)),
                bin2hex($input) . ' byte-for-byte',
            );
            $this->assertStringEndsWith(
                ' — cancelled',
                $segments[0]->describe(),
                bin2hex($input) . ' residue is labelled cancelled',
            );
        }
    }

    public function testTrailingBareEscReportsCancelledAndSwallowedWindowBytes(): void
    {
        // AIP-11 / finding 010: finishPending() emitted the dangling ESC and
        // dropped the rest of the window — the sequence the ESC had cancelled
        // ahead of it, and any byte the parser swallowed after it (0x7F stays
        // Action::None in Escape). Every window byte now keeps its own segment
        // in stream order, so a tail conserves 100 % of its bytes.
        $cases = [
            'cancelled CSI ahead of the ESC' => ["\x1b[1;2\x1b", ["\x1b[1;2", "\x1b"]],
            'abandoned SS3, ESC, ignored DEL' => ["\x1bO\x1b\x7f", ["\x1bO", "\x1b", "\x7f"]],
            'pending text, cancelled CSI, ESC' => ["ok\x1b[31\x1b", ["ok", "\x1b[31", "\x1b"]],
        ];

        foreach ($cases as $name => [$input, $expected]) {
            $segments = Inspector::parse($input);

            $this->assertSame(
                $expected,
                array_map(static fn($segment): string => $segment->raw(), $segments),
                $name,
            );
            $this->assertSame($input, self::reemit($segments), $name . ' byte-for-byte');
        }

        $segments = Inspector::parse("\x1b[1;2\x1b");
        $this->assertStringEndsWith(
            ' — cancelled',
            $segments[0]->describe(),
            'the prelude the ESC cancelled is labelled cancelled',
        );
        $this->assertStringStartsWith('ESC ', $segments[1]->describe());
    }

    public function testMidSequenceC0AndC1ExecuteKeepTheirPrelude(): void
    {
        // AIP-10 / finding 009: an executed C0/C1 byte mid-sequence used to
        // discard the byte window with it, losing the sequence's bytes at EOF.
        // A C0 that is not a cancel leaves the parser in its control-sequence
        // state, so the window keeps the prelude for the tail report; a C1
        // control does cancel the sequence, so the prelude is replayed ahead
        // of the C1 segment. CAN/SUB keep README deviation 7's floor (last
        // two cases — their prelude stays invisible, and no byte is
        // double-counted by this test).
        $cases = [
            "\x1b[1;\r"           => ["\r", "\x1b[1;"],
            "\x1b[1;\rx"          => ["\r", "\x1b[1;x"],
            "hi\x1b[1;\r"         => ["hi", "\r", "\x1b[1;"],
            "\x1b[31\x84X"        => ["\x1b[31", "\x84", "X"],
            "\x1b[31\x9c"         => ["\x1b[31", "\x9c"],
            // Unmoved floors: the CAN-cancel prelude stays unreported.
            "\x1b[1;\r\x18"       => ["\r", "\x18"],
            "\x1b[31\x1amX"        => ["\x1a", "mX"],
        ];

        foreach ($cases as $input => $expected) {
            $segments = Inspector::parse($input);

            $this->assertSame(
                $expected,
                array_map(static fn($segment): string => $segment->raw(), $segments),
                bin2hex($input),
            );
        }
    }

    public function testCancelByteInsideStringStateDispatchesTheTruncatedPayload(): void
    {
        // 2026-10-23 (r1024/inspect, README deviation 11): sugar-ansi-parser
        // commit 35dda61c0 moved CAN/SUB inside the *string* states (OSC,
        // DCS-passthrough, SOS/PM/APC) from silently dropping the whole
        // sequence to dispatching the payload collected so far. The cancel
        // byte itself is CONSUMED - it never appears in a segment - which
        // is the asymmetry against the control-sequence states above: a
        // CAN in a CSI prelude or a DCS prelude is executable and echoes
        // as its own C0 segment (last case). The OSC raw keeps the
        // handler's BEL normalization; nothing else moved.
        $cases = [
            // CAN mid-OSC: truncated payload dispatches, cancel vanishes.
            "\x1b]0;a\x18b\x07" => ["\x1b]0;a\x07", 'b', "\x07"],
            // CAN mid-DCS-data: same shape, ST terminator re-emitted.
            "\x1bP q data\x18tail\x1b\\" => ["\x1bP  data\x1b\\", 'tail', "\x1b\\"],
            // SUB mid-SOS: same shape with the other cancel byte.
            "\x1bXabc\x1ad\x1b\\" => ["\x1bXabc\x1b\\", 'd', "\x1b\\"],
            // OSC cut by SUB with no terminator at all: the dispatch at
            // cancel re-arms the BEL normalization, then 'b' lands in Ground.
            "\x1b]0;a\x1ab" => ["\x1b]0;a\x07", 'b'],
            // Contrast (deviation 7, unmoved): CAN in the DCS *prelude* -
            // a control-sequence state - still echoes and discards.
            "\x1bP\x18x\x1b\\" => ["\x18", 'x', "\x1b\\"],
        ];

        $stringStateCases = array_slice($cases, 0, 4, preserve_keys: true);
        foreach ($cases as $input => $expected) {
            $segments = Inspector::parse($input);

            $this->assertSame(
                $expected,
                array_map(static fn($segment): string => $segment->raw(), $segments),
                bin2hex($input),
            );
        }

        foreach ($stringStateCases as $input => $expected) {
            $concat = implode('', $expected);
            $this->assertStringNotContainsString("\x18", $concat, bin2hex($input));
            $this->assertStringNotContainsString("\x1a", $concat, bin2hex($input));
        }
    }

    public function testTruncatedUtf8TailIsDroppedExactlyAsTheParserDropsIt(): void
    {
        // The one byte loss left in the contract, pinned so that a future fix is a
        // deliberate change rather than a surprise. Candy-ansi holds an incomplete
        // UTF-8 rune in its own buffer and `Parser::flush()` never dispatches it,
        // because that is not an escape-sequence state - so a stream that ends
        // mid-rune loses those bytes, with or without a sequence in front of them.
        // Byte-identical to `master`; the fix belongs in sugar-ansi-parser (README.md).
        $cases = [
            "\x1b[31m\x1b[0m\xfe" => ["\x1b[31m", "\x1b[0m"],
            "\x1b[31m\x1b[0m\xfeZ" => ["\x1b[31m", "\x1b[0m", 'Z'],
            "caf\xc3\xa9 \xe2" => ["café "],
            "x\xc3" => ['x'],
            "\x1b[31m\xc3" => ["\x1b[31m"],
        ];

        foreach ($cases as $input => $expected) {
            $segments = Inspector::parse($input);

            $this->assertSame(
                $expected,
                array_map(static fn($segment): string => $segment->raw(), $segments),
                bin2hex($input),
            );
        }
    }

    public function testMidStreamInvalidUtf8BytesAreDroppedExactlyAsTheParserDropsThem(): void
    {
        // Deviation #10: item 8's end-of-stream truncation has a mid-stream twin —
        // sugar-ansi-parser's UTF-8 window rejects a stray lead (0xF5–0xFF and friends), a
        // lead interrupted by a non-continuation byte, and a lone continuation byte,
        // dropping the offending byte(s) with no callback, so no segment can exist
        // for them. The interrupting/surrounding bytes survive normally. Surfacing
        // these needs a new sugar-ansi-parser callback (deferred); pinned meanwhile.
        $cases = [
            "a\xffb" => ['ab'],          // stray lead 0xFF dies, text merges
            "a\xf5b" => ['ab'],          // 0xF5: first invalid lead
            "a\xb0b" => ['ab'],          // lone continuation byte, no lead
            "a\xc3(b" => ['a(b'],        // lead interrupted; `(` re-prints
            "a\xc3\xffb" => ['ab'],      // lead dies, then 0xFF dies too
            "a\xe2\xe2Ab" => ['aAb'],    // second lead abandons the first rune
            "\x1b[31m\xffm" => ["\x1b[31m", 'm'], // sequences unaffected around it
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame(
                $expected,
                array_map(static fn($segment): string => $segment->raw(), Inspector::parse($input)),
                bin2hex($input),
            );
        }

        // Polarity: the C1 controls sugar-ansi-parser EXECUTES are not lost — they
        // arrive as their own sequence segments, which is why the deviation is
        // confined to what the UTF-8 window rejects.
        $this->assertSame(
            ['a', "\x85", 'b'],
            array_map(static fn($segment): string => $segment->raw(), Inspector::parse("a\x85b")),
        );
    }

    public function testIgnoredControlBytesAndParserCapsArriveAsTheParserRewroteThem(): void
    {
        // Remaining parser-policy shapes pinned so a future change to the
        // shared parser must pass through this contract:
        //  - Parser::put() refuses payload past MAX_STRING_BUFFER (64 KiB), so a
        //    long OSC arrives cut at exactly that length with its terminator
        //    intact (string payloads are re-emitted from the dispatch fields,
        //    not the window);
        //  - OscString maps C0 (0x00-0x06, 0x08-0x17, 0x19, 0x1C-0x1F) to `None`,
        //    so an ignored control byte vanishes from an OSC payload - while the
        //    very same byte survives in a DCS, SOS, PM or APC payload.
        // The two parameter caps (32 params, 65535 value) used to live here:
        // the window-tail rebuild (finding 016) makes them invisible in a CSI —
        // the wire bytes arrive verbatim — while a DCS prelude still rebuilds
        // through the parser fields (deviation 2's family).
        $this->assertSame("\x1b[99999m", Inspector::parse("\x1b[99999m")[0]->raw());
        $this->assertSame(
            "\x1b[38;2;99999;0;0m",
            Inspector::parse("\x1b[38;2;99999;0;0m")[0]->raw(),
        );

        $cap = 65536;
        // The buffer holds "0;" plus the payload, so two of its slots are taken by
        // the parameter text and 65534 x's fit before the remaining two are refused.
        $oversized = "\x1b]0;" . str_repeat('x', $cap + 2) . "\x07";
        $this->assertSame(
            "\x1b]0;" . str_repeat('x', $cap - 2) . "\x07",
            Inspector::parse($oversized)[0]->raw(),
        );

        $this->assertSame(
            ["\x1b]0;ab\x07"],
            array_map(static fn($segment): string => $segment->raw(), Inspector::parse("\x1b]0;a\x02b\x07")),
        );

        foreach (['P' => 'DCS', 'X' => 'SOS', '^' => 'PM', '_' => 'APC'] as $introducer => $family) {
            $raw = Inspector::parse("\x1b{$introducer}0;a\x02b\x1b\\")[0]->raw();

            $this->assertStringContainsString("\x02", $raw, $family . ' must pass the C0 byte through');
        }
    }

    // --- ambiguous truecolour spellings: the count rule both libs share -----

    public function testAmbiguousColonTruecolourUsesTheCountRule(): void
    {
        // xterm allows the colour-space id slot to be omitted *entirely*, so a
        // group of three sub-parameters after the mode is bare R:G:B and a group
        // of four is CS:R:G:B. candy-ansi-inspect's label and candy-codeshot's resolved
        // colour apply the same rule to the same bytes; pinning the reading here
        // keeps the two parsers from drifting apart silently.
        $cases = [
            "\x1b[38:2:1:2:3m" => 'SGR foreground rgb(1,2,3)',
            "\x1b[38:2:1:2:3:4m" => 'SGR foreground rgb(2,3,4)',
            "\x1b[38:2::1:2:3m" => 'SGR foreground rgb(1,2,3)',
            "\x1b[38:2:::1:2:3m" => 'SGR foreground rgb(1,2,3)',
            "\x1b[38:2:1m" => 'SGR foreground truncated truecolor',
        ];

        foreach ($cases as $input => $expected) {
            $segments = Inspector::parse($input);
            $this->assertSame($input, $segments[0]->raw());
            $this->assertStringContainsString($expected, $segments[0]->describe(), $input);
        }
    }

    // --- SP-R9: the C1 controls (step 10.16) --------------------------------

    public function testDelByteSurvivesTheStreamByteForByte(): void
    {
        // DEL (0x7F) used to reach execute(), match no branch, and vanish —
        // the fidelity contract says anything not on the deviations list that
        // loses a byte is a bug, and this was one.
        $this->assertSame("ab\x7fcd", self::reemit(Inspector::parse("ab\x7fcd")));
    }

    public function testRawColumnCannotForgeSegmentLines(): void
    {
        // AIP-02 / finding 002: the raw column of a report line used to emit
        // captured bytes verbatim, so a DCS payload carrying CR LF + a fake
        // `ESC[0m  SGR reset` forged a byte-shape-identical segment line, and
        // an OSC payload carrying C1 bytes replayed them at the terminal.
        // Both probes now arrive as ONE line with named tokens only.
        $forge = "\x1bP q abc\r\n\x1b[0m  SGR reset -- clean\r\ntail\x1b\\";
        $segments = Inspector::parse($forge);
        $report = Inspector::report($forge);
        $this->assertStringNotContainsString("\r", $report);
        $this->assertCount(count($segments), explode("\n", $report), 'exactly one report line per segment — nothing forged extra');

        $eightBit = "\x1b]0;x\x8d\x88y\x07";
        $report = Inspector::report($eightBit);
        $this->assertStringNotContainsString("\x8d", $report);
        $this->assertStringNotContainsString("\x88", $report);
        $this->assertStringContainsString('RI', $report);
        $this->assertStringContainsString('HTS', $report);

        // The bytes survive: it is the DISPLAY that tokenizes, `raw()` of the
        // segment keeps everything the sender sent (deviations aside).
        $segments = Inspector::parse($forge);
        $this->assertStringContainsString("\r", implode('', array_map(static fn($s): string => $s->raw(), $segments)));
    }

    public function testEightBitCsiIntroducerReplaysItsVerbatimByte(): void
    {
        // Formerly deviation #9's CSI leg: the dispatch callback hands the
        // handler only (final, params, …) and folds the introducer choice
        // away, so the old ESC [ template replayed `\x9b31m` as `ESC [ 31 m`
        // — bytes the sender did not use. The window-tail rebuild (finding
        // 016) reads the feed window instead: the 0x9B byte arrives verbatim
        // in raw(), and only the DISPLAY column tokenises it (AIP-02).
        $this->assertSame(
            [SequenceSegment::class . "|\x9b31m|CSI31m  SGR foreground red"],
            self::fingerprint(Inspector::parse("\x9b31m")),
        );
    }

    public function testEightBitSosAndPmCarryTheirPayloadThroughTheSevenBitReplay(): void
    {
        $this->assertSame(
            [SequenceSegment::class . "|\x1bXabc\x1b\\|ESCXabcESC\\  SOS string (3 bytes)"],
            self::fingerprint(Inspector::parse("\x98abc\x9c")),
        );
        $this->assertSame(
            [SequenceSegment::class . "|\x1b^priv\x1b\\|ESC^privESC\\  PM string (4 bytes)"],
            self::fingerprint(Inspector::parse("\x9epriv\x9c")),
        );
    }

    public function testUnterminatedEightBitTailsKeepTheirRawByteAndNameTheirFamily(): void
    {
        // The rewrite of deviation #9 applies to DISPATCHED sequences only. A
        // tail reports the bytes in flight verbatim in `raw()` — and since
        // step 10.16 it names the family from the 8-bit introducer instead of
        // the generic "escape". Since AIP-02 the DISPLAY column tokenizes the
        // raw C1 byte (`CSI`, `DCS`) exactly like the label sanitizer: the
        // bytes are preserved in the segment, never replayed at the terminal.
        $this->assertSame(
            [SequenceSegment::class . "|\x9b|CSI  truncated CSI (unterminated)"],
            self::fingerprint(Inspector::parse("\x9b")),
        );
        $this->assertSame(
            [SequenceSegment::class . "|\x90|DCS  truncated DCS (unterminated)"],
            self::fingerprint(Inspector::parse("\x90")),
        );
    }

    public function testCollapsedParserSlotsReplayTheWireBytesVerbatim(): void
    {
        // Finding 016 in all four CSI shapes: sugar-ansi-parser keeps one
        // prefix byte in its `cmd` slot, one intermediate in another, and the
        // CsiParam rows drop 0x3C-0x3F after the first marker — the old
        // `ESC [` template rebuild then reported sequences the sender never
        // wrote (`ESC[1<2m` came back as `ESC[12m`, a fabricated parameter
        // merge). The window tail IS the sequence: stacked markers, mid-body
        // DEL bytes and extra intermediates survive verbatim, and the label
        // reads the interior bytes too — an honest `SGR-marker` name beats a
        // tidy fabrication.
        $shapes = [
            "\x1b[1<2m",
            "\x1b[1\x7f2m",
            "\x1b[=?5m",
            "\x1b[1/!m",
        ];
        foreach ($shapes as $wire) {
            $segments = Inspector::parse($wire);
            $this->assertCount(1, $segments, bin2hex($wire));
            $this->assertSame($wire, $segments[0]->raw(), bin2hex($wire));
            $this->assertStringContainsString('SGR-marker', $segments[0]->describe(), bin2hex($wire));
        }
    }

    public function testEscIntermediateBeforeOIsNotBufferedAsSs3(): void
    {
        // Finding 018: `ESC <intermediate> O` used to store the intermediate
        // INSTEAD of the `O` and arm an SS3 — `ESC / O P` came back as a
        // fabricated `ESC/P` labelled F1, an SS3 shape never sent and a lost
        // byte. Only the bare `ESC O` (ECMA-48 SS3) arms now; the
        // intermediate-bearing shape reports its verbatim window bytes under
        // its honest 2-byte-ESC name and the following bytes stay text.
        $segments = Inspector::parse("\x1b/OP");

        $this->assertCount(2, $segments);
        $this->assertSame("\x1b/O", $segments[0]->raw());
        $this->assertStringContainsString('ESC / O', $segments[0]->describe());
        $this->assertSame('P', $segments[1]->raw());
        $report = Inspector::report("\x1b/OP");
        $this->assertStringNotContainsString('F1', $report, 'no phantom SS3 function key');
        // Polarity: the real SS3 still arms.
        $this->assertSame("\x1bOP", Inspector::parse("\x1bOP")[0]->raw());
    }

    public function testCrossTypeC1JumpCancelsTheOpenStringWithAReport(): void
    {
        // Finding 017: opening a second string family mid-string
        // (here OSC 0x9D inside a live SOS) makes the parser discard the
        // in-flight payload with NO dispatch — the account law says a
        // discarded sequence must at least be reported. The OSC's 0x9D twin
        // candidate now anchors the window tail, so the 9 abandoned SOS bytes
        // surface ahead of the OSC as a cancelled segment; the OSC itself is
        // re-emitted from its fields (the string-family template, README
        // deviations 1 and 9).
        $segments = Inspector::parse("\x1bXhello\x9d1;t\x1b\\");

        $this->assertCount(2, $segments);
        $this->assertSame("\x1bXhello", $segments[0]->raw());
        $this->assertStringContainsString('truncated SOS (unterminated) — cancelled', $segments[0]->describe());
        $this->assertSame("\x1b]1;t\x07", $segments[1]->raw());
        $this->assertStringContainsString('set icon name', $segments[1]->describe());
    }

    public function testBelTerminatedDcsReplaysVerbatimWhileItsStSpellingKeepsTheTemplate(): void
    {
        // The DCS half of finding 016 splits on the terminator the sender
        // used. A BEL-terminated body has fully arrived when the dispatch
        // fires, so the window replays the true prelude — dropped `=` marker
        // and the BEL itself included. An ST-terminated body dispatches on
        // the ESC of `ESC \` with the backslash still unseen, so that family
        // keeps the disclosed template re-emission (README deviation 2:
        // hoisted intermediate, dropped prelude final) — pinned, not hidden.
        $this->assertSame(
            "\x1bP<=q\x07",
            Inspector::parse("\x1bP<=q\x07")[0]->raw(),
        );
        $stTemplate = Inspector::parse("\x1bP<=q\x1b\\")[0]->raw();
        $this->assertSame("\x1bP<\x1b\\", $stTemplate, 'documented re-emission: = lost, ST appended');
    }
}
