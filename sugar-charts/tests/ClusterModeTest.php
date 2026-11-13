<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\Charts\Buffer\BufferHelper;
use SugarCraft\Charts\Canvas\Canvas;

/**
 * Platform honesty for cluster splitting (sugar-charts-044/-118).
 *
 * The library used to key everything off `grapheme_str_split()` — a
 * symbol core PHP has never shipped in ext-intl; it only exists via
 * symfony/polyfill-intl-grapheme. Compliant installs therefore split
 * by CODEPOINT in silence (ZWJ families and combining marks became
 * stray cells) while the docblock claimed mb/grapheme parity. The
 * cure: ext-intl is now a hard requirement and BufferHelper::clusters
 * runs the fleet-proven IntlBreakIterator ladder, with the
 * clusterMode() seam pinning which canon is in force.
 */
final class ClusterModeTest extends TestCase
{
    public function testComposerPinsExtIntlAsRequireNotSuggest(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        self::assertIsArray($manifest);
        self::assertArrayHasKey('ext-intl', $manifest['require'], 'grapheme splitting is a contract, not a suggestion');
        self::assertArrayNotHasKey('ext-intl', $manifest['suggest'] ?? []);
    }

    public function testClusterModeAgreesWithTheLadderArms(): void
    {
        $expected = function_exists('grapheme_str_split') || class_exists(\IntlBreakIterator::class)
            ? 'grapheme'
            : 'codepoint';
        self::assertSame($expected, BufferHelper::clusterMode());
    }

    public function testGraphemeCanonIsLiveOnThisHost(): void
    {
        if (BufferHelper::clusterMode() !== 'grapheme') {
            self::markTestSkipped('codepoint-only host (bypassed composer install); grapheme arms unreachable here');
        }
        // The regression this row cures: 'e' + U+0301 must stay ONE
        // cluster. On a codepoint split it would be two cells.
        $clusters = BufferHelper::clusters("e\u{0301}x");
        self::assertCount(2, $clusters);
        self::assertSame("e\u{0301}", $clusters[0]);
        self::assertSame('x', $clusters[1]);
    }

    public function testZwjFamilyStaysSingleCluster(): void
    {
        if (BufferHelper::clusterMode() !== 'grapheme') {
            self::markTestSkipped('codepoint-only host; ZWJ segmentation needs ICU');
        }
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";
        self::assertCount(1, BufferHelper::clusters($family));
    }

    public function testEmptyStringIsZeroClusters(): void
    {
        self::assertSame([], BufferHelper::clusters(''));
    }

    public function testPlaceStringWritesClustersNotCodepoints(): void
    {
        if (BufferHelper::clusterMode() !== 'grapheme') {
            self::markTestSkipped('codepoint-only host; cell count differs by design there');
        }
        $grid = array_fill(0, 4, new Cell('', null, null, 1));
        $grid = BufferHelper::placeString($grid, 4, 1, 0, 0, "e\u{0301}x");
        self::assertSame("e\u{0301}", $grid[0]->rune, 'the combining sequence must occupy exactly one cell');
        self::assertSame('x', $grid[1]->rune);
        self::assertSame('', $grid[2]->rune, 'a codepoint split would have pushed text to this cell');
    }

    public function testCanvasSetStringSharesTheCentralSplitter(): void
    {
        if (BufferHelper::clusterMode() !== 'grapheme') {
            self::markTestSkipped('codepoint-only host; cell count differs by design there');
        }
        $canvas = new Canvas(5, 1);
        $canvas->setString(0, 0, "e\u{0301}x");
        self::assertSame("e\u{0301}", $canvas->cell(0, 0)->rune);
        self::assertSame('x', $canvas->cell(1, 0)->rune);
        self::assertSame(' ', $canvas->cell(2, 0)->rune, 'Canvas must no longer split differently from BufferHelper (untouched cells read as the blank space cell)');
    }
}
