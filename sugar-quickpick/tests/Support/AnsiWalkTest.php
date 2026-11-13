<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\QuickPick\Support\AnsiWalk;

/**
 * Pins for the escape-aware walker that backs the highlighters.
 *
 * The defects these guard: (M1) rebuilding a row from escape-stripped runes
 * clobbered consumer SGR; (C1-adjacent) a truncated string sequence must
 * swallow its payload, not leak it as display text; stray bytes of invalid
 * UTF-8 must be one-piece units so a match index can never address half a
 * codepoint.
 */
final class AnsiWalkTest extends TestCase
{
    public function testPiecesSeparatesEscapesFromCodepoints(): void
    {
        $pieces = AnsiWalk::pieces("\x1b[1mab\x1b[0m");

        self::assertSame([
            ['escape' => true, 'text' => "\x1b[1m"],
            ['escape' => false, 'text' => 'a'],
            ['escape' => false, 'text' => 'b'],
            ['escape' => true, 'text' => "\x1b[0m"],
        ], $pieces);
    }

    public function testPlainStripsSgrOscAndCursorSequences(): void
    {
        self::assertSame('hello world', AnsiWalk::plain('hello world'));
        self::assertSame('Warning', AnsiWalk::plain("\x1b[33mWarning\x1b[0m"));
        self::assertSame('Alert', AnsiWalk::plain("\x1b[1;3;31;4mAlert\x1b[0m"));
        self::assertSame('text', AnsiWalk::plain("\x1b[10C\x1b[5Dtext\x1b[0m"));
        self::assertSame(
            'label',
            AnsiWalk::plain("\x1b]8;;https://example.invalid\x07label\x1b]8;;\x07"),
            'OSC-8 hyperlink URI must not become display text',
        );
    }

    public function testWideAndMultibyteCodepointsAreSinglePieces(): void
    {
        $chars = AnsiWalk::chars("aé日🎉b");
        self::assertSame(['a', 'é', '日', '🎉', 'b'], $chars);
    }

    public function testStrayContinuationBytesAreOneBytePieces(): void
    {
        // 0xA9 is a UTF-8 continuation byte with no lead — it can only ever be
        // a stray (render ingress repairs valid-UTF-8 damage beforehand).
        $chars = AnsiWalk::chars("a\xA9b");
        self::assertSame(['a', "\xA9", 'b'], $chars);
    }

    public function testInvalidContinuationDoesNotSwallowFollowingAscii(): void
    {
        // -042: the lead 0xC3 promises one continuation byte; when the next
        // byte is not 0x80..0xBF the lead stands alone and 'g' survives as
        // its own display piece (the old size-from-lead-only scan folded
        // "\xc3g" into one piece and silently ate the character).
        self::assertSame(['a', "\xC3", 'g'], AnsiWalk::chars("a\xC3g"));
        // With the bad lead folded into one piece, 'g' would not be its own
        // addressable cell — the display index of 'g' shifts by one and a
        // highlight at index 2 paints the wrong rune. Pin the addressing:
        $cells = AnsiWalk::chars("a\xC3<g>");
        self::assertSame(['a', "\xC3", '<', 'g', '>'], $cells);
        self::assertSame("\xC3", $cells[1]);
        self::assertSame('g', $cells[3]);
        // A 3-byte lead truncated after one valid continuation stops there —
        // the ASCII behind the bad byte is not collateral.
        self::assertSame(['x', "\xE4\xB8", 'y'], AnsiWalk::chars("x\xE4\xB8y"));
        // Truncation at end of input keeps the valid prefix as one piece.
        self::assertSame(['a', "\xE4\xB8"], AnsiWalk::chars("a\xE4\xB8"));
        // A genuinely valid 3-byte codepoint still walks as one piece.
        self::assertSame(['a', '中', 'b'], AnsiWalk::chars("a\xe4\xb8\xadb"));
    }

    public function testOscClosedByStringTerminatorMidString(): void
    {
        // -081: `sequenceEnd()` claims ESC-ST ("\x1b\\") closure but nothing
        // pinned it. An OSC/DCS/SOS/PM/APC sequence closed by ST mid-string
        // must consume the introducer, the payload, AND the terminator.
        self::assertSame('ab', AnsiWalk::plain("a\x1b]0;t\x1b\\b"));
        $pieces = AnsiWalk::pieces("\x1b_x\x1b\\y");
        self::assertSame(
            [
                ['escape' => true, 'text' => "\x1b_x\x1b\\"],
                ['escape' => false, 'text' => 'y'],
            ],
            $pieces,
        );
    }

