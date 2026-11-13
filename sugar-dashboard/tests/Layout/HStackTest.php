<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use SugarCraft\Dashboard\Layout\HStack;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Components\Card\Text;
use SugarCraft\Dashboard\Components\Card\Badge;
use SugarCraft\Dashboard\Layout\Spacer;
use SugarCraft\Dashboard\Layout\HAlign;
use PHPUnit\Framework\TestCase;

final class HStackTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testHStackImplementsSizer(): void
    {
        $stack = HStack::new();
        $this->assertInstanceOf(Sizer::class, $stack);
    }

    public function testHStackImplementsItem(): void
    {
        $stack = HStack::new();
        $this->assertInstanceOf(Item::class, $stack);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyStack(): void
    {
        $stack = HStack::new();
        $rendered = $stack->render();

        $this->assertSame('', $rendered);
    }

    public function testRenderSingleItem(): void
    {
        $stack = HStack::new(new Text('Hello'));
        $rendered = $stack->render();

        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testRenderMultipleItemsSideBySide(): void
    {
        $stack = HStack::new(
            new Text('A'),
            new Text('B'),
            new Text('C')
        );
        $rendered = $stack->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
        $this->assertStringContainsString('C', $rendered);

        // Items should be on same line (no newlines between)
        $lines = explode("\n", $rendered);
        $this->assertCount(1, $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Spacing
    // ═══════════════════════════════════════════════════════════════

    public function testSpacedFactory(): void
    {
        $stack = HStack::spaced(3,
            new Text('A'),
            new Text('B')
        );
        $rendered = $stack->render();

        // A and B should be separated
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testZeroSpacingItemsTouching(): void
    {
        $stack = HStack::new(new Text('A'), new Text('B'));
        $rendered = $stack->render();

        // A and B should be adjacent without space
        $this->assertStringNotContainsString(' A', $rendered);
        $this->assertStringNotContainsString('B ', $rendered);
    }

    public function testWithSpacingAddsSpace(): void
    {
        $stack = HStack::new(new Text('A'), new Text('B'))->withSpacing(3);
        $rendered = $stack->render();

        // Should have spaces between A and B
        $this->assertStringContainsString('A   B', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Alignment
    // ═══════════════════════════════════════════════════════════════

    public function testCenteredFactory(): void
    {
        $stack = HStack::centered(new Text('Center'));
        $rendered = $stack->render();

        $this->assertStringContainsString('Center', $rendered);
    }

    public function testWithAlignmentReturnsNewInstance(): void
    {
        $original = HStack::new(new Text('A'));
        $updated = $original->withAlignment(HAlign::Right);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = HStack::new(new Text('Test'));
        $resized = $original->setSize(50, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = HStack::new(new Text('A'));
        $updated = $original->withItems([new Text('B')]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithAppendedAddsItem(): void
    {
        $original = HStack::new(new Text('A'));
        $updated = $original->withAppended(new Text('B'));
        $rendered = $updated->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testWithPrependedAddsItem(): void
    {
        $original = HStack::new(new Text('B'));
        $updated = $original->withPrepended(new Text('A'));
        $rendered = $updated->render();

        // A should come before B
        $posA = strpos($rendered, 'A');
        $posB = strpos($rendered, 'B');
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posB, $posA);
    }

    public function testWithSpacingReturnsNewInstance(): void
    {
        $original = HStack::new(new Text('A'));
        $updated = $original->withSpacing(3);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeEmptyStack(): void
    {
        $stack = HStack::new();
        [$w, $h] = $stack->innerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeSingleItem(): void
    {
        $stack = HStack::new(new Text('Hello'));
        [$w, $h] = $stack->innerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeMultipleItems(): void
    {
        $stack = HStack::new(new Text('A'), new Text('B'));
        [$w, $h] = $stack->innerSize();

        $this->assertGreaterThan(1, $w); // Combined width
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithSpacing(): void
    {
        $stackNoSpacing = HStack::new(new Text('A'), new Text('B'));
        $stackWithSpacing = HStack::new(new Text('A'), new Text('B'))->withSpacing(3);

        [$wNoSpacing, ] = $stackNoSpacing->innerSize();
        [$wWithSpacing, ] = $stackWithSpacing->innerSize();

        $this->assertGreaterThan($wNoSpacing, $wWithSpacing);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testManyItems(): void
    {
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = new Text("($i)");
        }
        $stack = HStack::new(...$items);
        $rendered = $stack->render();

        $this->assertStringContainsString('(0)', $rendered);
        $this->assertStringContainsString('(4)', $rendered);
    }

    public function testMixedContent(): void
    {
        $stack = HStack::new(
            Badge::new('New'),
            new Text('Label'),
            Spacer::new(1, 1)->withFillChar('-')
        );
        $rendered = $stack->render();

        $this->assertStringContainsString('New', $rendered);
        $this->assertStringContainsString('Label', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multi-line children stitch side-by-side (finding sugar-dashboard-020)
    // ═══════════════════════════════════════════════════════════════

    private function rawItem(string $bytes): Item
    {
        return new class($bytes) implements Item {
            public function __construct(private readonly string $bytes)
            {
            }

            public function render(): string
            {
                return $this->bytes;
            }
        };
    }

    public function testMultiLineChildrenComposeRowWiseSideBySide(): void
    {
        $stack = new HStack(items: [$this->rawItem("ab\ncd"), $this->rawItem('xyz')], spacing: 0, alignment: HAlign::Left);

        // 'ab','cd' column is 2 wide; the tall right column keeps its rows
        // lane-locked — the old blob-concat produced ['abxy\nz'... wrecked].
        $this->assertSame("abxyz\ncd", $stack->render());
    }

    public function testSeamSpacingNeverCorruptsThePreviousLine(): void
    {
        $stack = new HStack(items: [$this->rawItem("a\nb"), $this->rawItem('c')], spacing: 1, alignment: HAlign::Left);

        // Row 1 has no final-column content: no phantom trailing seam.
        $this->assertSame("a c\nb", $stack->render());
    }

    public function testShorterBlockRowsArePaddedWithinTheirColumn(): void
    {
        $stack = new HStack(items: [$this->rawItem("wide!\nq"), $this->rawItem("r\ns\nt")], spacing: 0, alignment: HAlign::Left);

        // Column 0 pads rows to width 5; missing rows of the shorter child
        // render as its padded blanks so the following column stays in lane.
        $this->assertSame("wide!r\nq    s\n     t", $stack->render());
    }

    public function testAlignmentPadsEveryRowNotJustTheFirst(): void
    {
        $stack = new HStack(items: [$this->rawItem("ab\ncd")], spacing: 0, alignment: HAlign::Right);
        $stack = $stack->setSize(6, 0);

        // two children columns total 2 wide inside 6 → 4 leading spaces on BOTH rows
        $this->assertSame("    ab\n    cd", $stack->render());
    }
}
