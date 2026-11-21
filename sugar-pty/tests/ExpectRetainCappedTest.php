<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Pty\Expect;

/**
 * tui-F-5: the session output cap is a BYTE budget, so the last chunk that
 * crosses the edge used to be torn with a raw substr($chunk, 0, $room) —
 * landing mid-codepoint and putting a lone continuation byte on the wire.
 * The cut now backs off to the start of the torn sequence; the bytes it gives
 * up are counted as dropped, never smuggled through half-cut, and from then on
 * every later byte is refused, so the transcript stays a byte prefix.
 *
 * Moved from candy-code's PtyDriverRetainTest when PtyDriver was deleted and
 * PtyRun began reading through {@see Expect::collectWindow()}.
 *
 * @see Expect::retainCapped()
 * @see Expect::utf8SafeByteCut()
 */
final class ExpectRetainCappedTest extends TestCase
{
    /**
     * @return array{0: string, 1: int, 2: int} buffer, retained, dropped
     */
    private static function retain(string $buffer, string $chunk, int $cap, int $retained = 0, int $dropped = 0): array
    {
        $method = new ReflectionMethod(Expect::class, 'retainCapped');

        /** @var array{0: string, 1: int, 2: int} $out */
        $out = $method->invoke(null, $buffer, $chunk, $cap, $retained, $dropped);

        return $out;
    }

    private static function cut(string $chunk, int $room): int
    {
        $method = new ReflectionMethod(Expect::class, 'utf8SafeByteCut');

        /** @var int $result */
        $result = $method->invoke(null, $chunk, $room);

        return $result;
    }

    // ---------------------------------------------------------------- utf8SafeByteCut

    public function testCutNeverMovesAnAsciiBoundary(): void
    {
        self::assertSame(5, self::cut(str_repeat('x', 10), 5));
    }

    public function testCutKeepsAWholeTwoByteSequenceOnTheEdge(): void
    {
        // "a" + é (U+00E9 = \xC3\xA9) + "b": a cut of 3 is complete (a, é).
        $chunk = "a\u{00E9}b";
        self::assertSame(3, self::cut($chunk, 3));
        self::assertTrue(mb_check_encoding(substr($chunk, 0, self::cut($chunk, 3)), 'UTF-8'));
    }

    public function testCutBacksOffASeveredTwoByteSequence(): void
    {
        // room = 2 lands between the two bytes of é → back off before the lead.
        $chunk = "a\u{00E9}";
        $cut = self::cut($chunk, 2);
        self::assertSame(1, $cut);
        self::assertTrue(mb_check_encoding(substr($chunk, 0, $cut), 'UTF-8'));
    }

    public function testCutBacksOffASeveredThreeByteSequence(): void
    {
        // "ab" + 你 (U+4F60 = \xE4\xBD\xA0): room 4 keeps "ab" then half of 你.
        $chunk = "ab\u{4F60}";
        $cut = self::cut($chunk, 4);
        self::assertSame(2, $cut, 'a three-byte glyph must not be entered with a partial');
        self::assertTrue(mb_check_encoding(substr($chunk, 0, $cut), 'UTF-8'));
    }

    public function testCutBacksOffASeveredFourByteSequence(): void
    {
        // U+1F600 is four bytes (\xF0\x9F\x98\x80); room 2 is inside it.
        $chunk = "\u{1F600}";
        $cut = self::cut($chunk, 2);
        self::assertSame(0, $cut, 'an over-tight budget into a four-byte glyph keeps nothing');
    }

    public function testCutKeepsAWholeFourByteSequence(): void
    {
        $chunk = "\u{1F600}x";
        self::assertSame(4, self::cut($chunk, 4));
    }

    public function testCutClampsToChunkLength(): void
    {
        $chunk = "abc";
        self::assertSame(3, self::cut($chunk, 99));
    }

    // ---------------------------------------------------------------- retainCapped()

    public function testRetainKeepsTheWholeChunkWhenItFits(): void
    {
        [$out, $retained, $dropped] = self::retain('', "\u{4F60}\u{4F60}", 10);
        self::assertSame("\u{4F60}\u{4F60}", $out);
        self::assertSame(6, $retained);
        self::assertSame(0, $dropped);
    }

    public function testRetainDropsEverythingWhenNoRoomRemains(): void
    {
        // The running total has already reached the cap: room is zero, so the
        // whole chunk is counted dropped and the buffer is untouched.
        [$out, $retained, $dropped] = self::retain('x', 'abcdef', 1, 1);
        self::assertSame('x', $out);
        self::assertSame(1, $retained);
        self::assertSame(6, $dropped);
    }

    public function testRetainNeverSplitsTheFinalCodepointAtTheCapEdge(): void
    {
        // cap 4, chunk = 你你 (6 bytes). The old raw substr(0,4) emitted
        // "\xE4\xBD\xA0\xE4" — a dangling lead byte. Backing off keeps the one
        // complete glyph (3 bytes) and counts the other three as dropped.
        [$out, $retained, $dropped] = self::retain('', "\u{4F60}\u{4F60}", 4);

        self::assertTrue(
            mb_check_encoding($out, 'UTF-8'),
            'retain emitted invalid UTF-8 at the cap edge: ' . bin2hex($out),
        );
        self::assertSame("\u{4F60}", $out);
        self::assertSame(3, $retained, 'the retained total must stay within the byte cap');
        self::assertSame(3, $dropped, 'the bytes the boundary back-off gave up must be counted');
    }

    public function testRetainBacksOffTheEdgeAgainstARunningSessionTotal(): void
    {
        // cap 5 with four bytes already retained: one byte of room, not enough
        // to start a two-byte é. The cut backs to zero — the running total is
        // never exceeded and no partial lead is appended to the buffer.
        $buffer = "abcd";
        [$out, $retained, $dropped] = self::retain($buffer, "\u{00E9}", 5, 4);

        self::assertSame($buffer, $out, 'a partial lead byte must not be appended');
        self::assertSame(4, $retained, 'the session total must never exceed the byte cap');
        self::assertSame(2, $dropped, 'the refused codepoint is counted as dropped');
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    public function testRoomLeftByABackOffCutIsNeverSpentByALaterChunk(): void
    {
        // The true-prefix rule (owner ruling, 21 Nov): after the cut above,
        // cap 4 still shows one byte of room against retained 3, but the cap
        // has already refused bytes, so an ASCII chunk that would fit is
        // refused whole. Keeping it would splice 'z' in after the dropped
        // glyph, and the transcript would no longer be a prefix of the stream.
        [$out, $retained, $dropped] = self::retain('', "\u{4F60}\u{4F60}", 4);
        [$out, $retained, $dropped] = self::retain($out, 'z', 4, $retained, $dropped);

        self::assertSame("\u{4F60}", $out, 'nothing is kept after the gap the cut left');
        self::assertSame(3, $retained);
        self::assertSame(4, $dropped, 'the refused chunk is counted on top of the back-off bytes');
    }
}