    public function testApcAndPmIntroducersConsumePayload(): void
    {
        // -081 dataset rows: APC (0x5F) and PM (0x5E) — each closed by BEL
        // and by ST — plus DCS/SOS for the same claim in the docblock.
        self::assertSame('ok', AnsiWalk::plain("\x1b_gencat\x07ok"));
        self::assertSame('ok', AnsiWalk::plain("\x1b_gencat\x1b\\ok"));
        self::assertSame('ok', AnsiWalk::plain("\x1b^priv\x07ok"));
        self::assertSame('ok', AnsiWalk::plain("\x1b^priv\x1b\\ok"));
        self::assertSame('ok', AnsiWalk::plain("\x1bPq#0payload\x07ok"));
        self::assertSame('ok', AnsiWalk::plain("\x1bXsos-payload\x07ok"));
    }

    public function testPiecesMemoizationAnswersByteIdentically(): void
    {
        // -070: the split is cached per string; a cache hit must answer with
        // exactly the same bytes (arrays are value types, so callers cannot
        // alias or corrupt the stored list).
        $text = "\x1b[1mbanana\x1b[0m";
        self::assertSame(AnsiWalk::pieces($text), AnsiWalk::pieces($text));
        // Different strings never share a slot.
        self::assertNotSame(
            \count(AnsiWalk::pieces($text)),
            \count(AnsiWalk::pieces($text . 'x')),
        );
    }

    public function testTruncatedStringSequenceSwallowsPayload(): void
    {
        // Unterminated OSC at end of input: everything after the introducer is
        // still sequence bytes — leaking it would print the URI as text.
        self::assertSame('x', AnsiWalk::plain("x\x1b]8;;https://leak.example.invalid"));
        // Same for DCS.
        self::assertSame('y', AnsiWalk::plain("y\x1bPq#0leak"));
    }

    public function testLoneEscapeAtEndIsASequencePiece(): void
    {
        self::assertSame('ab', AnsiWalk::plain("ab\x1b"));
        self::assertSame("\x1b", AnsiWalk::pieces("ab\x1b")[2]['text']);
    }

    public function testPaintWrapsMatchedRunsAndKeepsHostSgr(): void
    {
        // Match 'an' at codepoints 1-2 of 'banana' inside a bold row.
        $painted = AnsiWalk::paint("\x1b[1mbanana\x1b[0m", [1 => true, 2 => true], "\x1b[33m");

        self::assertSame("\x1b[1mb\x1b[33man\x1b[0mana\x1b[0m", $painted);
    }

    public function testPaintClosesStyleAcrossEmbeddedEscape(): void
    {
        // A run broken by a mid-word escape must close before and re-open
        // after it — otherwise the highlight's reset would cancel the
        // consumer sequence that follows.
        $painted = AnsiWalk::paint("ab\x1b[7mcd", [1 => true, 2 => true], "\x1b[33m");

        self::assertSame("a\x1b[33mb\x1b[0m\x1b[7m\x1b[33mc\x1b[0md", $painted);
    }

    public function testPaintMergesAdjacentMatchesIntoOneRun(): void
    {
        $painted = AnsiWalk::paint('banana', [1 => true, 2 => true, 3 => true, 4 => true], "\x1b[33m");
        self::assertSame("b\x1b[33manan\x1b[0ma", $painted);
    }

    public function testPaintWithEmptySetIsIdentity(): void
    {
        $text = "\x1b[1mbold\x1b[0m plain";
        self::assertSame($text, AnsiWalk::paint($text, [], "\x1b[33m"));
    }

    public function testPaintNeverLeavesStyleOpen(): void
    {
        $painted = AnsiWalk::paint('tail', [2 => true, 3 => true], "\x1b[33m");
        self::assertSame("ta\x1b[33mil\x1b[0m", $painted);
        self::assertStringEndsWith("\x1b[0m", $painted);
        self::assertSame('tail', AnsiWalk::plain($painted));
    }
}
