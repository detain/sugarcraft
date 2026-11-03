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
