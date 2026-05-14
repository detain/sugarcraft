<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use SugarCraft\Dash\Plot\Chart\Area;
use SugarCraft\Dash\Plot\Chart\AreaPoint;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class AreaTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testAreaImplementsSizer(): void
    {
        $area = Area::new();
        $this->assertInstanceOf(Sizer::class, $area);
    }

    public function testAreaImplementsItem(): void
    {
        $area = Area::new();
        $this->assertInstanceOf(Item::class, $area);
    }

    // ═══════════════════════════════════════════════════════════════
    // AreaPoint
    // ═══════════════════════════════════════════════════════════════

    public function testAreaPointCreation(): void
    {
        $point = new AreaPoint('Jan', 50);

        $this->assertSame('Jan', $point->label);
        $this->assertSame(50.0, $point->value);
        $this->assertNull($point->y0);
    }

    public function testAreaPointWithBaseline(): void
    {
        $point = new AreaPoint('Jan', 50, 10.0);

        $this->assertSame(10.0, $point->y0);
    }

    public function testAreaPointNegativeValue(): void
    {
        $point = new AreaPoint('Neg', -25.5);

        $this->assertSame(-25.5, $point->value);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsEmptyWithNoData(): void
    {
        $area = Area::new();
        $this->assertSame('', $area->render());
    }

    public function testRenderReturnsNonEmptyWithData(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
            new AreaPoint('Feb', 75),
        ]);
        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsFillChars(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ]);
        $rendered = $area->render();

        // Should contain fill block characters
        $this->assertMatchesRegularExpression('/[░▒▓█]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Sample creation
    // ═══════════════════════════════════════════════════════════════

    public function testSampleCreatesDataPoints(): void
    {
        $area = Area::sample(8);

        $this->assertNotSame('', $area->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Data point operations
    // ═══════════════════════════════════════════════════════════════

    public function testWithDataPointsReplacesData(): void
    {
        $points = [
            new AreaPoint('Jan', 50),
            new AreaPoint('Feb', 75),
        ];
        $area = Area::new($points);

        $rendered = $area->render();

        $this->assertStringContainsString('Jan', $rendered);
        $this->assertStringContainsString('Feb', $rendered);
    }

    public function testWithPointAddsPoint(): void
    {
        $area = Area::new()
            ->withPoint(new AreaPoint('Jan', 50))
            ->withPoint(new AreaPoint('Feb', 75));

        $rendered = $area->render();

        $this->assertStringContainsString('Jan', $rendered);
        $this->assertStringContainsString('Feb', $rendered);
    }

    public function testAddPointByParams(): void
    {
        $area = Area::new()
            ->addPoint('Jan', 50)
            ->addPoint('Feb', 75);

        $rendered = $area->render();

        $this->assertStringContainsString('Jan', $rendered);
        $this->assertStringContainsString('Feb', $rendered);
    }

    public function testAddPointWithBaseline(): void
    {
        $area = Area::new()
            ->addPoint('Jan', 50, 10.0);

        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Display options
    // ═══════════════════════════════════════════════════════════════

    public function testShowGridDefaultTrue(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ]);

        $rendered = $area->render();

        $this->assertStringContainsString('│', $rendered);
    }

    public function testHideGrid(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ])->withShowGrid(false);

        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }

    public function testShowLabelsDefaultTrue(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ]);

        $rendered = $area->render();

        $this->assertStringContainsString('Jan', $rendered);
    }

    public function testHideLabels(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ])->withShowLabels(false);

        $rendered = $area->render();

        $this->assertStringNotContainsString('Jan', $rendered);
    }

    public function testShowValues(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ])->withShowValues(true);

        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }

    public function testStackedMode(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
            new AreaPoint('Feb', 75),
        ])->withStacked(true);

        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }

    public function testShowLegend(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ])->withShowLegend(true);

        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Layer colors
    // ═══════════════════════════════════════════════════════════════

    public function testWithLayerColors(): void
    {
        $colors = [
            Color::ansi(9),
            Color::ansi(10),
            Color::ansi(11),
        ];
        $area = Area::new([
            new AreaPoint('Jan', 50),
            new AreaPoint('Feb', 75),
        ])->withLayerColors($colors);

        $rendered = $area->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ])->withColor(Color::ansi(9));

        $rendered = $area->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testFillColorAddsAnsiCodes(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ])->withFillColor(Color::ansi(8));

        $rendered = $area->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testGridColorAddsAnsiCodes(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ])->withGridColor(Color::ansi(8));

        $rendered = $area->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testLabelColorAddsAnsiCodes(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ])->withLabelColor(Color::ansi(7));

        $rendered = $area->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Sizer interface
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Area::new()->withPoint(new AreaPoint('Jan', 50));
        $resized = $original->setSize(50, 12);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsRendered(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ])->setSize(50, 12);

        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Area::new()->withPoint(new AreaPoint('Jan', 50));
        $updated = $original->withColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithFillColorReturnsNewInstance(): void
    {
        $original = Area::new()->withPoint(new AreaPoint('Jan', 50));
        $updated = $original->withFillColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithGridColorReturnsNewInstance(): void
    {
        $original = Area::new()->withPoint(new AreaPoint('Jan', 50));
        $updated = $original->withGridColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithLabelColorReturnsNewInstance(): void
    {
        $original = Area::new()->withPoint(new AreaPoint('Jan', 50));
        $updated = $original->withLabelColor(Color::ansi(7));

        $this->assertNotSame($original, $updated);
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $original = Area::new()->withPoint(new AreaPoint('Jan', 50));
        $updated = $original->withHeight(15);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ])->setSize(50, 12);
        [$w, $h] = $area->getInnerSize();

        $this->assertSame(50, $w);
        $this->assertSame(12, $h);
    }

    public function testGetInnerSizeWithDefaultValues(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ]);
        [$w, $h] = $area->getInnerSize();

        $this->assertGreaterThanOrEqual(10, $w);
        $this->assertGreaterThanOrEqual(4, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSingleDataPoint(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ]);

        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }

    public function testZeroValue(): void
    {
        $area = Area::new([
            new AreaPoint('Zero', 0),
        ]);

        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }

    public function testNegativeValue(): void
    {
        $area = Area::new([
            new AreaPoint('Neg', -50),
        ]);

        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLargeValues(): void
    {
        $area = Area::new([
            new AreaPoint('Big', 1000000),
        ]);

        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }

    public function testAllSameValues(): void
    {
        $area = Area::new([
            new AreaPoint('A', 50),
            new AreaPoint('B', 50),
            new AreaPoint('C', 50),
        ]);

        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }

    public function testMinimumHeightConstraint(): void
    {
        $area = Area::new([
            new AreaPoint('Jan', 50),
        ])->withHeight(4);

        $rendered = $area->render();

        $this->assertNotSame('', $rendered);
    }
}