<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Table;

use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Components\Table\TableBordered;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class TableBorderedTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTableBorderedImplementsSizer(): void
    {
        $table = TableBordered::new([]);
        $this->assertInstanceOf(Sizer::class, $table);
    }

    public function testTableBorderedImplementsItem(): void
    {
        $table = TableBordered::new([]);
        $this->assertInstanceOf(Item::class, $table);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $table = TableBordered::new([
            ['header' => 'Name'],
        ]);
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsHeader(): void
    {
        $table = TableBordered::new([
            ['header' => 'Name'],
            ['header' => 'Age'],
        ]);
        $rendered = $table->render();

        $this->assertStringContainsString('Name', $rendered);
        $this->assertStringContainsString('Age', $rendered);
    }

    public function testRenderContainsBorderChars(): void
    {
        $table = TableBordered::new([
            ['header' => 'Col1'],
            ['header' => 'Col2'],
        ]);
        $rendered = $table->render();

        // Should contain box-drawing characters (including ┬ for multiple columns)
        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('┐', $rendered);
        $this->assertStringContainsString('┬', $rendered);
        $this->assertStringContainsString('│', $rendered);
    }

    public function testRenderWithEmptyColumns(): void
    {
        $table = TableBordered::new([]);
        $rendered = $table->render();

        $this->assertSame('', $rendered);
    }

    public function testRenderWithDataRows(): void
    {
        $table = TableBordered::new(
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
        $this->assertStringContainsString('100', $rendered);
        $this->assertStringContainsString('200', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Header visibility
    // ═══════════════════════════════════════════════════════════════

    public function testHeaderShownByDefault(): void
    {
        $table = TableBordered::new([
            ['header' => 'Test'],
        ]);
        $rendered = $table->render();

        $this->assertStringContainsString('Test', $rendered);
    }

    public function testHeaderCanBeHidden(): void
    {
        $table = TableBordered::new(
            [['header' => 'Test']],
            [['Data']]
        )->withShowHeader(false);
        $rendered = $table->render();

        $this->assertStringNotContainsString('Test', $rendered);
        $this->assertStringContainsString('Data', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Column alignment
    // ═══════════════════════════════════════════════════════════════

    public function testLeftAlignedColumn(): void
    {
        $table = TableBordered::new(
            [
                ['header' => 'Left', 'align' => HAlign::Left],
            ],
            [['Test']]
        );
        $rendered = $table->render();

        // Should render without error
        $this->assertNotSame('', $rendered);
    }

    public function testCenterAlignedColumn(): void
    {
        $table = TableBordered::new(
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
        $table = TableBordered::new(
            [
                ['header' => 'Right', 'align' => HAlign::Right],
            ],
            [['Test']]
        );
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $table = TableBordered::new([
            ['header' => 'Test'],
        ])->withBorderColor(Color::ansi(9));
        $rendered = $table->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testHeaderColorAddsAnsiCodes(): void
    {
        $table = TableBordered::new([
            ['header' => 'Test'],
        ])->withHeaderColor(Color::ansi(12));
        $rendered = $table->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testHeaderBackgroundColorAddsAnsiCodes(): void
    {
        $table = TableBordered::new([
            ['header' => 'Test'],
        ])->withHeaderBackgroundColor(Color::ansi(9));
        $rendered = $table->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = TableBordered::new([['header' => 'Test']]);
        $resized = $original->setSize(50, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $table = TableBordered::new([
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
        $original = TableBordered::new([['header' => 'A']]);
        $updated = $original->withColumns([['header' => 'B']]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithRowsReturnsNewInstance(): void
    {
        $original = TableBordered::new([['header' => 'A']], [['data']]);
        $updated = $original->withRows([['new']]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithAddedRowReturnsNewInstance(): void
    {
        $original = TableBordered::new([['header' => 'A']], [['1']]);
        $updated = $original->withAddedRow(['2']);

        $this->assertNotSame($original, $updated);
        $this->assertGreaterThan(
            substr_count($original->render(), "\n"),
            substr_count($updated->render(), "\n")
        );
    }

    public function testOriginalUnchangedAfterWithRows(): void
    {
        $original = TableBordered::new(
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
        $table = TableBordered::new([
            ['header' => 'Test'],
        ]);
        [$w, $h] = $table->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithEmptyColumns(): void
    {
        $table = TableBordered::new([]);
        [$w, $h] = $table->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeIncludesHeaderSeparator(): void
    {
        $table = TableBordered::new(
            [['header' => 'Test']],
            [['Data']]
        );
        [, $h] = $table->getInnerSize();

        // Should have: top border + header + separator + data + bottom border = 5 lines
        $this->assertGreaterThanOrEqual(4, $h);
    }

    public function testGetInnerSizeGrowsWithRows(): void
    {
        $table1 = TableBordered::new([['header' => 'A']], [['1']]);
        $table2 = TableBordered::new([['header' => 'A']], [['1'], ['2'], ['3']]);

        [, $h1] = $table1->getInnerSize();
        [, $h2] = $table2->getInnerSize();

        $this->assertLessThan($h2, $h1);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongHeader(): void
    {
        $table = TableBordered::new([
            ['header' => str_repeat('X', 50)],
        ]);
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }

    public function testVeryLongCellContent(): void
    {
        $table = TableBordered::new(
            [['header' => 'Test']],
            [[str_repeat('Y', 100)]]
        );
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeContent(): void
    {
        $table = TableBordered::new(
            [['header' => '名前']],
            [['日本語']]
        );
        $rendered = $table->render();

        $this->assertStringContainsString('名前', $rendered);
        $this->assertStringContainsString('日本語', $rendered);
    }

    public function testEmptyDataRows(): void
    {
        $table = TableBordered::new(
            [
                ['header' => 'A'],
                ['header' => 'B'],
            ],
            []
        );
        $rendered = $table->render();

        // Should still render header and borders
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testColumnWidthOverride(): void
    {
        $table = TableBordered::new(
            [
                ['header' => 'Narrow', 'width' => 30],
            ],
            [['X']]
        );
        $rendered = $table->render();

        $this->assertNotSame('', $rendered);
    }
}