<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiInspect\Inspector;
use SugarCraft\AnsiInspect\SequenceSegment;
use SugarCraft\AnsiInspect\StreamingInspector;
use SugarCraft\AnsiInspect\TextSegment;

/**
 * Byte-offset reporting (step AIP-40, finding 019).
 *
 * Every segment the parser produces carries the absolute stream position of
 * its first input byte — the column sequin prints by default, which lets a
 * line from a multi-megabyte capture be located even when its normalised
 * `raw()` bytes no longer grep-match the input (README fidelity items 1-2).
 * The offsets are measured against the FEED CLOCK inside AnsiHandler, never
 * derived from `raw()` lengths: a normalised OSC reports one terminator byte
 * while consuming two, and an abandoned CAN/SUB prelude is skipped (not
 * invented) — both shapes keep every surviving segment at its true position.
 */
final class ByteOffsetTest extends TestCase
{
    /**
     * Clean shapes: every input byte belongs to exactly one segment, so the
     * offsets must tile the input — start at 0, advance by the previous
     * segment's exact consumed bytes (for these shapes that equals
     * strlen(raw())), and end flush at the input length.
     */
    public static function cleanShapeProvider(): array
    {
        return [
            'text only'            => ['hello'],
            'csi wraps text'       => ["hi\x1b[31mred\x1b[0m!"],
            'osc bel'              => ["\x1b]0;t\x07after"],
            'multiple csis'        => ["\x1b[1m\x1b[31mbold red\x1b[0m"],
            'ss3 mid-stream'       => ["a\x1bOPb"],
            'x10 mouse'            => ["\x1b[M !!tail"],
            'c0 executes in text'  => ["a\nb\x7fc"],
        ];
    }

    /**
     * @dataProvider cleanShapeProvider
     */
    public function testOffsetsTileTheInputForCleanShapes(string $input): void
    {
        $segments = Inspector::parse($input);
        $this->assertNotSame([], $segments);

        $expected = 0;
        foreach ($segments as $segment) {
            $this->assertSame(
                $expected,
                $segment->byteOffset(),
                'segment ' . json_encode($segment->raw()) . ' does not start where the previous one ended',
            );
            $expected += strlen($segment->raw());
        }
        $this->assertSame(strlen($input), $expected, 'reported bytes must sum to the input length');
    }

    public function testFirstSegmentAlwaysStartsAtZero(): void
    {
        foreach (['x', "\x1b[1m", "\x1b]0;t\x07"] as $input) {
            $this->assertSame(0, Inspector::parse($input)[0]->byteOffset());
        }
    }

    /**
     * The ST-normalised OSC consumes two terminator bytes but reports one;
     * the offset of what follows must jump over BOTH (true input positions,
     * not a raw()-length running total).
     */
    public function testNormalisedTerminatorKeepsTheFollowingSegmentsAtTheirTruePositions(): void
    {
        $segments = Inspector::parse("\x1b]0;t\x1b\\after");
        $this->assertSame(0, $segments[0]->byteOffset());          // OSC consumes ESC ] 0 ; t ESC (one backslash lands in the phantom-swallow)
        $this->assertInstanceOf(SequenceSegment::class, $segments[0]);
        $this->assertSame("\x1b]0;t\x07", $segments[0]->raw());     // the disclosed BEL normalisation
        $this->assertSame(7, $segments[1]->byteOffset());           // 'after' really starts at byte 7
        $this->assertSame("after", (string) $segments[1]->raw());
    }

    /**
     * CAN/SUB flush the abandoned prelude without a callback (README
     * deviation 7): those bytes appear in NO segment, so the segment after
     * the cancel byte must SKIP them — skipping unreported bytes is exactly
     * how the offsets stay honest about the report's own content.
     */
    public function testAbandonedPreludeBytesAreSkippedNotInvented(): void
    {
        $segments = Inspector::parse("a\x1b[3\x18m b");
        // 'a'@0  CAN@4  'm b'@5 — bytes 1..3 (the cancelled `ESC [ 3`) are
        // never dispatched and therefore carry no segment.
        $this->assertSame(0, $segments[0]->byteOffset());
        $this->assertSame(4, $segments[1]->byteOffset());
        $this->assertSame('CAN ', substr($segments[1]->describe(), 0, 4));
        $this->assertSame(5, $segments[2]->byteOffset());
        $this->assertSame('m b', $segments[2]->raw());
    }

