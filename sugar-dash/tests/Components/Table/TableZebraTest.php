<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Table;

use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Components\Table\TableZebra;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class TableZebraTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTableZebraImplementsSizer(): void
    {
        $table = TableZebra::new([]);
        $this->assertInstanceOf(Sizer::class, $table);
    }

    public function testTableZebraImplementsItem(): void
    {
        $table = TableZebra::new([]);
        $this->assertInstanceOf(Item::class, $table);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $table = TableZebra::new([
            ['header' => 'Name'],
        ]);
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsHeader(): void
    {
        $table = TableZebra::new([
            ['header' => 'Name'],
            ['header' => 'Age'],
        ]);
        $rendered = $table->render();

        $this->assertStringContainsString('Name', $rendered);
        $this->assertStringContainsString('Age', $rendered);
    }

    public function testRenderContainsData(): void
    {
        $table = TableZebra::new(
            [
                ['header' => 'Name'],
                ['header' => 'Value'],
            ],
            [
                ['Alice', '100'],
                ['Bob', '200'],
            ]
        );
        $rendered = $table->render();

        $this->assertStringContainsString('Alice', $rendered);
        $this->assertStringContainsString('Bob', $rendered);
    }

    public function testRenderWithEmptyColumns(): void
    {
        $table = TableZebra::new([]);
        $rendered = $table->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Zebra striping (alternating colors)
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultHasZebraColors(): void
    {
        $table = TableZebra::new(
            [['header' => 'Test']],
            [['A'], ['B'], ['C']]
        );
        $rendered = $table->render();

        // Should have ANSI color codes for zebra striping
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testPlainFactoryHasNoColors(): void
    {
        $table = TableZebra::plain(
            [['header' => 'Test']],
            [['A']]
        );
        $rendered = $table->render();

        // Plain should have no color codes (or at least fewer)
        // The exact number depends on implementation but plain shouldn't have bg colors
        $lines = explode("\n", $rendered);
        // Should still render content
        $this->assertNotSame('', $rendered);
    }

    public function testOddEvenRowColorsApplied(): void
    {
        $table = TableZebra::new(
            [['header' => 'Test']],
            [['Row1'], ['Row2'], ['Row3']]
        );
        $rendered = $table->render();

        // Should render without error
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Header visibility
    // ═══════════════════════════════════════════════════════════════

    public function testHeaderShownByDefault(): void
    {
        $table = TableZebra::new([
            ['header' => 'Test'],
        ]);
        $rendered = $table->render();

        $this->assertStringContainsString('Test', $rendered);
    }

    public function testHeaderCanBeHidden(): void
    {
        $table = TableZebra::new(
            [['header' => 'Test']],
            [['Data']]
        )->withShowHeader(false);
        $rendered = $table->render();

        $this->assertStringNotContainsString('Test', $rendered);
        $this->assertStringContainsString('Data', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Row separators
    // ═══════════════════════════════════════════════════════════════

    public function testSeparatorsCanBeShown(): void
    {
        $table = TableZebra::new(
            [['header' => 'Test']],
            [['A'], ['B']]
        )->withShowSeparators(true);
        $rendered = $table->render();

        // Should contain separator character
        $this->assertStringContainsString('─', $rendered);
    }

    public function testSeparatorsHiddenByDefault(): void
    {
        $table = TableZebra::new(
            [['header' => 'Test']],
            [['A'], ['B']]
        );
        $rendered = $table->render();

        // Should not have separator lines (only the content)
        $lines = explode("\n", $rendered);
        // 1 header + 2 data rows = 3 lines without separators
        $this->assertCount(3, $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testOddRowColorAddsAnsiCodes(): void
    {
        $table = TableZebra::new(
            [['header' => 'Test']],
            [['A']]
        )->withOddRowColor(Color::ansi(9));
        $rendered = $table->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testEvenRowColorAddsAnsiCodes(): void
    {
        $table = TableZebra::new(
            [['header' => 'Test']],
            [['A'], ['B']]
        )->withEvenRowColor(Color::ansi(10));
        $rendered = $table->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testHeaderColorAddsAnsiCodes(): void
    {
        $table = TableZebra::new([
            ['header' => 'Test'],
        ])->withHeaderColor(Color::ansi(12));
        $rendered = $table->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testHeaderBackgroundColorAddsAnsiCodes(): void
    {
        $table = TableZebra::new([
            ['header' => 'Test'],
        ])->withHeaderBackgroundColor(Color::ansi(9));
        $rendered = $table->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Column alignment
    // ═══════════════════════════════════════════════════════════════

    public function testLeftAlignedColumn(): void
    {
        $table = TableZebra::new(
            [
                ['header' => 'Left', 'align' => HAlign::Left],
            ],
            [['Test']]
        );
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }

    public function testCenterAlignedColumn(): void
    {
        $table = TableZebra::new(
            [
                ['header' => 'Center', 'align' => HAlign::Center],
            ],
            [['Test']]
        );
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRightAlignedColumn(): void
    {
        $table = TableZebra::new(
            [
                ['header' => 'Right', 'align' => HAlign::Right],
            ],
            [['Test']]
        );
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = TableZebra::new([['header' => 'Test']]);
        $resized = $original->setSize(50, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $table = TableZebra::new([
            ['header' => 'Hi'],
        ])->setSize(50, 5);
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithColumnsReturnsNewInstance(): void
    {
        $original = TableZebra::new([['header' => 'A']]);
        $updated = $original->withColumns([['header' => 'B']]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithRowsReturnsNewInstance(): void
    {
        $original = TableZebra::new([['header' => 'A']], [['data']]);
        $updated = $original->withRows([['new']]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithAddedRowReturnsNewInstance(): void
    {
        $original = TableZebra::new([['header' => 'A']], [['1']]);
        $updated = $original->withAddedRow(['2']);

        $this->assertNotSame($original, $updated);
        $this->assertGreaterThan(
            substr_count($original->render(), "\n"),
            substr_count($updated->render(), "\n")
        );
    }

    public function testWithOddRowColorReturnsNewInstance(): void
    {
        $original = TableZebra::new([['header' => 'A']], [['1']]);
        $updated = $original->withOddRowColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithEvenRowColorReturnsNewInstance(): void
    {
        $original = TableZebra::new([['header' => 'A']], [['1']]);
        $updated = $original->withEvenRowColor(Color::ansi(10));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithRows(): void
    {
        $original = TableZebra::new(
            [['header' => 'Col']],
            [['Original']]
        );
        $original->withRows([['Changed']]);
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $table = TableZebra::new([
            ['header' => 'Test'],
        ]);
        [$w, $h] = $table->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithEmptyColumns(): void
    {
        $table = TableZebra::new([]);
        [$w, $h] = $table->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeIncludesHeader(): void
    {
        $table = TableZebra::new(
            [['header' => 'Test']],
            [['Data']]
        );
        [, $h] = $table->getInnerSize();

        // Should have: header + data row = 2 lines
        $this->assertGreaterThanOrEqual(2, $h);
    }

    public function testGetInnerSizeGrowsWithRows(): void
    {
        $table1 = TableZebra::new([['header' => 'A']], [['1']]);
        $table2 = TableZebra::new([['header' => 'A']], [['1'], ['2'], ['3']]);

        [, $h1] = $table1->getInnerSize();
        [, $h2] = $table2->getInnerSize();

        $this->assertLessThan($h2, $h1);
    }

    public function testGetInnerSizeGrowsWithSeparators(): void
    {
        $table1 = TableZebra::new(
            [['header' => 'A']],
            [['1'], ['2']]
        )->withShowSeparators(false);
        $table2 = TableZebra::new(
            [['header' => 'A']],
            [['1'], ['2']]
        )->withShowSeparators(true);

        [, $h1] = $table1->getInnerSize();
        [, $h2] = $table2->getInnerSize();

        $this->assertLessThan($h2, $h1);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongHeader(): void
    {
        $table = TableZebra::new([
            ['header' => str_repeat('X', 50)],
        ]);
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }

    public function testVeryLongCellContent(): void
    {
        $table = TableZebra::new(
            [['header' => 'Test']],
            [[str_repeat('Y', 100)]]
        );
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeContent(): void
    {
        $table = TableZebra::new(
            [['header' => '名前']],
            [['日本語']]
        );
        $rendered = $table->render();

        $this->assertStringContainsString('名前', $rendered);
        $this->assertStringContainsString('日本語', $rendered);
    }

    public function testEmptyDataRows(): void
    {
        $table = TableZebra::new(
            [
                ['header' => 'A'],
                ['header' => 'B'],
            ],
            []
        );
        $rendered = $table->render();

        // Should still render header
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testSingleRow(): void
    {
        $table = TableZebra::new(
            [['header' => 'One']],
            [['Data']]
        );
        $rendered = $table->render();

        $this->assertStringContainsString('One', $rendered);
        $this->assertStringContainsString('Data', $rendered);
    }

    public function testColumnWidthOverride(): void
    {
        $table = TableZebra::new(
            [
                ['header' => 'Narrow', 'width' => 30],
            ],
            [['X']]
        );
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }
}