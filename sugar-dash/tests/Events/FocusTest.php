<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Events;

use SugarCraft\Dash\Events\Focus;
use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Grid\Bar;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class FocusTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testFocusImplementsSizer(): void
    {
        $focus = Focus::new([Text::new('test')]);
        $this->assertInstanceOf(Sizer::class, $focus);
    }

    public function testFocusImplementsItem(): void
    {
        $focus = Focus::new([Text::new('test')]);
        $this->assertInstanceOf(Item::class, $focus);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $focus = Focus::new([Text::new('Hello')]);
        $this->assertNotSame('', $focus->render());
    }

    public function testRenderFocusedItem(): void
    {
        $items = [Text::new('First'), Text::new('Second'), Text::new('Third')];
        $focus = Focus::new($items)->withFocusedIndex(1);
        $rendered = $focus->render();

        // Should render the second item (index 1)
        $this->assertStringContainsString('Second', $rendered);
    }

    public function testRenderEmptyItems(): void
    {
        $focus = Focus::new([]);
        $this->assertSame('', $focus->render());
    }

    public function testDefaultFocusedIndexIsZero(): void
    {
        $items = [Text::new('First'), Text::new('Second')];
        $focus = Focus::new($items);
        $rendered = $focus->render();

        // Should render the first item by default
        $this->assertStringContainsString('First', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Focus::new([Text::new('test')]);
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testGetInnerSizeReturnsFocusedItemSize(): void
    {
        $text = Text::new('Hello');
        $focus = Focus::new([$text])->setSize(20, 5);
        [$w, $h] = $focus->getInnerSize();

        // Should return dimensions of the focused item (text "Hello")
        $this->assertGreaterThanOrEqual(5, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Navigation
    // ═══════════════════════════════════════════════════════════════

    public function testNextWrapsAround(): void
    {
        $items = [Text::new('First'), Text::new('Second'), Text::new('Third')];
        $focus = Focus::new($items)->withFocusedIndex(2);
        $next = $focus->next();

        // Should wrap to index 0
        $this->assertSame(0, $this->getFocusedIndex($next));
    }

    public function testNextAdvancesIndex(): void
    {
        $items = [Text::new('First'), Text::new('Second'), Text::new('Third')];
        $focus = Focus::new($items)->withFocusedIndex(0);
        $next = $focus->next();

        $this->assertSame(1, $this->getFocusedIndex($next));
    }

    public function testPreviousWrapsAround(): void
    {
        $items = [Text::new('First'), Text::new('Second'), Text::new('Third')];
        $focus = Focus::new($items)->withFocusedIndex(0);
        $prev = $focus->previous();

        // Should wrap to last index
        $this->assertSame(2, $this->getFocusedIndex($prev));
    }

    public function testPreviousDecreasesIndex(): void
    {
        $items = [Text::new('First'), Text::new('Second'), Text::new('Third')];
        $focus = Focus::new($items)->withFocusedIndex(1);
        $prev = $focus->previous();

        $this->assertSame(0, $this->getFocusedIndex($prev));
    }

    public function testFirst(): void
    {
        $items = [Text::new('First'), Text::new('Second'), Text::new('Third')];
        $focus = Focus::new($items)->withFocusedIndex(2);
        $first = $focus->first();

        $this->assertSame(0, $this->getFocusedIndex($first));
    }

    public function testLast(): void
    {
        $items = [Text::new('First'), Text::new('Second'), Text::new('Third')];
        $focus = Focus::new($items)->withFocusedIndex(0);
        $last = $focus->last();

        $this->assertSame(2, $this->getFocusedIndex($last));
    }

    public function testNavigationOnEmptyFocus(): void
    {
        $focus = Focus::new([]);
        $this->assertSame($focus, $focus->next());
        $this->assertSame($focus, $focus->previous());
    }

    // ═══════════════════════════════════════════════════════════════
    // renderAll
    // ═══════════════════════════════════════════════════════════════

    public function testRenderAllReturnsAllItems(): void
    {
        $items = [Text::new('First'), Text::new('Second')];
        $focus = Focus::new($items);
        $rendered = $focus->renderAll();

        $this->assertCount(2, $rendered);
        $this->assertStringContainsString('First', $rendered[0]);
        $this->assertStringContainsString('Second', $rendered[1]);
    }

    public function testRenderAllEmptyReturnsEmptyArray(): void
    {
        $focus = Focus::new([]);
        $this->assertSame([], $focus->renderAll());
    }

    public function testRenderAllFocusedHasAnsiCodes(): void
    {
        $items = [Text::new('First'), Text::new('Second')];
        $focus = Focus::new($items)->withFocusedIndex(0);
        $rendered = $focus->renderAll();

        // Focused item should have ANSI codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered[0]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color styling
    // ═══════════════════════════════════════════════════════════════

    public function testWithFocusForegroundAddsAnsiCodes(): void
    {
        $focus = Focus::new([Text::new('test')])
            ->withFocusForeground(Color::ansi(12));
        $rendered = $focus->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testWithFocusBackgroundAddsAnsiCodes(): void
    {
        $focus = Focus::new([Text::new('test')])
            ->withFocusBackground(Color::ansi(9));
        $rendered = $focus->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = Focus::new([Text::new('test')]);
        $modified = $original->withItems([Text::new('new')]);

        $this->assertNotSame($original, $modified);
    }

    public function testWithFocusedIndexReturnsNewInstance(): void
    {
        $original = Focus::new([Text::new('First'), Text::new('Second')]);
        $modified = $original->withFocusedIndex(1);

        $this->assertNotSame($original, $modified);
    }

    public function testWithFocusForegroundReturnsNewInstance(): void
    {
        $original = Focus::new([Text::new('test')]);
        $modified = $original->withFocusForeground(Color::ansi(9));

        $this->assertNotSame($original, $modified);
    }

    public function testWithFocusBackgroundReturnsNewInstance(): void
    {
        $original = Focus::new([Text::new('test')]);
        $modified = $original->withFocusBackground(Color::ansi(9));

        $this->assertNotSame($original, $modified);
    }

    public function testWithUnfocusedForegroundReturnsNewInstance(): void
    {
        $original = Focus::new([Text::new('test')]);
        $modified = $original->withUnfocusedForeground(Color::ansi(9));

        $this->assertNotSame($original, $modified);
    }

    public function testWithUnfocusedBackgroundReturnsNewInstance(): void
    {
        $original = Focus::new([Text::new('test')]);
        $modified = $original->withUnfocusedBackground(Color::ansi(9));

        $this->assertNotSame($original, $modified);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testFocusedIndexClampedToValidRange(): void
    {
        $items = [Text::new('First'), Text::new('Second')];
        $focus = Focus::new($items)->withFocusedIndex(100);

        // Should clamp to last valid index (1)
        $this->assertSame(1, $this->getFocusedIndex($focus));
    }

    public function testWithItemsClampsFocusedIndex(): void
    {
        $focus = Focus::new([Text::new('A'), Text::new('B'), Text::new('C')])->withFocusedIndex(2);
        $modified = $focus->withItems([Text::new('X'), Text::new('Y')]);

        // Should clamp to new last index (1)
        $this->assertSame(1, $this->getFocusedIndex($modified));
    }

    public function testNavigationPreservesImmutability(): void
    {
        $items = [Text::new('First'), Text::new('Second')];
        $original = Focus::new($items);
        $next = $original->next();

        // Original should be unchanged
        $this->assertSame(0, $this->getFocusedIndex($original));
        // Modified should be different
        $this->assertNotSame($original, $next);
    }

    public function testChainedNavigation(): void
    {
        $items = [Text::new('A'), Text::new('B'), Text::new('C')];
        $focus = Focus::new($items);

        $afterTwoNext = $focus->next()->next();
        $this->assertSame(2, $this->getFocusedIndex($afterTwoNext));

        $afterWrap = $afterTwoNext->next();
        $this->assertSame(0, $this->getFocusedIndex($afterWrap));
    }

    public function testRenderWithDifferentItemTypes(): void
    {
        $items = [
            Text::new('Text Item'),
            Bar::new('Bar Item'),
        ];
        $focus = Focus::new($items)->withFocusedIndex(1);
        $rendered = $focus->render();

        $this->assertStringContainsString('Bar Item', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helper
    // ═══════════════════════════════════════════════════════════════

    private function getFocusedIndex(Focus $focus): int
    {
        // Use reflection to get the private property
        $reflection = new \ReflectionClass($focus);
        $property = $reflection->getProperty('focusedIndex');
        $property->setAccessible(true);
        return $property->getValue($focus);
    }
}