    /**
     * A C0 that keeps the parser in its control-sequence state (finding 009)
     * leaves the executed byte physically INSIDE the eventual sequence's
     * span. The offsets report that truth: the CSI starts before the LF, and
     * the LF's own position sits within it — no fake renumbering to make the
     * list look monotone.
     */
    public function testMidSequenceExecuteKeepsOverlappingTruePositions(): void
    {
        $segments = Inspector::parse("\x1b[3\nm x");
        $byRaw = [];
        foreach ($segments as $segment) {
            $byRaw[$segment->raw()] = $segment->byteOffset();
        }
        $this->assertSame(0, $byRaw["\x1b[3m"], 'the sequence starts at its introducer, byte 0');
        $this->assertSame(3, $byRaw["\n"], 'the echoed LF keeps its own physical position');
        $this->assertSame(5, $byRaw[' x'], 'and everything after the sequence resumes truthfully');
    }

    /**
     * Armed sequences (SS3 / X10) complete in a LATER feed byte than their
     * dispatch arm, so their offsets must be captured at the introducer, not
     * at completion.
     */
    public function testArmedSequencesArePositionedAtTheirIntroducers(): void
    {
        $ss3 = Inspector::parse("pre\x1bOPpost");
        foreach ($ss3 as $segment) {
            if ($segment->raw() === "\x1bOP") {
                $this->assertSame(3, $segment->byteOffset());
            }
        }
        $this->assertContains("\x1bOP", array_map(static fn($s): string => $s->raw(), $ss3));

        $x10 = Inspector::parse("x\x1b[M !!y");
        $this->assertSame(0, $x10[0]->byteOffset());               // 'x'
        $this->assertSame(1, $x10[1]->byteOffset());               // mouse report at its ESC
        $this->assertStringContainsString('mouse X10', $x10[1]->describe());
        $this->assertSame(7, $x10[2]->byteOffset());               // 'y' after the third payload byte
    }

    /**
     * The dangling-tail anatomy (findings 009/010/011) reports cancelled
     * prelude, bare ESC and swallowed bytes as a stream-ordered trio: every
     * piece keeps its physical position.
     */
    public function testEndOfStreamTailPiecesKeepTheirPhysicalPositions(): void
    {
        $segments = Inspector::parse("ab\x1b[3\x1b");
        $this->assertSame(0, $segments[0]->byteOffset());          // 'ab'
        $this->assertSame(2, $segments[1]->byteOffset());          // cancelled 'ESC [ 3'
        $this->assertSame(5, $segments[2]->byteOffset());          // the bare dangling ESC
    }

    /**
     * One-shot and byte-by-byte streaming share the AnsiHandler feed clock,
     * so the offsets must be identical — the streaming==one-shot truth
     * (AIP-17) extended to the position column.
     */
    public function testStreamingReportsTheSameOffsetsAsStreamingAsOneShot(): void
    {
        $inputs = [
            "a\x1b[3\nm x",
            "\x1bOPtail",
            "\x1b[M !!ok",
            "\x1b]0;t\x1b\\x",
            "\x1b[3\x1bm",
            "a\x1b[3\x18m b",
            "\x1bX\x1b\\z",
            "\x1bP1q b\x1b\\t",
            "pre\x1b[3\x1b[1mpost",
        ];

        foreach ($inputs as $input) {
            $oneShot = self::identity(Inspector::parse($input));

            $streaming = new StreamingInspector();
            $collected = [];
            foreach (str_split($input) as $byte) {
                $collected = array_merge($collected, $streaming->feed($byte));
            }
            $collected = array_merge($collected, $streaming->finish());

            $this->assertSame($oneShot, self::identity($collected), 'stream offsets diverged for ' . json_encode($input));
        }
    }

