<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Stats;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testStatsImplementsSizer(): void
    {
        $stats = Stats::new([['label' => 'Test', 'value' => '100']]);
        $this->assertInstanceOf(Sizer::class, $stats);
    }

    public function testStatsImplementsItem(): void
    {
        $stats = Stats::new([['label' => 'Test', 'value' => '100']]);
        $this->assertInstanceOf(Item::class, $stats);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $stats = Stats::new([['label' => 'Test', 'value' => '100']]);
        $rendered = $stats->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $stats = Stats::new([['label' => 'Users', 'value' => '500']]);
        $rendered = $stats->render();

        $this->assertStringContainsString('Users', $rendered);
    }

    public function testRenderContainsValue(): void
    {
        $stats = Stats::new([['label' => 'Users', 'value' => '500']]);
        $rendered = $stats->render();

        $this->assertStringContainsString('500', $rendered);
    }

    public function testEmptyItemsReturnsEmpty(): void
    {
        $stats = Stats::new([]);
        $rendered = $stats->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testNewFactory(): void
    {
        $stats = Stats::new([
            ['label' => 'Revenue', 'value' => '$1,234'],
            ['label' => 'Users', 'value' => '5,678'],
        ]);
        $rendered = $stats->render();

        $this->assertStringContainsString('Revenue', $rendered);
        $this->assertStringContainsString('$1,234', $rendered);
    }

    public function testHorizontalFactory(): void
    {
        $stats = Stats::horizontal([
            ['label' => 'Revenue', 'value' => '$1,234'],
        ]);
        $rendered = $stats->render();

        // Horizontal style uses ─ as separator
        $this->assertStringContainsString('─', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multiple stats
    // ═══════════════════════════════════════════════════════════════

    public function testMultipleStatsRenderAll(): void
    {
        $stats = Stats::new([
            ['label' => 'Stat 1', 'value' => '100'],
            ['label' => 'Stat 2', 'value' => '200'],
            ['label' => 'Stat 3', 'value' => '300'],
        ]);
        $rendered = $stats->render();

        $this->assertStringContainsString('Stat 1', $rendered);
        $this->assertStringContainsString('Stat 2', $rendered);
        $this->assertStringContainsString('Stat 3', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testLabelColorAddsAnsiCodes(): void
    {
        $stats = Stats::new([['label' => 'Test', 'value' => '100']])
            ->withLabelColor(Color::ansi(12));
        $rendered = $stats->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testValueColorAddsAnsiCodes(): void
    {
        $stats = Stats::new([['label' => 'Test', 'value' => '100']])
            ->withValueColor(Color::ansi(9));
        $rendered = $stats->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testSeparatorColorAddsAnsiCodes(): void
    {
        $stats = Stats::new([
            ['label' => 'A', 'value' => '1'],
            ['label' => 'B', 'value' => '2'],
        ])->withSeparatorColor(Color::ansi(8));
        $rendered = $stats->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testItemColorOverridesDefault(): void
    {
        $stats = Stats::new([[
            'label' => 'Test',
            'value' => '100',
            'color' => Color::ansi(9),
        ]]);
        $rendered = $stats->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Separator handling
    // ═══════════════════════════════════════════════════════════════

    public function testCustomSeparator(): void
    {
        $stats = Stats::new([
            ['label' => 'A', 'value' => '1'],
            ['label' => 'B', 'value' => '2'],
        ])->withSeparator(':');
        $rendered = $stats->render();

        $this->assertStringContainsString(':', $rendered);
    }

    public function testHorizontalSeparatorStyleChangesHeight(): void
    {
        $vertical = Stats::new([['label' => 'A', 'value' => '1']]);
        $horizontal = Stats::horizontal([['label' => 'A', 'value' => '1']]);

        [, $vHeight] = $vertical->getInnerSize();
        [, $hHeight] = $horizontal->getInnerSize();

        $this->assertSame(2, $vHeight);
        $this->assertSame(3, $hHeight);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Stats::new([['label' => 'T', 'value' => '1']]);
        $resized = $original->setSize(80, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = Stats::new([['label' => 'A', 'value' => '1']]);
        $updated = $original->withItems([['label' => 'B', 'value' => '2']]);

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('B', $updated->render());
        $this->assertStringNotContainsString('A', $updated->render());
    }

    public function testWithLabelColorReturnsNewInstance(): void
    {
        $original = Stats::new([['label' => 'T', 'value' => '1']]);
        $updated = $original->withLabelColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithValueColorReturnsNewInstance(): void
    {
        $original = Stats::new([['label' => 'T', 'value' => '1']]);
        $updated = $original->withValueColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithItems(): void
    {
        $original = Stats::new([['label' => 'Original', 'value' => '1']]);
        $original->withItems([['label' => 'Changed', 'value' => '2']]);
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $stats = Stats::new([['label' => 'Test', 'value' => '100']]);
        [$w, $h] = $stats->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeVerticalStyleHasHeightTwo(): void
    {
        $stats = Stats::new([['label' => 'T', 'value' => '1']]);
        [, $h] = $stats->getInnerSize();

        $this->assertSame(2, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnicodeLabels(): void
    {
        $stats = Stats::new([
            ['label' => 'ユーザー', 'value' => '1000'],
            ['label' => '売上', 'value' => '¥999'],
        ]);
        $rendered = $stats->render();

        $this->assertStringContainsString('ユーザー', $rendered);
        $this->assertStringContainsString('売上', $rendered);
    }

    public function testUnicodeValues(): void
    {
        $stats = Stats::new([
            ['label' => 'Revenue', 'value' => '¥1,234'],
        ]);
        $rendered = $stats->render();

        $this->assertStringContainsString('¥1,234', $rendered);
    }

    public function testVeryLongValue(): void
    {
        $stats = Stats::new([[
            'label' => 'Large Number',
            'value' => str_repeat('9', 50),
        ]]);
        $rendered = $stats->render();

        $this->assertStringContainsString(str_repeat('9', 50), $rendered);
    }

    public function testAlignmentSetting(): void
    {
        $stats = Stats::new([['label' => 'Test', 'value' => '100']])
            ->withAlignment('center');
        $rendered = $stats->render();

        // Just verify it renders without error
        $this->assertNotSame('', $rendered);
    }
}
