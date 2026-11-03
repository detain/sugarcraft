<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\Clusters;

/**
 * Edge table for the shared grapheme-boundary walker.
 *
 * The three rules (at-or-before, strictly-before, after) disagree only on
 * exact-boundary offsets — which is where the old per-caller copies each
 * resolved differently — so the pins concentrate there, plus mid-cluster
 * snaps and the clamps past either end. A decomposed accented letter and a
 * ZWJ family emoji give walks that must NOT split inside a cluster.
 */
final class ClustersTest extends TestCase
{
    /** 'cafe' + U+0301: five codepoints, four clusters (c, a, f, e\u0301). */
    private const CAFE = "caf\u{65}\u{301}";

    /** Man + ZWJ + Woman + ZWJ + Girl: five codepoints, one cluster. */
    private const EMOJI_FAMILY = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

    public function testPlainAsciiEveryOffsetIsABoundary(): void
    {
        foreach ([0, 1, 2, 3] as $at) {
            self::assertSame($at, Clusters::boundaryAtOrBefore('abc', $at));
            self::assertSame(max(0, $at - 1), Clusters::boundaryBefore('abc', $at));
            self::assertSame(min(3, $at + 1), Clusters::boundaryAfter('abc', $at));
        }
    }

    public function testCombiningSequenceIsNeverSplit(): void
    {
        self::assertSame(5, \mb_strlen(self::CAFE, 'UTF-8'));
        self::assertSame(3, Clusters::boundaryAtOrBefore(self::CAFE, 4));
        self::assertSame(3, Clusters::boundaryAtOrBefore(self::CAFE, 3));
        self::assertSame(3, Clusters::boundaryBefore(self::CAFE, 4));
        self::assertSame(2, Clusters::boundaryBefore(self::CAFE, 3));
        self::assertSame(3, Clusters::boundaryAfter(self::CAFE, 2));
        self::assertSame(5, Clusters::boundaryAfter(self::CAFE, 3));
    }

    public function testZWJFamilyCountsAsOneCluster(): void
    {
        $total = \mb_strlen(self::EMOJI_FAMILY, 'UTF-8');
        self::assertGreaterThan(1, $total, 'the sequence is several codepoints deep');
        self::assertSame(0, Clusters::boundaryAtOrBefore(self::EMOJI_FAMILY, 1));
        self::assertSame(0, Clusters::boundaryBefore(self::EMOJI_FAMILY, $total));
        self::assertSame($total, Clusters::boundaryAfter(self::EMOJI_FAMILY, 1));
        self::assertSame($total, Clusters::boundaryAfter(self::EMOJI_FAMILY, $total));
    }

    public function testClampsAtBothEnds(): void
    {
        $text = 'a' . self::CAFE;
        $total = \mb_strlen($text, 'UTF-8');
        self::assertSame(0, Clusters::boundaryAtOrBefore($text, -5));
        self::assertSame(0, Clusters::boundaryBefore($text, -5));
        self::assertSame($total, Clusters::boundaryAtOrBefore($text, $total + 4));
        self::assertSame(4, Clusters::boundaryBefore($text, $total + 4));
        self::assertSame($total, Clusters::boundaryAfter($text, $total + 4));
        self::assertSame(0, Clusters::boundaryAtOrBefore('', 0));
        self::assertSame(0, Clusters::boundaryAfter('', 0));
    }
}