    /**
     * Library API (acceptance): the field is visible on parsed segments, and
     * hand-constructed segments stay untracked — `raw()` equality and the
     * existing two-argument construction are untouched.
     */
    public function testHandConstructedSegmentsAreUntrackedAndRawEqualityStands(): void
    {
        $this->assertNull((new SequenceSegment("\x1b[1m", 'SGR bold'))->byteOffset());
        $this->assertNull((new TextSegment('hi'))->byteOffset());

        $text = new TextSegment('hi', 42);
        $this->assertSame(42, $text->byteOffset());
        $this->assertSame('hi', $text->raw(), 'raw() equality is untouched by the offset field');

        $seq = new SequenceSegment("\x1b[1m", 'SGR bold', 7);
        $this->assertSame(7, $seq->byteOffset());
        $this->assertSame("\x1b[1m", $seq->raw());
        $this->assertSame('ESC[1m  SGR bold', $seq->describe(), 'describe() renders without a prefix');
    }

    /**
     * Reset between parses restarts the feed clock — a second parse of the
     * same handler shape must not inherit the first stream's positions.
     */
    public function testResetRestartsTheFeedClock(): void
    {
        $handler = new \SugarCraft\AnsiInspect\AnsiHandler();
        $first = $handler->parse("ab\x1b[1m");
        $second = $handler->parse("ab\x1b[1m");
        $this->assertSame(
            array_map(static fn($s): ?int => $s->byteOffset(), $first),
            array_map(static fn($s): ?int => $s->byteOffset(), $second),
        );
    }

    public function testFormatSegmentRendersTheHexOffsetColumnUnderTheFlag(): void
    {
        $segments = Inspector::parse("hi\x1b[31mx");
        $this->assertSame('hi', Inspector::formatSegment($segments[0]), 'default: byte-identical to describe()');

        $this->assertSame('@0  hi', Inspector::formatSegment($segments[0], true));
        $this->assertSame('@2  ESC[31m  SGR foreground red', Inspector::formatSegment($segments[1], true));
        $this->assertSame('@7  x', Inspector::formatSegment($segments[2], true));

        // 26 renders as hex, lowercase, unpadded: the position column a
        // `grep -ob` consumer can compare against byte counts.
        $this->assertSame('@1a  tail', Inspector::formatSegment(new TextSegment('tail', 26), true));

        // Untracked segments render without a prefix even under the flag —
        // no fabricated position.
        $this->assertSame('ESC[1m  SGR bold', Inspector::formatSegment(new SequenceSegment("\x1b[1m", 'SGR bold'), true));
    }

    public function testReportShowOffsetsPrefixesEveryLineOfTheTranscript(): void
    {
        $transcript = Inspector::report("hi\x1b[31mred", true);
        $this->assertSame(
            "@0  hi\n@2  ESC[31m  SGR foreground red\n@7  red",
            $transcript,
        );
        // The flag is opt-in: the default report is untouched (the pinned
        // README transcripts and every existing golden stay byte-identical).
        $this->assertSame("hi\nESC[31m  SGR foreground red\nred", Inspector::report("hi\x1b[31mred"));
    }

    public function testJsonEntriesCarryTheByteOffsetField(): void
    {
        $decoded = json_decode(Inspector::reportAsJson("hi\x1b[31m"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([0, 2], array_column($decoded, 'byteOffset'));

        $handBuilt = Inspector::encodeSegmentsJson([new SequenceSegment("\x1b[1m", 'SGR bold')]);
        $this->assertNull(json_decode($handBuilt, true, 512, JSON_THROW_ON_ERROR)[0]['byteOffset']);
    }

    /**
     * @return list<string>
     */
    private static function identity(array $segments): array
    {
        return array_map(
            static fn($segment): string => get_class($segment) . '|' . json_encode($segment->raw()) . '|' . ($segment->byteOffset() ?? 'null'),
            $segments,
        );
    }
}
