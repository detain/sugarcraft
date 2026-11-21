<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

/**
 * The printable-ASCII fast path in Width::compute() must not split an ASCII
 * base from a following Extend or SpacingMark codepoint. Each case below pins
 * string(), truncate() and truncateEllipsis() against the same cluster walk,
 * so an ASCII base plus a combining mark is one cell in every measure and a
 * budget is never overrun.
 */
final class WidthAsciiSeamTest extends TestCase
{
    /** Spacing mark (Mc) after an ASCII base: DEVANAGARI VOWEL SIGN VISARGA. */
    private const SPACING_MARK = "\u{0903}";

    /** Nonspacing mark (Mn) after an ASCII base: COMBINING ACUTE ACCENT. */
    private const NONSPACING_MARK = "\u{0301}";

    public function testSpacingMarkAfterAsciiBaseIsOneCellInString(): void
    {
        $this->assertSame(1, Width::string('e' . self::SPACING_MARK));
        $this->assertSame(2, Width::string('e' . self::SPACING_MARK . 'x'));
    }

    public function testNonspacingMarkAfterAsciiBaseIsOneCellInString(): void
    {
        $this->assertSame(1, Width::string('e' . self::NONSPACING_MARK));
        $this->assertSame(2, Width::string('e' . self::NONSPACING_MARK . 'x'));
    }

    public function testTruncateKeepsSpacingMarkWithItsAsciiBase(): void
    {
        $out = Width::truncate('e' . self::SPACING_MARK . 'x', 1);
        $this->assertSame('e' . self::SPACING_MARK, $out);
        $this->assertSame(1, Width::string($out));
    }

    public function testTruncateKeepsNonspacingMarkWithItsAsciiBase(): void
    {
        $out = Width::truncate('e' . self::NONSPACING_MARK . 'x', 1);
        $this->assertSame('e' . self::NONSPACING_MARK, $out);
        $this->assertSame(1, Width::string($out));
    }

    public function testTruncateEllipsisNeverOverrunsBudgetWithSpacingMark(): void
    {
        // Regression: the ASCII run used to swallow the 'e' alone, so the
        // spacing mark was scored as a separate cell and the result measured 5
        // against a budget of 4.
        $out = Width::truncateEllipsis('e' . self::SPACING_MARK . 'xyz', 4, "\u{2026}");
        $this->assertSame('e' . self::SPACING_MARK . 'xyz', $out);
        $this->assertLessThanOrEqual(4, Width::string($out));

        $clipped = Width::truncateEllipsis('e' . self::SPACING_MARK . 'xyzw', 4, "\u{2026}");
        $this->assertSame('e' . self::SPACING_MARK . 'xy' . "\u{2026}", $clipped);
        $this->assertLessThanOrEqual(4, Width::string($clipped));
    }

    public function testTruncateEllipsisNeverOverrunsBudgetWithNonspacingMark(): void
    {
        $clipped = Width::truncateEllipsis('e' . self::NONSPACING_MARK . 'xyzw', 4, "\u{2026}");
        $this->assertSame('e' . self::NONSPACING_MARK . 'xy' . "\u{2026}", $clipped);
        $this->assertLessThanOrEqual(4, Width::string($clipped));
    }

    public function testFastPathStillCountsAsciiRunsBeforeAndAfterAMark(): void
    {
        // The back-off must only give up the LAST ASCII byte of a run, so the
        // rest of the run is still charged one cell per byte.
        $s = 'abcd' . self::SPACING_MARK . 'efgh';
        $this->assertSame(8, Width::string($s));
        $this->assertSame($s, Width::truncate($s, 8));
        $this->assertSame(7, Width::string(Width::truncate($s, 7)));
    }

    /**
     * Parity sweep: for every ASCII base and both mark kinds, the three
     * measures agree and a budget is never exceeded.
     *
     * @return iterable<string, array{string}>
     */
    public static function markProvider(): iterable
    {
        foreach (['a', 'e', 'Z', '7', '~'] as $base) {
            yield 'spacing ' . $base => [$base . self::SPACING_MARK . 'qrs'];
            yield 'nonspacing ' . $base => [$base . self::NONSPACING_MARK . 'qrs'];
        }
    }

    /** @dataProvider markProvider */
    public function testEveryBudgetHoldsForAsciiPlusMark(string $s): void
    {
        $total = Width::string($s);
        for ($budget = 0; $budget <= $total + 1; $budget++) {
            $head = Width::truncate($s, $budget);
            $this->assertLessThanOrEqual($budget, Width::string($head), "truncate budget $budget");
            $ellipsized = Width::truncateEllipsis($s, $budget, "\u{2026}");
            $this->assertLessThanOrEqual($budget, Width::string($ellipsized), "truncateEllipsis budget $budget");
        }
        $this->assertSame($total, Width::string(Width::truncate($s, $total)));
    }
}
