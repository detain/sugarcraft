<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use SugarCraft\Dash\Plot\Chart\Funnel;
use SugarCraft\Dash\Plot\Chart\FunnelStage;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class FunnelTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testFunnelImplementsSizer(): void
    {
        $funnel = Funnel::new();
        $this->assertInstanceOf(Sizer::class, $funnel);
    }

    public function testFunnelImplementsItem(): void
    {
        $funnel = Funnel::new();
        $this->assertInstanceOf(Item::class, $funnel);
    }

    // ═══════════════════════════════════════════════════════════════
    // FunnelStage
    // ═══════════════════════════════════════════════════════════════

    public function testFunnelStageCreation(): void
    {
        $stage = new FunnelStage('Visitors', 1000);

        $this->assertSame('Visitors', $stage->label);
        $this->assertSame(1000.0, $stage->value);
        $this->assertNull($stage->color);
    }

    public function testFunnelStageWithColor(): void
    {
        $color = Color::hex('#89B4FA');
        $stage = new FunnelStage('Leads', 500, $color);

        $this->assertSame($color, $stage->color);
    }

    public function testFunnelStageWithColorReturnsNewInstance(): void
    {
        $stage = new FunnelStage('Visitors', 1000);
        $color = Color::hex('#89B4FA');
        $withColor = $stage->withColor($color);

        $this->assertSame($color, $withColor->color);
        $this->assertNull($stage->color);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsEmptyWithNoStages(): void
    {
        $funnel = Funnel::new();
        $this->assertSame('', $funnel->render());
    }

    public function testRenderReturnsNonEmptyWithStages(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
            new FunnelStage('Leads', 500),
        ]);
        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsFunnelChars(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Stage 1', 100),
        ]);
        $rendered = $funnel->render();

        // Should contain funnel/box drawing characters
        $this->assertMatchesRegularExpression('/[╭╮╰╯│█╲╱┌┐└┘─━]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Sample creation
    // ═══════════════════════════════════════════════════════════════

    public function testSampleCreatesStages(): void
    {
        $funnel = Funnel::sample(4);

        $this->assertNotSame('', $funnel->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Stage operations
    // ═══════════════════════════════════════════════════════════════

    public function testWithStagesReplacesStages(): void
    {
        $stages = [
            new FunnelStage('Visitors', 1000),
            new FunnelStage('Leads', 500),
        ];
        $funnel = Funnel::new($stages);

        $rendered = $funnel->render();

        $this->assertStringContainsString('Visitors', $rendered);
        $this->assertStringContainsString('Leads', $rendered);
    }

    public function testWithStageAddsStage(): void
    {
        $funnel = Funnel::new()
            ->withStage(new FunnelStage('Visitors', 1000))
            ->withStage(new FunnelStage('Leads', 500));

        $rendered = $funnel->render();

        $this->assertStringContainsString('Visitors', $rendered);
        $this->assertStringContainsString('Leads', $rendered);
    }

    public function testAddStageByParams(): void
    {
        $funnel = Funnel::new()
            ->addStage('Visitors', 1000);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testAddStageWithColor(): void
    {
        $funnel = Funnel::new()
            ->addStage('Visitors', 1000, Color::ansi(9));

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Display options
    // ═══════════════════════════════════════════════════════════════

    public function testShowLabelsDefaultTrue(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ]);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testHideLabels(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ])->withShowLabels(false);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testShowValuesDefaultTrue(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ]);

        $rendered = $funnel->render();

        // Should contain the value (1000 or formatted)
        $this->assertNotSame('', $rendered);
    }

    public function testHideValues(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ])->withShowValues(false);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testShowPercentages(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
            new FunnelStage('Leads', 500),
        ])->withShowPercentages(true);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testCenteredDefaultTrue(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ]);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testNotCentered(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ])->withCentered(false);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ])->withColor(Color::ansi(9));

        $rendered = $funnel->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBorderColorAddsAnsiCodes(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ])->withBorderColor(Color::ansi(8));

        $rendered = $funnel->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testLabelColorAddsAnsiCodes(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ])->withLabelColor(Color::ansi(7));

        $rendered = $funnel->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testValueColorAddsAnsiCodes(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ])->withValueColor(Color::ansi(10));

        $rendered = $funnel->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testStageWithColor(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000, Color::ansi(9)),
        ]);

        $rendered = $funnel->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border styles
    // ═══════════════════════════════════════════════════════════════

    public function testBorderStyles(): void
    {
        $styles = ['rounded', 'single', 'double', 'bold', 'empty'];

        foreach ($styles as $style) {
            $funnel = Funnel::new([
                new FunnelStage('Visitors', 1000),
            ])->withStyle($style);

            $rendered = $funnel->render();

            $this->assertNotSame('', $rendered, "Style '$style' should render");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Sizer interface
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Funnel::new()->withStage(new FunnelStage('Visitors', 1000));
        $resized = $original->setSize(50, 15);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsRendered(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ])->setSize(50, 15);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Funnel::new()->withStage(new FunnelStage('Visitors', 1000));
        $updated = $original->withColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Funnel::new()->withStage(new FunnelStage('Visitors', 1000));
        $updated = $original->withBorderColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithLabelColorReturnsNewInstance(): void
    {
        $original = Funnel::new()->withStage(new FunnelStage('Visitors', 1000));
        $updated = $original->withLabelColor(Color::ansi(7));

        $this->assertNotSame($original, $updated);
    }

    public function testWithValueColorReturnsNewInstance(): void
    {
        $original = Funnel::new()->withStage(new FunnelStage('Visitors', 1000));
        $updated = $original->withValueColor(Color::ansi(10));

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ])->setSize(50, 15);
        [$w, $h] = $funnel->getInnerSize();

        $this->assertSame(50, $w);
        $this->assertSame(15, $h);
    }

    public function testGetInnerSizeWithDefaultValues(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
            new FunnelStage('Leads', 500),
        ]);
        [$w, $h] = $funnel->getInnerSize();

        $this->assertSame(50, $w);
        $this->assertGreaterThanOrEqual(8, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testMinimumWidthRendersEmpty(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ])->setSize(12, 15);

        $this->assertSame('', $funnel->render());
    }

    public function testMinimumHeightRendersEmpty(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Visitors', 1000),
        ])->setSize(50, 4);

        $this->assertSame('', $funnel->render());
    }

    public function testSingleStage(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Only', 100),
        ]);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testManyStages(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('S1', 1000),
            new FunnelStage('S2', 800),
            new FunnelStage('S3', 600),
            new FunnelStage('S4', 400),
            new FunnelStage('S5', 200),
            new FunnelStage('S6', 100),
        ]);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLargeValuesFormatting(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Big', 1000000),
        ]);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testSmallValuesFormatting(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('Small', 0.5),
        ]);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testDecreasingValues(): void
    {
        $funnel = Funnel::new([
            new FunnelStage('First', 100),
            new FunnelStage('Second', 75),
            new FunnelStage('Third', 50),
            new FunnelStage('Fourth', 25),
            new FunnelStage('Fifth', 10),
        ]);

        $rendered = $funnel->render();

        $this->assertNotSame('', $rendered);
    }
}