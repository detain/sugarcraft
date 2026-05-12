<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\EmptyState;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class EmptyStateTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyStateImplementsSizer(): void
    {
        $empty = EmptyState::new();
        $this->assertInstanceOf(Sizer::class, $empty);
    }

    public function testEmptyStateImplementsItem(): void
    {
        $empty = EmptyState::new();
        $this->assertInstanceOf(Item::class, $empty);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $empty = EmptyState::new();
        $rendered = $empty->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsIcon(): void
    {
        $empty = EmptyState::new();
        $rendered = $empty->render();

        $this->assertStringContainsString('📭', $rendered);
    }

    public function testRenderContainsTitle(): void
    {
        $empty = EmptyState::new();
        $rendered = $empty->render();

        $this->assertStringContainsString('Nothing here yet', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset types
    // ═══════════════════════════════════════════════════════════════

    public function testNoResultsPreset(): void
    {
        $empty = EmptyState::noResults();
        $rendered = $empty->render();

        $this->assertStringContainsString('🔍', $rendered);
        $this->assertStringContainsString('No results found', $rendered);
        $this->assertStringContainsString('Try adjusting your search', $rendered);
    }

    public function testErrorPreset(): void
    {
        $empty = EmptyState::error();
        $rendered = $empty->render();

        $this->assertStringContainsString('⚠️', $rendered);
        $this->assertStringContainsString('Something went wrong', $rendered);
    }

    public function testNoDataPreset(): void
    {
        $empty = EmptyState::noData();
        $rendered = $empty->render();

        $this->assertStringContainsString('📊', $rendered);
        $this->assertStringContainsString('No data available', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom icon
    // ═══════════════════════════════════════════════════════════════

    public function testWithIcon(): void
    {
        $empty = EmptyState::new()->withIcon('🎯');
        $rendered = $empty->render();

        $this->assertStringContainsString('🎯', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom title
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitle(): void
    {
        $empty = EmptyState::new()->withTitle('Custom Title');
        $rendered = $empty->render();

        $this->assertStringContainsString('Custom Title', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Description
    // ═══════════════════════════════════════════════════════════════

    public function testWithDescription(): void
    {
        $empty = EmptyState::new()->withDescription('This is a helpful description');
        $rendered = $empty->render();

        $this->assertStringContainsString('This is a helpful description', $rendered);
    }

    public function testNullDescriptionNotShown(): void
    {
        $empty = EmptyState::new()->withDescription(null);
        $rendered = $empty->render();

        // Should only have icon and title (2 lines)
        $lines = explode("\n", $rendered);
        $this->assertLessThanOrEqual(2, count($lines));
    }

    public function testEmptyDescriptionNotShown(): void
    {
        $empty = EmptyState::new()->withDescription('');
        $rendered = $empty->render();

        $lines = explode("\n", $rendered);
        $this->assertLessThanOrEqual(2, count($lines));
    }

    public function testDescriptionWordWrap(): void
    {
        $empty = EmptyState::new()
            ->withDescription('This is a very long description that should be wrapped to fit the available width')
            ->setSize(40, 20);
        $rendered = $empty->render();
        $lines = explode("\n", $rendered);

        // Should have more lines due to wrapping
        $this->assertGreaterThan(3, count($lines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Action hint
    // ═══════════════════════════════════════════════════════════════

    public function testWithAction(): void
    {
        $empty = EmptyState::new()->withAction('Press + to add your first item');
        $rendered = $empty->render();

        $this->assertStringContainsString('Press + to add your first item', $rendered);
    }

    public function testNullActionNotShown(): void
    {
        $empty = EmptyState::new()->withAction(null);
        $rendered = $empty->render();

        $this->assertStringNotContainsString('Press', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testIconColorAddsAnsiCodes(): void
    {
        $empty = EmptyState::new()
            ->withIconColor(Color::ansi(9));
        $rendered = $empty->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTitleColorAddsAnsiCodes(): void
    {
        $empty = EmptyState::new()
            ->withTitleColor(Color::ansi(9));
        $rendered = $empty->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testDescriptionColorAddsAnsiCodes(): void
    {
        $empty = EmptyState::new()
            ->withDescription('Some description')
            ->withDescriptionColor(Color::ansi(9));
        $rendered = $empty->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $empty = EmptyState::new()
            ->withIconColor(Color::ansi(9));
        $rendered = $empty->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = EmptyState::new();
        $resized = $original->setSize(60, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocationAffectsCentering(): void
    {
        $narrow = EmptyState::new()->setSize(30, 10);
        $wide = EmptyState::new()->setSize(80, 10);

        $narrowRendered = $narrow->render();
        $wideRendered = $wide->render();

        // Same icon centered differently
        $this->assertNotSame($narrowRendered, $wideRendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithIconReturnsNewInstance(): void
    {
        $original = EmptyState::new();
        $updated = $original->withIcon('🎉');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('🎉', $updated->render());
    }

    public function testWithTitleReturnsNewInstance(): void
    {
        $original = EmptyState::new();
        $updated = $original->withTitle('New Title');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('New Title', $updated->render());
    }

    public function testWithDescriptionReturnsNewInstance(): void
    {
        $original = EmptyState::new();
        $updated = $original->withDescription('Description');

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithTitle(): void
    {
        $original = EmptyState::new();
        $original->withTitle('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Nothing here yet', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $empty = EmptyState::new();
        [$w, $h] = $empty->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(2, $h); // icon + title
    }

    public function testGetInnerSizeWithDescription(): void
    {
        $empty = EmptyState::new()->withDescription('A description');
        [, $h] = $empty->getInnerSize();

        $this->assertGreaterThan(2, $h);
    }

    public function testGetInnerSizeWithAction(): void
    {
        $empty = EmptyState::new()->withAction('An action');
        [, $h] = $empty->getInnerSize();

        $this->assertGreaterThan(2, $h);
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $empty = EmptyState::new()->setSize(100, 10);
        [$w, ] = $empty->getInnerSize();

        $this->assertSame(100, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongDescription(): void
    {
        $empty = EmptyState::new()
            ->withDescription(str_repeat('word ', 50))
            ->setSize(60, 30);
        $rendered = $empty->render();

        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeIcon(): void
    {
        $empty = EmptyState::new()->withIcon('🎊');
        $rendered = $empty->render();

        $this->assertStringContainsString('🎊', $rendered);
    }

    public function testUnicodeTitle(): void
    {
        $empty = EmptyState::new()->withTitle('日本語タイトル');
        $rendered = $empty->render();

        $this->assertStringContainsString('日本語タイトル', $rendered);
    }
}
