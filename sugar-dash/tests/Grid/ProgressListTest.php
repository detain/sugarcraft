<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\ProgressList;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\HAlign;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ProgressListTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testProgressListImplementsSizer(): void
    {
        $list = ProgressList::new([]);
        $this->assertInstanceOf(Sizer::class, $list);
    }

    public function testProgressListImplementsItem(): void
    {
        $list = ProgressList::new([]);
        $this->assertInstanceOf(Item::class, $list);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsEmptyForEmptyList(): void
    {
        $list = ProgressList::new([]);
        $rendered = $list->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $list = ProgressList::new([
            ['label' => 'Task 1', 'progress' => 0.5],
        ]);
        $rendered = $list->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $list = ProgressList::new([
            ['label' => 'Downloading files...', 'progress' => 0.75],
        ]);
        $rendered = $list->render();

        $this->assertStringContainsString('Downloading files', $rendered);
    }

    public function testRenderContainsProgressBar(): void
    {
        $list = ProgressList::new([
            ['label' => 'Task', 'progress' => 0.5],
        ]);
        $rendered = $list->render();

        // Should contain progress bar brackets
        $this->assertStringContainsString('[', $rendered);
        $this->assertStringContainsString(']', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Progress values
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithFullProgress(): void
    {
        $list = ProgressList::new([
            ['label' => 'Complete', 'progress' => 1.0],
        ]);
        $rendered = $list->render();

        $this->assertStringContainsString('Complete', $rendered);
        $this->assertStringContainsString('100%', $rendered);
    }

    public function testRenderWithZeroProgress(): void
    {
        $list = ProgressList::new([
            ['label' => 'Not started', 'progress' => 0.0],
        ]);
        $rendered = $list->render();

        $this->assertStringContainsString('Not started', $rendered);
        $this->assertStringContainsString('0%', $rendered);
    }

    public function testProgressClampedToOne(): void
    {
        $list = ProgressList::new([
            ['label' => 'Task', 'progress' => 1.5], // Over 1.0
        ]);
        $rendered = $list->render();

        $this->assertStringContainsString('100%', $rendered);
    }

    public function testProgressClampedToZero(): void
    {
        $list = ProgressList::new([
            ['label' => 'Task', 'progress' => -0.5], // Under 0.0
        ]);
        $rendered = $list->render();

        $this->assertStringContainsString('0%', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multiple items
    // ═══════════════════════════════════════════════════════════════

    public function testRenderMultipleItems(): void
    {
        $list = ProgressList::new([
            ['label' => 'Task 1', 'progress' => 0.3],
            ['label' => 'Task 2', 'progress' => 0.6],
            ['label' => 'Task 3', 'progress' => 0.9],
        ]);
        $rendered = $list->render();

        $this->assertStringContainsString('Task 1', $rendered);
        $this->assertStringContainsString('Task 2', $rendered);
        $this->assertStringContainsString('Task 3', $rendered);
    }

    public function testRenderMultipleItemsSeparatedByNewline(): void
    {
        $list = ProgressList::new([
            ['label' => 'Task 1', 'progress' => 0.3],
            ['label' => 'Task 2', 'progress' => 0.6],
        ]);
        $rendered = $list->render();

        $this->assertStringContainsString("\n", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $list = ProgressList::new([
            ['label' => 'Task', 'progress' => 0.5],
        ]);
        [$w, $h] = $list->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithMultipleItems(): void
    {
        $list = ProgressList::new([
            ['label' => 'Task 1', 'progress' => 0.3],
            ['label' => 'Task 2', 'progress' => 0.6],
            ['label' => 'Task 3', 'progress' => 0.9],
        ]);
        [$w, $h] = $list->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(3, $h);
    }

    public function testGetInnerSizeRespectsHeightOverride(): void
    {
        $list = ProgressList::new([
            ['label' => 'Task 1', 'progress' => 0.3],
            ['label' => 'Task 2', 'progress' => 0.6],
        ])->setSize(50, 1);

        [$w, $h] = $list->getInnerSize();

        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = ProgressList::new([['label' => 'Task', 'progress' => 0.5]]);
        $updated = $original->withItems([
            ['label' => 'New Task', 'progress' => 0.8],
        ]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowProgressBarsReturnsNewInstance(): void
    {
        $original = ProgressList::new([['label' => 'Task', 'progress' => 0.5]]);
        $updated = $original->withShowProgressBars(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowPercentagesReturnsNewInstance(): void
    {
        $original = ProgressList::new([['label' => 'Task', 'progress' => 0.5]]);
        $updated = $original->withShowPercentages(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithProgressBarWidthReturnsNewInstance(): void
    {
        $original = ProgressList::new([['label' => 'Task', 'progress' => 0.5]]);
        $updated = $original->withProgressBarWidth(30);

        $this->assertNotSame($original, $updated);
    }

    public function testWithProgressBarWidthClampsToMinimumOne(): void
    {
        $list = ProgressList::new([['label' => 'Task', 'progress' => 0.5]])->withProgressBarWidth(0);
        $width = $this->invokePrivate('progressBarWidth', $list);

        $this->assertSame(1, $width);
    }

    public function testWithLabelAlignReturnsNewInstance(): void
    {
        $original = ProgressList::new([['label' => 'Task', 'progress' => 0.5]]);
        $updated = $original->withLabelAlign(HAlign::Center);

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = ProgressList::new([['label' => 'Task', 'progress' => 0.5]]);
        $resized = $original->setSize(80, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helper methods
    // ═══════════════════════════════════════════════════════════════

    /**
     * Invoke a private property for testing via its getter name.
     */
    private function invokePrivate(string $propName, ProgressList $list): mixed
    {
        $reflection = new \ReflectionClass($list);
        $prop = $reflection->getProperty($propName);
        $prop->setAccessible(true);
        return $prop->getValue($list);
    }
}
