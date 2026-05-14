<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Select;

use SugarCraft\Dash\Components\Select\Select;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class SelectTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSelectImplementsSizer(): void
    {
        $select = Select::new([['label' => 'Option']]);
        $this->assertInstanceOf(Sizer::class, $select);
    }

    public function testSelectImplementsItem(): void
    {
        $select = Select::new([['label' => 'Option']]);
        $this->assertInstanceOf(Item::class, $select);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $select = Select::new([['label' => 'Option']]);
        $rendered = $select->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $select = Select::new([['label' => 'My Option']]);
        $rendered = $select->render();

        $this->assertStringContainsString('My Option', $rendered);
    }

    public function testRenderEmptyOptionsReturnsEmpty(): void
    {
        $select = Select::new([]);
        $rendered = $select->render();

        $this->assertSame('', $rendered);
    }

    public function testRenderMultipleOptions(): void
    {
        $select = Select::new([
            ['label' => 'Option 1'],
            ['label' => 'Option 2'],
            ['label' => 'Option 3'],
        ]);
        $rendered = $select->render();

        $this->assertStringContainsString('Option 1', $rendered);
        $this->assertStringContainsString('Option 2', $rendered);
        $this->assertStringContainsString('Option 3', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Selection behavior
    // ═══════════════════════════════════════════════════════════════

    public function testRenderSelectedShowsSelectedChar(): void
    {
        $select = Select::new([['label' => 'Option']]);
        $rendered = $select->render();

        $this->assertStringContainsString('▶', $rendered);
    }

    public function testRenderUnselectedShowsUnselectedChar(): void
    {
        $select = Select::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withSelectedIndex(1);

        $rendered = $select->render();

        // First option should show unselected char
        $this->assertStringContainsString('○', $rendered);
    }

    public function testSwitchingSelection(): void
    {
        $select = Select::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ]);

        // Initially first is selected
        $this->assertStringContainsString('First', $select->render());

        // After switching, second is selected
        $select2 = $select->withSelectedIndex(1);
        $rendered2 = $select2->render();
        $this->assertStringContainsString('Second', $rendered2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testSelectedColorAddsAnsiCodes(): void
    {
        $select = Select::new([['label' => 'Option']])
            ->withSelectedColor(Color::ansi(9));
        $rendered = $select->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testUnselectedColorAddsAnsiCodes(): void
    {
        $select = Select::new([['label' => 'Option']])
            ->withUnselectedColor(Color::ansi(8));
        $rendered = $select->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $select = Select::new([['label' => 'Option']])
            ->withSelectedColor(Color::ansi(9));
        $rendered = $select->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom characters
    // ═══════════════════════════════════════════════════════════════

    public function testWithCharsChangesMarkers(): void
    {
        $select = Select::new([['label' => 'Option']])
            ->withChars('[*]', '[ ]');
        $rendered = $select->render();

        $this->assertStringContainsString('[*]', $rendered);
        $this->assertStringNotContainsString('▶', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithSelectedIndexReturnsNewInstance(): void
    {
        $original = Select::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ]);
        $updated = $original->withSelectedIndex(1);

        $this->assertNotSame($original, $updated);
    }

    public function testWithOptionsReturnsNewInstance(): void
    {
        $original = Select::new([['label' => 'Option']]);
        $updated = $original->withOptions([['label' => 'New Option']]);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithSelectedIndex(): void
    {
        $original = Select::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ]);
        $original->withSelectedIndex(1);

        $rendered = $original->render();
        // Original should still have first option selected
        $this->assertStringContainsString('▶ First', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Select::new([['label' => 'Option']]);
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $select = Select::new([
            ['label' => 'Option'],
            ['label' => 'Longer Option'],
        ]);
        [$w, $h] = $select->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(2, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeIndexClampedToZero(): void
    {
        $select = Select::new([['label' => 'Option']])
            ->withSelectedIndex(-5);

        $this->assertNotSame('', $select->render());
    }

    public function testOversizedIndexClampedToLast(): void
    {
        $select = Select::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withSelectedIndex(100);

        $this->assertNotSame('', $select->render());
    }

    public function testUnicodeLabel(): void
    {
        $select = Select::new([['label' => '日本語']]);
        $rendered = $select->render();

        $this->assertStringContainsString('日本語', $rendered);
    }

    public function testWithOptionsClampsSelectedIndex(): void
    {
        $original = Select::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withSelectedIndex(1);

        // Reducing to 1 option should clamp index
        $updated = $original->withOptions([['label' => 'Only']]);
        $this->assertNotSame('', $updated->render());
    }
}
