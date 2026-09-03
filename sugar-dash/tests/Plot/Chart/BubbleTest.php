<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use SugarCraft\Dash\Plot\Chart\Bubble;
use SugarCraft\Dash\Plot\Chart\BubblePoint;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class BubbleTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testBubbleImplementsSizer(): void
    {
        $bubble = Bubble::new();
        $this->assertInstanceOf(Sizer::class, $bubble);
    }

    public function testBubbleImplementsItem(): void
    {
        $bubble = Bubble::new();
        $this->assertInstanceOf(Item::class, $bubble);
    }

    // ═══════════════════════════════════════════════════════════════
    // BubblePoint
    // ═══════════════════════════════════════════════════════════════

    public function testBubblePointCreation(): void
    {
        $point = new BubblePoint('Alpha', 25, 75, 5);

        $this->assertSame('Alpha', $point->label);
        $this->assertSame(25.0, $point->x);
        $this->assertSame(75.0, $point->y);
        $this->assertSame(5.0, $point->size);
        $this->assertNull($point->color);
        $this->assertNull($point->category);
    }

    public function testBubblePointWithColor(): void
    {
        $color = Color::hex('#89B4FA');
        $point = new BubblePoint('Alpha', 25, 75, 5, $color);

        $this->assertSame($color, $point->color);
    }

    public function testBubblePointWithCategory(): void
    {
        $point = new BubblePoint('Alpha', 25, 75, 5, null, 'groupA');

        $this->assertSame('groupA', $point->category);
    }

    public function testBubblePointWithColorReturnsNewInstance(): void
    {
        $point = new BubblePoint('Alpha', 25, 75, 5);
        $color = Color::hex('#89B4FA');
        $withColor = $point->withColor($color);

        $this->assertSame($color, $withColor->color);
        $this->assertNull($point->color);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsEmptyWithNoPoints(): void
    {
        $bubble = Bubble::new();
        $this->assertSame('', $bubble->render());
    }

    public function testRenderReturnsNonEmptyWithPoints(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ]);
        $rendered = $bubble->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsBubbleChars(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 50, 50, 5),
        ]);
        $rendered = $bubble->render();

        // Should contain circle characters
        $this->assertMatchesRegularExpression('/[●◜◝◟◞]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Sample creation
    // ═══════════════════════════════════════════════════════════════

    public function testSampleCreatesPoints(): void
    {
        $bubble = Bubble::sample(5);

        $this->assertNotSame('', $bubble->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Point operations
    // ═══════════════════════════════════════════════════════════════

    public function testWithPointsReplacesPoints(): void
    {
        $points = [
            new BubblePoint('Alpha', 25, 75, 5),
            new BubblePoint('Beta', 60, 30, 3),
        ];
        $bubble = Bubble::new($points);

        $rendered = $bubble->render();

        $this->assertStringContainsString('Alpha', $rendered);
        $this->assertStringContainsString('Beta', $rendered);
    }

    public function testWithPointAddsPoint(): void
    {
        $bubble = Bubble::new()
            ->withPoint(new BubblePoint('Alpha', 25, 75, 5))
            ->withPoint(new BubblePoint('Beta', 60, 30, 3));

        $rendered = $bubble->render();

        $this->assertStringContainsString('Alpha', $rendered);
        $this->assertStringContainsString('Beta', $rendered);
    }

    public function testAddPointByParams(): void
    {
        $bubble = Bubble::new()
            ->addPoint('Alpha', 25, 75, 5);

        $rendered = $bubble->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Display options
    // ═══════════════════════════════════════════════════════════════

    public function testShowGridDefaultTrue(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ]);

        $rendered = $bubble->render();

        // Should contain grid dots
        $this->assertStringContainsString('·', $rendered);
    }

    public function testHideGrid(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ])->withShowGrid(false);

        $rendered = $bubble->render();

        $this->assertNotSame('', $rendered);
    }

    public function testShowLabelsDefaultTrue(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ]);

        $rendered = $bubble->render();

        $this->assertNotSame('', $rendered);
    }

    public function testHideLabels(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ])->withShowLabels(false);

        $rendered = $bubble->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Range settings
    // ═══════════════════════════════════════════════════════════════

    public function testWithXRange(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ])->withXRange(0, 100);

        $rendered = $bubble->render();

        $this->assertNotSame('', $rendered);
    }

    public function testWithYRange(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ])->withYRange(0, 100);

        $rendered = $bubble->render();

        $this->assertNotSame('', $rendered);
    }

    public function testWithSizeRange(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ])->withSizeRange(1, 10);

        $rendered = $bubble->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ])->withColor(Color::ansi(9));

        $rendered = $bubble->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testGridColorAddsAnsiCodes(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ])->withGridColor(Color::ansi(8));

        $rendered = $bubble->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testLabelColorAddsAnsiCodes(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ])->withLabelColor(Color::ansi(7));

        $rendered = $bubble->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testPointWithColor(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5, Color::ansi(9)),
        ]);

        $rendered = $bubble->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Sizer interface
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Bubble::new()->withPoint(new BubblePoint('Alpha', 25, 75, 5));
        $resized = $original->setSize(50, 20);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsRendered(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ])->setSize(50, 20);

        $rendered = $bubble->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Bubble::new()->withPoint(new BubblePoint('Alpha', 25, 75, 5));
        $updated = $original->withColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithGridColorReturnsNewInstance(): void
    {
        $original = Bubble::new()->withPoint(new BubblePoint('Alpha', 25, 75, 5));
        $updated = $original->withGridColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithLabelColorReturnsNewInstance(): void
    {
        $original = Bubble::new()->withPoint(new BubblePoint('Alpha', 25, 75, 5));
        $updated = $original->withLabelColor(Color::ansi(7));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBgColorReturnsNewInstance(): void
    {
        $original = Bubble::new()->withPoint(new BubblePoint('Alpha', 25, 75, 5));
        $updated = $original->withBgColor(Color::ansi(0));

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ])->setSize(50, 20);
        [$w, $h] = $bubble->getInnerSize();

        $this->assertSame(50, $w);
        $this->assertSame(20, $h);
    }

    public function testGetInnerSizeWithDefaultValues(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ]);
        [$w, $h] = $bubble->getInnerSize();

        $this->assertSame(50, $w);
        $this->assertSame(20, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testMinimumWidthRendersEmpty(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ])->setSize(12, 20);

        $this->assertSame('', $bubble->render());
    }

    public function testMinimumHeightRendersEmpty(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Alpha', 25, 75, 5),
        ])->setSize(50, 3);

        $this->assertSame('', $bubble->render());
    }

    public function testMultiplePoints(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('A', 20, 80, 3),
            new BubblePoint('B', 40, 60, 5),
            new BubblePoint('C', 60, 40, 4),
            new BubblePoint('D', 80, 20, 6),
        ]);

        $rendered = $bubble->render();

        $this->assertNotSame('', $rendered);
    }

    public function testDifferentSizes(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Small', 25, 75, 1),
            new BubblePoint('Large', 75, 25, 10),
        ]);

        $rendered = $bubble->render();

        $this->assertNotSame('', $rendered);
    }

    public function testPointsAtBounds(): void
    {
        $bubble = Bubble::new([
            new BubblePoint('Min', 0, 0, 5),
            new BubblePoint('Max', 100, 100, 5),
        ])->withXRange(0, 100)->withYRange(0, 100);

        $rendered = $bubble->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ringed bubble geometry (S1 amendment: CIRCLE_CHARS drives render)
    //
    // render() binning via mapSize (default size range 1..10):
    //   raw size 1..3  -> single cell
    //   raw size 4..6  -> radius 1 (legacy plus, no diagonals exist)
    //   raw size 7..10 -> radius 2 (rounded box with quadrant arcs)
    // At the default 50x20 the single point (50,50) centers at grid cell
    // (row 8, col 20); each row is a 6-char label + space, so the visible
    // char for grid col x sits at mb offset 7 + x of the ANSI-stripped line.
    // ═══════════════════════════════════════════════════════════════

    /**
     * Read Bubble's private CIRCLE_CHARS table through reflection so the
     * assertions below track the source of truth: mutating a table entry
     * in src changes what these tests expect (proving the table drives
     * render), instead of restating the glyphs as test-local literals.
     *
     * @return array<string,string>
     */
    private function circleCharsTable(): array
    {
        foreach ((new ReflectionClass(Bubble::class))->getReflectionConstants() as $constant) {
            if ($constant->getName() === 'CIRCLE_CHARS') {
                /** @var array<string,string> $value */
                $value = $constant->getValue();

                return $value;
            }
        }

        $this->fail('Bubble::CIRCLE_CHARS no longer exists; the geometry contract has moved.');
    }

    /**
     * @return list<string> ANSI-stripped render lines (row y = index y).
     */
    private function strippedRenderLines(Bubble $bubble): array
    {
        return explode("\n", (string) preg_replace('/\x1b\[[0-9;]*m/', '', $bubble->render()));
    }

    private function cellAt(array $lines, int $row, int $col): string
    {
        return mb_substr($lines[$row], 7 + $col, 1);
    }

    public function testMediumBubbleEmitsQuadrantArcsFromTableAtDiagonals(): void
    {
        $table = $this->circleCharsTable();
        // size 10 -> radius 2; center (row 8, col 20); corners at (+-2, +-2).
        $lines = $this->strippedRenderLines(Bubble::new([
            new BubblePoint('Big', 50, 50, 10),
        ]));

        $this->assertSame($table['top-left'], $this->cellAt($lines, 6, 18));
        $this->assertSame($table['top-right'], $this->cellAt($lines, 6, 22));
        $this->assertSame($table['bottom-left'], $this->cellAt($lines, 10, 18));
        // Binding criterion (a): bottom-right corner comes from the table
        // lookup at its exact diagonal offset - never a hardcoded glyph.
        $this->assertSame($table['bottom-right'], $this->cellAt($lines, 10, 22));
        // The table entry itself must carry U+25DE LOWER RIGHT ARC.
        $this->assertSame("\u{25DE}", $table['bottom-right']);
    }

    public function testMediumBubbleRendersConnectedBoxWithFullDotFill(): void
    {
        $table = $this->circleCharsTable();
        $lines = $this->strippedRenderLines(Bubble::new([
            new BubblePoint('Big', 50, 50, 10),
        ]));

        $arcs = [$table['top-left'], $table['top-right'], $table['bottom-left'], $table['bottom-right']];
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $cell = $this->cellAt($lines, 8 + $dy, 20 + $dx);
                $isCorner = abs($dx) === 2 && abs($dy) === 2;

                // Connected: the 5x5 window holds no gaps (full box => 4-connected).
                $this->assertNotSame(' ', $cell, "gap at (dx=$dx, dy=$dy)");

                if ($isCorner) {
                    $this->assertContains($cell, $arcs);
                } else {
                    // Cardinal extremes and interior fill stay the full dot.
                    $this->assertSame($table['full'], $cell, "non-corner at (dx=$dx, dy=$dy)");
                }
            }
        }
    }

    public function testSmallBubbleKeepsPlusShapeWithoutCornerArcs(): void
    {
        $table = $this->circleCharsTable();
        // size 5 -> radius 1: legacy 5-cell plus; its diagonals are outside
        // the disk, and the arc clause of the contract is scoped to r >= 2.
        $bubble = Bubble::new([
            new BubblePoint('Small', 50, 50, 5),
        ]);
        $lines = $this->strippedRenderLines($bubble);

        $this->assertSame($table['full'], $this->cellAt($lines, 8, 20));
        $this->assertSame($table['full'], $this->cellAt($lines, 7, 20));
        $this->assertSame($table['full'], $this->cellAt($lines, 9, 20));
        $this->assertSame($table['full'], $this->cellAt($lines, 8, 19));
        $this->assertSame($table['full'], $this->cellAt($lines, 8, 21));
        foreach ([[-1, -1], [-1, 1], [1, -1], [1, 1]] as [$dy, $dx]) {
            $this->assertSame(' ', $this->cellAt($lines, 8 + $dy, 20 + $dx));
        }

        $arcs = [$table['top-left'], $table['top-right'], $table['bottom-left'], $table['bottom-right']];
        $this->assertSame([], array_values(array_intersect(mb_str_split(implode('', $lines)), $arcs)));
    }

    public function testDegenerateSizeRangePinsEveryPointToLargestBin(): void
    {
        // B9/Q4 contract: withSizeRange(x, x) collapses mapSize's span to zero.
        // Pre-guard that division threw DivisionByZeroError (PHP 8 float divide
        // by zero is fatal) and render() produced nothing; post-guard the ratio
        // is pinned to 1, so EVERY point takes the largest bin regardless of
        // its raw size — both the size-3 and the size-9 point below render the
        // r=2 solid 5x5 box. Grid math at default 50x20: mapX(25)=col 10,
        // mapY(75)=row 4; mapX(75)=col 30, mapY(25)=row 12.
        $table = $this->circleCharsTable();
        $lines = $this->strippedRenderLines(Bubble::new([
            new BubblePoint('Low', 25, 75, 3),
            new BubblePoint('High', 75, 25, 9),
        ])->withSizeRange(5, 5));

        $arcs = [$table['top-left'], $table['top-right'], $table['bottom-left'], $table['bottom-right']];
        foreach ([[4, 10], [12, 30]] as [$row, $col]) {
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $cell = $this->cellAt($lines, $row + $dy, $col + $dx);
                    $isCorner = abs($dx) === 2 && abs($dy) === 2;

                    $this->assertNotSame(' ', $cell, "gap at center ($row,$col) + (dx=$dx, dy=$dy)");
                    if ($isCorner) {
                        $this->assertContains($cell, $arcs);
                    } else {
                        $this->assertSame($table['full'], $cell, "non-corner at center ($row,$col) + (dx=$dx, dy=$dy)");
                    }
                }
            }
        }
    }

    public function testMapSizeLadderCapsTopBinSoSevenThroughTenShareTheBox(): void
    {
        // v4/Q1 collapse: plotBubble dispatches three shapes, and the former
        // bin 4 (raw 10 at the default 1..10 range) landed in the same r=2 arm
        // as bin 3 (raw 7..9) — byte-identical output. The ladder is capped at
        // 3 so the dead rung is gone; this pin fails if a fourth distinct bin
        // ever reappears without the dispatch gaining a matching arm.
        $mapSize = new ReflectionMethod(Bubble::class, 'mapSize');
        $bubble = Bubble::new([]);

        $bins = [];
        for ($raw = 1; $raw <= 10; $raw++) {
            $bins[$raw] = $mapSize->invoke($bubble, (float) $raw);
        }

        $this->assertSame([1, 1, 1, 2, 2, 2, 3, 3, 3, 3], array_values($bins));
        // Equivalence pin: the collapsed top band keeps old bin3 ≡ bin4 on one
        // shape — raw 7 and raw 10 must never disagree.
        $this->assertSame($bins[7], $bins[10]);
        // Out-of-range sizes saturate into an existing rung, never invent one.
        $this->assertSame(1, $mapSize->invoke($bubble, 0.0));
        $this->assertSame(3, $mapSize->invoke($bubble, 20.0));
    }
}