<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Radio;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class RadioTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testRadioImplementsSizer(): void
    {
        $radio = Radio::new([['label' => 'Option']]);
        $this->assertInstanceOf(Sizer::class, $radio);
    }

    public function testRadioImplementsItem(): void
    {
        $radio = Radio::new([['label' => 'Option']]);
        $this->assertInstanceOf(Item::class, $radio);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $radio = Radio::new([['label' => 'Option']]);
        $rendered = $radio->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $radio = Radio::new([['label' => 'My Option']]);
        $rendered = $radio->render();

        $this->assertStringContainsString('My Option', $rendered);
    }

    public function testRenderEmptyOptionsReturnsEmpty(): void
    {
        $radio = Radio::new([]);
        $rendered = $radio->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Single selection behavior
    // ═══════════════════════════════════════════════════════════════

    public function testRenderSelectedShowsSelectedChar(): void
    {
        $radio = Radio::new([['label' => 'Option']]);
        $rendered = $radio->render();

        $this->assertStringContainsString('◉', $rendered);
    }

    public function testRenderUnselectedShowsUnselectedChar(): void
    {
        $radio = Radio::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withSelectedIndex(1);

        $rendered = $radio->render();

        // First option should show unselected char
        $this->assertStringContainsString('○', $rendered);
    }

    public function testOnlyOneSelectedAtATime(): void
    {
        $radio = Radio::new([
            ['label' => 'Option 1'],
            ['label' => 'Option 2'],
            ['label' => 'Option 3'],
        ]);

        $rendered = $radio->render();

        // Should have exactly one selected char
        $this->assertSame(1, substr_count($rendered, '◉'));
    }

    public function testSwitchingSelection(): void
    {
        $radio = Radio::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ]);

        // Initially first is selected
        $this->assertStringContainsString('First', $radio->render());

        // After switching, second is selected
        $radio2 = $radio->withSelectedIndex(1);
        $this->assertStringContainsString('Second', $radio2->render());
        // First should no longer be highlighted as selected (but still visible)
        $lines = explode("\n", $radio2->render());
        // Second line should have > indicator (selected char shows ◉)
        $this->assertStringContainsString('>◉', $lines[1]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Selection indicator
    // ═══════════════════════════════════════════════════════════════

    public function testSelectedOptionHasArrow(): void
    {
        $radio = Radio::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withSelectedIndex(1);

        $rendered = $radio->render();
        $lines = explode("\n", $rendered);

        // Second line should have '>' prefix
        $this->assertStringContainsString('>', $lines[1]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testSelectedColorAddsAnsiCodes(): void
    {
        $radio = Radio::new([['label' => 'Option']])
            ->withSelectedColor(Color::ansi(9));
        $rendered = $radio->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testUnselectedColorAddsAnsiCodes(): void
    {
        $radio = Radio::new([['label' => 'Option']])
            ->withUnselectedColor(Color::ansi(8));
        $rendered = $radio->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $radio = Radio::new([['label' => 'Option']])
            ->withSelectedColor(Color::ansi(9));
        $rendered = $radio->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom characters
    // ═══════════════════════════════════════════════════════════════

    public function testWithCharsChangesMarkers(): void
    {
        $radio = Radio::new([['label' => 'Option']])
            ->withChars('(*) ', '( ) ');
        $rendered = $radio->render();

        $this->assertStringContainsString('(*)', $rendered);
        $this->assertStringNotContainsString('◉', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithSelectedIndexReturnsNewInstance(): void
    {
        $original = Radio::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ]);
        $updated = $original->withSelectedIndex(1);

        $this->assertNotSame($original, $updated);
    }

    public function testWithOptionsReturnsNewInstance(): void
    {
        $original = Radio::new([['label' => 'Option']]);
        $updated = $original->withOptions([['label' => 'New Option']]);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithSelectedIndex(): void
    {
        $original = Radio::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ]);
        $original->withSelectedIndex(1);

        $rendered = $original->render();
        $lines = explode("\n", $rendered);
        // Original should still have first option selected
        $this->assertStringContainsString('>', $lines[0]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Radio::new([['label' => 'Option']]);
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $radio = Radio::new([
            ['label' => 'Option'],
            ['label' => 'Longer Option'],
        ]);
        [$w, $h] = $radio->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(2, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeIndexClampedToZero(): void
    {
        $radio = Radio::new([['label' => 'Option']])
            ->withSelectedIndex(-5);

        $this->assertNotSame('', $radio->render());
    }

    public function testOversizedIndexClampedToLast(): void
    {
        $radio = Radio::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withSelectedIndex(100);

        $rendered = $radio->render();
        $lines = explode("\n", $rendered);
        // Last option should be selected
        $this->assertStringContainsString('>', $lines[1]);
    }

    public function testUnicodeLabel(): void
    {
        $radio = Radio::new([['label' => '日本語']]);
        $rendered = $radio->render();

        $this->assertStringContainsString('日本語', $rendered);
    }

    public function testWithOptionsClampsSelectedIndex(): void
    {
        $original = Radio::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withSelectedIndex(1);

        // Reducing to 1 option should clamp index
        $updated = $original->withOptions([['label' => 'Only']]);
        $this->assertNotSame('', $updated->render());
    }
}
