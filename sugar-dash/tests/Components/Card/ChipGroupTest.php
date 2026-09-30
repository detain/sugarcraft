<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\Chip;
use SugarCraft\Dash\Components\Card\ChipGroup;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\SizedItem;
use PHPUnit\Framework\TestCase;

final class ChipGroupTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testChipGroupImplementsSizer(): void
    {
        $group = ChipGroup::fromLabels(['A', 'B']);
        $this->assertInstanceOf(Sizer::class, $group);
    }

    public function testChipGroupImplementsItem(): void
    {
        $group = ChipGroup::fromLabels(['A', 'B']);
        $this->assertInstanceOf(Item::class, $group);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $group = ChipGroup::fromLabels(['Active', 'Pending']);
        $rendered = $group->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsAllLabels(): void
    {
        $group = ChipGroup::fromLabels(['Alpha', 'Beta', 'Gamma']);
        $rendered = $group->render();

        $this->assertStringContainsString('Alpha', $rendered);
        $this->assertStringContainsString('Beta', $rendered);
        $this->assertStringContainsString('Gamma', $rendered);
    }

    public function testEmptyGroupRendersEmpty(): void
    {
        $group = new ChipGroup();
        $rendered = $group->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testFromLabels(): void
    {
        $group = ChipGroup::fromLabels(['One', 'Two']);
        $rendered = $group->render();

        $this->assertStringContainsString('One', $rendered);
        $this->assertStringContainsString('Two', $rendered);
    }

    public function testPrimaryFactory(): void
    {
        $group = ChipGroup::primary(['Primary1', 'Primary2']);
        $rendered = $group->render();

        $this->assertStringContainsString('Primary1', $rendered);
        $this->assertStringContainsString('Primary2', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testSuccessFactory(): void
    {
        $group = ChipGroup::success(['Done', 'Complete']);
        $rendered = $group->render();

        $this->assertStringContainsString('Done', $rendered);
        $this->assertStringContainsString('Complete', $rendered);
    }

    public function testWarningFactory(): void
    {
        $group = ChipGroup::warning(['Caution', 'Warning']);
        $rendered = $group->render();

        $this->assertStringContainsString('Caution', $rendered);
        $this->assertStringContainsString('Warning', $rendered);
    }

    public function testDangerFactory(): void
    {
        $group = ChipGroup::danger(['Error', 'Failed']);
        $rendered = $group->render();

        $this->assertStringContainsString('Error', $rendered);
        $this->assertStringContainsString('Failed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Gap handling
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultGap(): void
    {
        $group = ChipGroup::fromLabels(['A', 'B']);
        $rendered = $group->render();

        // Chips should be adjacent with single space gap
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testCustomGap(): void
    {
        $group1 = ChipGroup::fromLabels(['A', 'B'])->withGap(3);
        $group2 = ChipGroup::fromLabels(['A', 'B'])->withGap(1);

        $rendered1 = $group1->render();
        $rendered2 = $group2->render();

        // More gap = wider output
        $this->assertGreaterThan(
            mb_strlen($rendered2, 'UTF-8'),
            mb_strlen($rendered1, 'UTF-8')
        );
    }

    public function testZeroGap(): void
    {
        $group = ChipGroup::fromLabels(['A', 'B'])->withGap(0);
        $rendered = $group->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = ChipGroup::fromLabels(['Test']);
        $resized = $original->setSize(50, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $group = ChipGroup::fromLabels(['Hi', 'Bye']);
        [$w, $h] = $group->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h); // Chips don't wrap by default
    }

    public function testEmptyGroupHasZeroSize(): void
    {
        $group = new ChipGroup();
        [$w, $h] = $group->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithChipsReturnsNewInstance(): void
    {
        $original = ChipGroup::fromLabels(['Original']);
        $updated = $original->withChips([
            Chip::new('Updated'),
        ]);

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
        $this->assertStringNotContainsString('Updated', $original->render());
    }

    public function testWithAppendedReturnsNewInstance(): void
    {
        $original = ChipGroup::fromLabels(['First']);
        $updated = $original->withAppended(Chip::new('Second'));

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('First', $updated->render());
        $this->assertStringContainsString('Second', $updated->render());
    }

    public function testWithGapReturnsNewInstance(): void
    {
        $original = ChipGroup::fromLabels(['A', 'B']);
        $updated = $original->withGap(5);

        $this->assertNotSame($original, $updated);
    }

    public function testWithWrapReturnsNewInstance(): void
    {
        $original = ChipGroup::fromLabels(['A', 'B']);
        $updated = $original->withWrap(true);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithChips(): void
    {
        $original = ChipGroup::fromLabels(['Original']);
        $original->withChips([Chip::new('Changed')]);
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongLabels(): void
    {
        $group = ChipGroup::fromLabels([
            str_repeat('A', 50),
            str_repeat('B', 50),
        ]);
        $rendered = $group->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testUnicodeLabels(): void
    {
        $group = ChipGroup::fromLabels(['日本語', '中文', '한국어']);
        $rendered = $group->render();

        $this->assertStringContainsString('日本語', $rendered);
        $this->assertStringContainsString('中文', $rendered);
        $this->assertStringContainsString('한국어', $rendered);
    }

    public function testSingleChip(): void
    {
        $group = ChipGroup::fromLabels(['Only']);
        $rendered = $group->render();

        $this->assertStringContainsString('Only', $rendered);
    }

    public function testNonChipElementIsRefusedAtTheDoor(): void
    {
        // Re-audit at 7860dbba9 (finding #2 family): the wrap/measure paths
        // called getInnerSize() on every element trusting the list<Chip>
        // docblock — any foreign element passed the raw-array door and fataled
        // at render time. The ctor now parses, naming the offending index.
        $intruder = new class implements SizedItem {
            public function render(): string
            {
                return 'xx';
            }

            public function setSize(int $width, int $height): Sizer
            {
                return $this;
            }

            public function getInnerSize(): array
            {
                return [2, 1];
            }
        };
        $this->assertFalse($intruder instanceof Chip, 'the double must not be a Chip');

        try {
            new ChipGroup([Chip::new('ok'), $intruder]);
            $this->fail('the ctor accepted a non-Chip element');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('to be a Chip', $e->getMessage());
            $this->assertStringContainsString('index 1', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('to be a Chip, string given at index 0');
        ChipGroup::fromLabels(['A'])->withChips(['B']);
    }

    public function testGenuineChipListPassesTheElementDoor(): void
    {
        $chips = [Chip::new('A'), Chip::new('B')];
        $group = new ChipGroup($chips);

        $this->assertStringContainsString('A', $group->render());
        $this->assertStringContainsString('B', $group->render());

        [$w, $h] = $group->getInnerSize();
        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }
}
