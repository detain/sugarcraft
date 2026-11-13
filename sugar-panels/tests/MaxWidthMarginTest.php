<?php

declare(strict_types=1);

namespace SugarCraft\Panels\Tests;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Panels\{Node, SugarPanels};
use PHPUnit\Framework\TestCase;
use SugarCraft\Panels\Tests\Support\ReadsFrames;

/**
 * PB-PANELS-34: a max* cap covers the painted box, margin rides outside it.
 *
 * Node::measureWidth() returns capped(inner, maxWidth) + marginWidth(), so a
 * margined capped leaf claims maxWidth + margin through totalWidth(). The
 * parents used to clamp each child's region to the BARE maxWidth before the
 * child's own renderNode() inset its margin from that region — the margin was
 * charged twice and the content came out narrower (or, for a capped height
 * with a top margin, entirely invisible) below what the node measured. The
 * pins below are the three double-charge sites: horizontal width, vertical
 * height, and the stacked-child width leg of renderVertical().
 */
final class MaxWidthMarginTest extends TestCase
{
    use ReadsFrames;

    /**
     * Renders into SGR-free rows, one string per viewport row.
     *
     * @return list<string>
     */
    private function rows(SugarPanels $panels, Node $layout, int $w, int $h): array
    {
        return self::rowsOf($panels->render($layout, $w, $h), strip: true);
    }

    private function bareLeaf(string $content): Node
    {
        return Node::leaf($content)->withBorder(false);
    }

    public function testAMarginedCappedLeafKeepsItsMeasuredWidthInARow(): void
    {
        $sidebar = $this->bareLeaf('hello')->withMaxWidth(5)->withMargin(0, 0, 0, 3);
        // Measure first: 5 columns of capped content plus 3 columns of margin.
        self::assertSame(8, $sidebar->totalWidth());

        $panels = new SugarPanels();
        $layout = $panels->horizontal(
            $sidebar,
            $this->bareLeaf('fill')->withGrow(),
        )->withBorder(false);

        $rows = $this->rows($panels, $layout, 20, 1);
        // Pre-fix the leaf region was clamped to 5 BEFORE the 3-column margin
        // inset, so only "he" survived: '   he  ' with the rest gone.
        self::assertSame('   hellofill', \rtrim($rows[0]));
    }

    public function testAMarginedCappedLeafKeepsItsContentInAStackedHeightLeg(): void
    {
        $box = $this->bareLeaf("r1\nr2\nr3")->withMaxHeight(1)->withMargin(2, 0, 0, 0);
        self::assertSame(3, $box->totalHeight()); // capped 1 row + 2 top margin

        $panels = new SugarPanels();
        $layout = $panels->vertical(
            $box,
            $this->bareLeaf('tail')->withGrow(),
        )->withBorder(false);

        $rows = $this->rows($panels, $layout, 10, 6);
        // Pre-fix the height cap was applied before the top-margin inset,
        // leaving 1 - 2 <= 0 content rows: "r1" was never painted at all.
        self::assertContains('r1', \array_map(\rtrim(...), $rows));
    }

    public function testAStackedMarginedCappedLeafKeepsItsMeasuredWidth(): void
    {
        $box = $this->bareLeaf('hello')->withMaxWidth(5)->withMargin(0, 0, 0, 3);
        self::assertSame(8, $box->totalWidth());

        $panels = new SugarPanels();
        $layout = $panels->vertical(
            $box,
            $this->bareLeaf('tail'),
        )->withBorder(false);

        $rows = $this->rows($panels, $layout, 20, 2);
        // The stacked-child width cap took the same bare-maxWidth clamp;
        // pre-fix this row read '   he' for the same reason as the H leg.
        self::assertSame('   hello', \rtrim($rows[0]));
    }
}
