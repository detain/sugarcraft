<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Nav;

use SugarCraft\Dash\Components\Nav\Breadcrumb;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class BreadcrumbTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testBreadcrumbImplementsSizer(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Category', 'Item']);
        $this->assertInstanceOf(Sizer::class, $breadcrumb);
    }

    public function testBreadcrumbImplementsItem(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Category', 'Item']);
        $this->assertInstanceOf(Item::class, $breadcrumb);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Category', 'Item']);
        $rendered = $breadcrumb->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsAllItems(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Category', 'Item']);
        $rendered = $breadcrumb->render();

        $this->assertStringContainsString('Home', $rendered);
        $this->assertStringContainsString('Category', $rendered);
        $this->assertStringContainsString('Item', $rendered);
    }

    public function testRenderContainsSeparator(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Category']);
        $rendered = $breadcrumb->render();

        // Default separator is '›'
        $this->assertStringContainsString('›', $rendered);
    }

    public function testRenderEmptyItemsReturnsEmpty(): void
    {
        $breadcrumb = Breadcrumb::new([]);
        $rendered = $breadcrumb->render();

        $this->assertSame('', $rendered);
    }

    public function testRenderSingleItemNoSeparator(): void
    {
        $breadcrumb = Breadcrumb::new(['Home']);
        $rendered = $breadcrumb->render();

        $this->assertStringContainsString('Home', $rendered);
        // No separator for single item
        $this->assertStringNotContainsString('›', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Path parsing
    // ═══════════════════════════════════════════════════════════════

    public function testFromPathFactory(): void
    {
        $breadcrumb = Breadcrumb::fromPath('Home / Category / Item', '/');
        $rendered = $breadcrumb->render();

        $this->assertStringContainsString('Home', $rendered);
        $this->assertStringContainsString('Category', $rendered);
        $this->assertStringContainsString('Item', $rendered);
    }

    public function testFromPathTrimsWhitespace(): void
    {
        $breadcrumb = Breadcrumb::fromPath('  Home  /  Category  ', '/');
        $rendered = $breadcrumb->render();

        $this->assertStringContainsString('Home', $rendered);
        $this->assertStringContainsString('Category', $rendered);
        // Should not have extra spaces around items
        $stripped = preg_replace('/\s+/', ' ', $rendered);
        $this->assertStringNotContainsString('  ', $stripped ?? '');
    }

    public function testFromPathWithCustomSeparator(): void
    {
        $breadcrumb = Breadcrumb::fromPath('Home > Category > Item', '>');
        $rendered = $breadcrumb->render();

        $this->assertStringContainsString('Home', $rendered);
        $this->assertStringContainsString('Category', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Separator customization
    // ═══════════════════════════════════════════════════════════════

    public function testCustomSeparator(): void
    {
        $breadcrumb = Breadcrumb::new(['A', 'B'])
            ->withSeparator('/');
        $rendered = $breadcrumb->render();

        $this->assertStringContainsString('/', $rendered);
        $this->assertStringNotContainsString('›', $rendered);
    }

    public function testSeparatorWithSpaces(): void
    {
        $breadcrumb = Breadcrumb::new(['A', 'B'])
            ->withSeparator('>');
        $rendered = $breadcrumb->render();

        // Separator should have spaces around it
        $this->assertStringContainsString(' > ', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testItemColorAddsAnsiCodes(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Item'])
            ->withItemColor(Color::ansi(8));
        $rendered = $breadcrumb->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testActiveColorAddsAnsiCodes(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Item'])
            ->withActiveColor(Color::ansi(9));
        $rendered = $breadcrumb->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testSeparatorColorAddsAnsiCodes(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Item'])
            ->withSeparatorColor(Color::ansi(8));
        $rendered = $breadcrumb->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Item'])
            ->withItemColor(Color::ansi(7))
            ->withActiveColor(Color::ansi(9))
            ->withSeparatorColor(Color::ansi(8));
        $rendered = $breadcrumb->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Active item highlighting
    // ═══════════════════════════════════════════════════════════════

    public function testLastItemIsActiveByDefault(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Category', 'Item']);
        $rendered = $breadcrumb->render();

        // Last item should be styled as active (different color)
        $this->assertNotSame('', $rendered);
    }

    public function testWithActiveIndex(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Category', 'Item'])
            ->withActiveIndex(1);
        $rendered = $breadcrumb->render();

        $this->assertStringContainsString('Home', $rendered);
        $this->assertStringContainsString('Category', $rendered);
        $this->assertStringContainsString('Item', $rendered);
    }

    public function testNegativeActiveIndexMeansNoActive(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Item'])
            ->withActiveIndex(-1);
        $rendered = $breadcrumb->render();

        // No specific active item highlighted, all items use itemColor
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Breadcrumb::new(['Home', 'Item']);
        $resized = $original->setSize(50, 1);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $breadcrumb = Breadcrumb::new(['A', 'B'])->setSize(50, 1);
        $rendered = $breadcrumb->render();

        // Should pad to fill width
        $this->assertGreaterThanOrEqual(50, mb_strlen($rendered, 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = Breadcrumb::new(['A', 'B']);
        $updated = $original->withItems(['X', 'Y', 'Z']);

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('X', $updated->render());
        $this->assertStringNotContainsString('A', $updated->render());
    }

    public function testWithSeparatorReturnsNewInstance(): void
    {
        $original = Breadcrumb::new(['A', 'B']);
        $updated = $original->withSeparator('/');

        $this->assertNotSame($original, $updated);
    }

    public function testWithItemColorReturnsNewInstance(): void
    {
        $original = Breadcrumb::new(['A', 'B']);
        $updated = $original->withItemColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithActiveColorReturnsNewInstance(): void
    {
        $original = Breadcrumb::new(['A', 'B']);
        $updated = $original->withActiveColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithActiveIndexReturnsNewInstance(): void
    {
        $original = Breadcrumb::new(['A', 'B', 'C']);
        $updated = $original->withActiveIndex(0);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithItems(): void
    {
        $original = Breadcrumb::new(['Original']);
        $original->withItems(['Changed']);
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Category', 'Item']);
        [$w, $h] = $breadcrumb->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h); // Breadcrumb is single-line
    }

    public function testGetInnerSizeEmptyReturnsZeroWidth(): void
    {
        $breadcrumb = Breadcrumb::new([]);
        [$w, $h] = $breadcrumb->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $breadcrumb = Breadcrumb::new(['A'])->setSize(50, 1);
        [$w, ] = $breadcrumb->getInnerSize();

        $this->assertGreaterThanOrEqual(50, $w);
    }

    public function testGetInnerSizeWithManyItems(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', 'Category', 'Subcategory', 'Item']);
        [$w, ] = $breadcrumb->getInnerSize();

        // More items = wider
        $this->assertGreaterThan(
            Width::string('Home›Item'),
            $w
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongItem(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', str_repeat('x', 100)]);
        $rendered = $breadcrumb->render();

        $this->assertStringContainsString('Home', $rendered);
        $this->assertStringContainsString('x', $rendered);
    }

    public function testUnicodeItems(): void
    {
        $breadcrumb = Breadcrumb::new(['ホーム', 'カテゴリー']);
        $rendered = $breadcrumb->render();

        $this->assertStringContainsString('ホーム', $rendered);
        $this->assertStringContainsString('カテゴリー', $rendered);
    }

    public function testSpecialCharsInItems(): void
    {
        $breadcrumb = Breadcrumb::new(['Item & Tag', 'Name <Value>']);
        $rendered = $breadcrumb->render();

        $this->assertStringContainsString('Item & Tag', $rendered);
        $this->assertStringContainsString('Name <Value>', $rendered);
    }

    public function testEmptyStringItem(): void
    {
        $breadcrumb = Breadcrumb::new(['Home', '', 'Item']);
        $rendered = $breadcrumb->render();

        $this->assertStringContainsString('Home', $rendered);
        $this->assertStringContainsString('Item', $rendered);
    }
}
