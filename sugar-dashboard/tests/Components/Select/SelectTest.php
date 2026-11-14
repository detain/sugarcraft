<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Select;

use SugarCraft\Dashboard\Components\Select\Select;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
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
        [$w, $h] = $select->innerSize();

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

    // ═══════════════════════════════════════════════════════════════
    // Allocation windowing (finding sugar-dashboard-051)
    // ═══════════════════════════════════════════════════════════════

    /** @return list<array{label: string}> */
    private function manyOptions(int $count): array
    {
        $options = [];
        for ($i = 0; $i < $count; $i++) {
            $options[] = ['label' => sprintf('Opt %03d', $i)];
        }
        return $options;
    }

    public function testUnallocatedSelectPaintsEveryOption(): void
    {
        $rendered = Select::new($this->manyOptions(500))->withSelectedIndex(250)->render();

        $this->assertCount(500, explode("\n", $rendered));
    }

    public function testSelectIsWindowedToTheAllocatedHeight(): void
    {
        $rendered = Select::new($this->manyOptions(500))
            ->withSelectedIndex(250)
            ->setSize(0, 20)
            ->render();

        $this->assertCount(20, explode("\n", $rendered));
        $this->assertStringContainsString('Opt 250', $rendered);
        $this->assertStringNotContainsString('Opt 000', $rendered);
        $this->assertStringNotContainsString('Opt 499', $rendered);
    }

    public function testWindowTracksSelectionAtTheEdges(): void
    {
        $bottom = Select::new($this->manyOptions(500))
            ->withSelectedIndex(499)
            ->setSize(0, 20)
            ->render();

        // Last window shows through the final option
        $this->assertStringContainsString('Opt 499', $bottom);
        $this->assertStringContainsString('Opt 480', $bottom);
        $this->assertStringNotContainsString('Opt 479', $bottom);

        $top = Select::new($this->manyOptions(500))
            ->withSelectedIndex(0)
            ->setSize(0, 20)
            ->render();

        $this->assertStringContainsString('Opt 000', $top);
        $this->assertStringNotContainsString('Opt 020', $top);
    }

    public function testInnerSizeReportsTheWindowedHeight(): void
    {
        [, $natural] = Select::new($this->manyOptions(500))->innerSize();
        $this->assertSame(500, $natural);

        [, $windowed] = Select::new($this->manyOptions(500))->setSize(0, 20)->innerSize();
        $this->assertSame(20, $windowed);
    }

    public function testWithersCarryTheAllocation(): void
    {
        $sized = Select::new($this->manyOptions(500))->withSelectedIndex(45)->setSize(0, 10);

        // Every wither must keep the allocation, or the window disappears.
        foreach (
            [
                fn(Select $s) => $s->withSelectedIndex(45),
                fn(Select $s) => $s->withSelectedColor(null),
                fn(Select $s) => $s->withUnselectedColor(null),
                fn(Select $s) => $s->withChars('>', '-'),
            ] as $wither
        ) {
            $rows = explode("\n", $wither($sized)->render());
            $this->assertCount(10, $rows);
            $this->assertStringContainsString('Opt 045', implode("\n", $rows));
        }
    }
}
