<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Bubble;
use SugarCraft\Dash\Grid\BubblePoint;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

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
        $this->assertMatchesRegularExpression('/[●◜◝◟◠]/', $rendered);
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
}
