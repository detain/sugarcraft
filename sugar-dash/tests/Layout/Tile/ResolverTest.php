<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout\Tile;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\Tile\Constraint;
use SugarCraft\Dash\Layout\Tile\ConstraintKind;
use SugarCraft\Dash\Layout\Tile\Dimension;
use SugarCraft\Dash\Layout\Tile\Resolver;
use SugarCraft\Dash\Layout\Tile\SizeHint;
use SugarCraft\Dash\Layout\Tile\SizeHinter;

/**
 * @implements SizeHinter<int,int>
 */
final class SimpleSizeHinter implements SizeHinter
{
    public function __construct(
        private readonly int $minWidth,
        private readonly int $minHeight,
        private readonly int $desiredWidth,
        private readonly int $desiredHeight,
    ) {}

    public function sizeHint(int $availWidth, int $availHeight): SizeHint
    {
        return new SizeHint(
            min: new Dimension($this->minWidth, $this->minHeight),
            desired: new Dimension($this->desiredWidth, $this->desiredHeight),
        );
    }
}

final class ResolverTest extends TestCase
{
    public function testResolveLinearWithAllFixedConstraints(): void
    {
        $constraints = [
            Constraint::fixed(10),
            Constraint::fixed(20),
            Constraint::fixed(30),
        ];

        $sizes = Resolver::resolveLinear(60, $constraints, 0);

        $this->assertSame([10, 20, 30], $sizes);
    }

    public function testResolveLinearWithAllFixedConstraintsAndGaps(): void
    {
        $constraints = [
            Constraint::fixed(10),
            Constraint::fixed(20),
            Constraint::fixed(30),
        ];

        // 3 tiles = 2 gaps of 5 each = 10 total gap
        // 100 - 10 = 90 for tiles
        $sizes = Resolver::resolveLinear(100, $constraints, 5);

        $this->assertSame([10, 20, 30], $sizes);
    }

    public function testResolveLinearWithAllFlexConstraints(): void
    {
        $constraints = [
            Constraint::flex(1.0),
            Constraint::flex(1.0),
            Constraint::flex(1.0),
        ];

        $sizes = Resolver::resolveLinear(90, $constraints, 0);

        // All flex with equal weight - should sum to 90 with no zero drift
        $this->assertSame(90, array_sum($sizes));
        $this->assertContainsOnly('int', $sizes);
    }

    public function testResolveLinearWithFlexNoZeroDrift(): void
    {
        $constraints = [
            Constraint::flex(1.0),
            Constraint::flex(1.0),
        ];

        $sizes = Resolver::resolveLinear(100, $constraints, 0);

        // Flex should not drift to zero
        $this->assertGreaterThan(0, $sizes[0]);
        $this->assertGreaterThan(0, $sizes[1]);
    }

    public function testResolveLinearWithMixedFixedAndFlex(): void
    {
        $constraints = [
            Constraint::fixed(10),
            Constraint::flex(1.0),
            Constraint::fixed(20),
        ];

        $sizes = Resolver::resolveLinear(100, $constraints, 0);

        // Fixed takes 30, flex gets remaining 70
        $this->assertSame(10, $sizes[0]);
        $this->assertSame(70, $sizes[1]);
        $this->assertSame(20, $sizes[2]);
    }

    public function testResolveLinearWithWeightedFlex(): void
    {
        $constraints = [
            Constraint::flex(1.0),
            Constraint::flex(3.0),
        ];

        $sizes = Resolver::resolveLinear(100, $constraints, 0);

        // 1:3 ratio = 25:75 approximately
        $this->assertEquals(25, $sizes[0]);
        $this->assertEquals(75, $sizes[1]);
    }

    public function testResolveLinearWithOptionalChildRemoval(): void
    {
        $constraints = [
            Constraint::fixed(10),
            Constraint::flex(1.0)->withOptional(true)->withMinSize(80),
            Constraint::fixed(20),
        ];

        $sizes = Resolver::resolveLinear(100, $constraints, 0);

        // Optional flex child has minSize 80 but only gets ~70 (100 - 30 = 70),
        // so it should be removed because sizes[1] < minSize
        $this->assertSame(10, $sizes[0]);
        $this->assertSame(0, $sizes[1]); // Removed - only got 70 but needs 80
        $this->assertSame(20, $sizes[2]);
    }

    public function testResolveLinearWithMinSizeFit(): void
    {
        $hinters = [
            null,
            new SimpleSizeHinter(50, 50, 30, 30), // min=50, desired=30 (less than min!)
            null,
        ];

        $constraints = [
            Constraint::fixed(10),
            Constraint::fit()->withMinSizeFit(true),
            Constraint::fixed(10),
        ];

        $sizes = Resolver::resolveLinear(100, $constraints, 0, $hinters, true);

        // minSizeFit queries the hinter's Min size (50) and uses it as effective minSize
        // Desired is 30 but minSize is clamped to 50
        $this->assertSame(10, $sizes[0]);
        $this->assertSame(50, $sizes[1]); // Clamped to minSize from hinter
        $this->assertSame(10, $sizes[2]);
    }

    public function testResolveLinearWithGaps(): void
    {
        $constraints = [
            Constraint::flex(1.0),
            Constraint::flex(1.0),
        ];

        $sizes = Resolver::resolveLinear(100, $constraints, 10);

        // 2 tiles = 1 gap of 10, so tiles share 90 space 45:45
        $this->assertSame(45, $sizes[0]);
        $this->assertSame(45, $sizes[1]);
    }

    public function testResolveLinearWithEmptyConstraints(): void
    {
        $sizes = Resolver::resolveLinear(100, [], 0);

        $this->assertSame([], $sizes);
    }

    public function testResolveLinearWithClampedFlex(): void
    {
        $constraints = [
            Constraint::flex(1.0)->withMaxSize(30),
            Constraint::flex(1.0),
        ];

        $sizes = Resolver::resolveLinear(100, $constraints, 0);

        // First flex clamped to 30, second gets remaining 70
        $this->assertSame(30, $sizes[0]);
        $this->assertSame(70, $sizes[1]);
    }

    public function testResolveLinearWithMinSizeOnFlex(): void
    {
        $constraints = [
            Constraint::flex(1.0)->withMinSize(40),
            Constraint::flex(1.0),
        ];

        $sizes = Resolver::resolveLinear(50, $constraints, 0);

        // Two flex 1:1 split 50 = 25:25, but first has minSize 40 so gets clamped to 40
        $this->assertSame(40, $sizes[0]); // Clamped to minSize
        $this->assertSame(10, $sizes[1]); // Remaining
    }

    public function testResolveLinearHorizontalVsVertical(): void
    {
        $hinters = [
            new SimpleSizeHinter(0, 0, 50, 100),
            new SimpleSizeHinter(0, 0, 50, 100),
        ];

        $constraints = [
            Constraint::fit(),
            Constraint::fit(),
        ];

        // Horizontal layout uses Desired.Width
        $sizesH = Resolver::resolveLinear(100, $constraints, 0, $hinters, true);
        $this->assertSame(50, $sizesH[0]);
        $this->assertSame(50, $sizesH[1]);

        // Vertical layout uses Desired.Height
        $sizesV = Resolver::resolveLinear(200, $constraints, 0, $hinters, false);
        $this->assertSame(100, $sizesV[0]);
        $this->assertSame(100, $sizesV[1]);
    }
}
