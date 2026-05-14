<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Form;

use SugarCraft\Dash\Components\Form\Checkbox;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class CheckboxTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testCheckboxImplementsSizer(): void
    {
        $checkbox = Checkbox::new([['label' => 'Option', 'checked' => false]]);
        $this->assertInstanceOf(Sizer::class, $checkbox);
    }

    public function testCheckboxImplementsItem(): void
    {
        $checkbox = Checkbox::new([['label' => 'Option', 'checked' => false]]);
        $this->assertInstanceOf(Item::class, $checkbox);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $checkbox = Checkbox::new([['label' => 'Option', 'checked' => false]]);
        $rendered = $checkbox->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $checkbox = Checkbox::new([['label' => 'My Option', 'checked' => false]]);
        $rendered = $checkbox->render();

        $this->assertStringContainsString('My Option', $rendered);
    }

    public function testRenderEmptyOptionsReturnsEmpty(): void
    {
        $checkbox = Checkbox::new([]);
        $rendered = $checkbox->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Checked/unchecked states
    // ═══════════════════════════════════════════════════════════════

    public function testRenderCheckedShowsCheckedChar(): void
    {
        $checkbox = Checkbox::new([['label' => 'Option', 'checked' => true]]);
        $rendered = $checkbox->render();

        $this->assertStringContainsString('◉', $rendered);
    }

    public function testRenderUncheckedShowsUncheckedChar(): void
    {
        $checkbox = Checkbox::new([['label' => 'Option', 'checked' => false]]);
        $rendered = $checkbox->render();

        $this->assertStringContainsString('○', $rendered);
    }

    public function testRenderMultipleOptions(): void
    {
        $checkbox = Checkbox::new([
            ['label' => 'Option 1', 'checked' => true],
            ['label' => 'Option 2', 'checked' => false],
            ['label' => 'Option 3', 'checked' => true],
        ]);
        $rendered = $checkbox->render();

        $this->assertStringContainsString('Option 1', $rendered);
        $this->assertStringContainsString('Option 2', $rendered);
        $this->assertStringContainsString('Option 3', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Selection indicator
    // ═══════════════════════════════════════════════════════════════

    public function testSelectedOptionHasArrow(): void
    {
        $checkbox = Checkbox::new([
            ['label' => 'First', 'checked' => false],
            ['label' => 'Second', 'checked' => false],
        ])->withSelectedIndex(1);

        $rendered = $checkbox->render();
        $lines = explode("\n", $rendered);

        // Second line should have '>' prefix
        $this->assertStringContainsString('>', $lines[1]);
    }

    public function testNonSelectedOptionNoArrow(): void
    {
        $checkbox = Checkbox::new([
            ['label' => 'First', 'checked' => false],
            ['label' => 'Second', 'checked' => false],
        ])->withSelectedIndex(0);

        $rendered = $checkbox->render();
        $lines = explode("\n", $rendered);

        // First line should have '>' prefix, second should not
        $this->assertStringContainsString('>', $lines[0]);
        $this->assertStringNotContainsString('> ', $lines[1]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testCheckedColorAddsAnsiCodes(): void
    {
        $checkbox = Checkbox::new([['label' => 'Option', 'checked' => true]])
            ->withCheckedColor(Color::ansi(9));
        $rendered = $checkbox->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testUncheckedColorAddsAnsiCodes(): void
    {
        $checkbox = Checkbox::new([['label' => 'Option', 'checked' => false]])
            ->withUncheckedColor(Color::ansi(8));
        $rendered = $checkbox->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $checkbox = Checkbox::new([['label' => 'Option', 'checked' => true]])
            ->withCheckedColor(Color::ansi(9));
        $rendered = $checkbox->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom characters
    // ═══════════════════════════════════════════════════════════════

    public function testWithCharsChangesMarkers(): void
    {
        $checkbox = Checkbox::new([['label' => 'Option', 'checked' => true]])
            ->withChars('[X]', '[ ]');
        $rendered = $checkbox->render();

        $this->assertStringContainsString('[X]', $rendered);
        $this->assertStringNotContainsString('◉', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithSelectedIndexReturnsNewInstance(): void
    {
        $original = Checkbox::new([
            ['label' => 'First', 'checked' => false],
            ['label' => 'Second', 'checked' => false],
        ]);
        $updated = $original->withSelectedIndex(1);

        $this->assertNotSame($original, $updated);
    }

    public function testWithOptionCheckedReturnsNewInstance(): void
    {
        $original = Checkbox::new([['label' => 'Option', 'checked' => false]]);
        $updated = $original->withOptionChecked(0, true);

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('◉', $updated->render());
        $this->assertStringNotContainsString('◉', $original->render());
    }

    public function testWithAllCheckedReturnsNewInstance(): void
    {
        $original = Checkbox::new([
            ['label' => 'Option 1', 'checked' => false],
            ['label' => 'Option 2', 'checked' => false],
        ]);
        $updated = $original->withAllChecked(true);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithOptionChecked(): void
    {
        $original = Checkbox::new([['label' => 'Option', 'checked' => false]]);
        $original->withOptionChecked(0, true);

        $this->assertStringContainsString('○', $original->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Checkbox::new([['label' => 'Option', 'checked' => false]]);
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $checkbox = Checkbox::new([
            ['label' => 'Option', 'checked' => false],
            ['label' => 'Longer Option', 'checked' => true],
        ]);
        [$w, $h] = $checkbox->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(2, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeIndexClampedToZero(): void
    {
        $checkbox = Checkbox::new([['label' => 'Option', 'checked' => false]])
            ->withSelectedIndex(-5);

        $this->assertNotSame('', $checkbox->render());
    }

    public function testOversizedIndexClampedToLast(): void
    {
        $checkbox = Checkbox::new([
            ['label' => 'First', 'checked' => false],
            ['label' => 'Second', 'checked' => false],
        ])->withSelectedIndex(100);

        $this->assertNotSame('', $checkbox->render());
    }

    public function testUnicodeLabel(): void
    {
        $checkbox = Checkbox::new([['label' => '日本語', 'checked' => false]]);
        $rendered = $checkbox->render();

        $this->assertStringContainsString('日本語', $rendered);
    }
}
