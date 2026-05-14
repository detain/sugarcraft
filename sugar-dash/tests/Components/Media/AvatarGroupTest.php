<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Media;

use SugarCraft\Dash\Components\Media\AvatarGroup;
use SugarCraft\Dash\Components\Media\Avatar;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class AvatarGroupTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testAvatarGroupImplementsSizer(): void
    {
        $group = AvatarGroup::fromNames(['Alice', 'Bob']);
        $this->assertInstanceOf(Sizer::class, $group);
    }

    public function testAvatarGroupImplementsItem(): void
    {
        $group = AvatarGroup::fromNames(['Alice', 'Bob']);
        $this->assertInstanceOf(Item::class, $group);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $group = AvatarGroup::fromNames(['Alice', 'Bob']);
        $rendered = $group->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsAvatarInitials(): void
    {
        $group = AvatarGroup::fromNames(['Alice', 'Bob']);
        $rendered = $group->render();

        // Alice -> " Al  " (padded to 5 chars), Bob -> " Bo  " (padded)
        $this->assertStringContainsString('Al', $rendered);
        $this->assertStringContainsString('Bo', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset factories
    // ═══════════════════════════════════════════════════════════════

    public function testFromNamesFactory(): void
    {
        $group = AvatarGroup::fromNames(['Alice', 'Bob', 'Charlie']);
        $rendered = $group->render();

        // Avatars are padded: " Al  ", " Bo  ", " Ch  "
        $this->assertStringContainsString('Al', $rendered);
        $this->assertStringContainsString('Bo', $rendered);
        $this->assertStringContainsString('Ch', $rendered);
    }

    public function testCompactFactoryLimitsDisplay(): void
    {
        $group = AvatarGroup::compact(['A', 'B', 'C', 'D', 'E'], 3);
        $rendered = $group->render();

        // Should show overflow indicator since we have 5 names but max 3
        $this->assertStringContainsString('+', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Empty group
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyGroupReturnsEmpty(): void
    {
        $group = AvatarGroup::fromNames([]);
        $rendered = $group->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Overflow indicator
    // ═══════════════════════════════════════════════════════════════

    public function testOverflowIndicatorShownWhenExceedsMax(): void
    {
        $group = AvatarGroup::compact(['A', 'B', 'C', 'D', 'E'], 3);
        $rendered = $group->render();

        // Should contain +2 for 5 items with max 3
        $this->assertStringContainsString('+', $rendered);
    }

    public function testNoOverflowWhenWithinLimit(): void
    {
        $group = AvatarGroup::compact(['A', 'B', 'C'], 3);
        $rendered = $group->render();

        // Should not contain + since 3 items = 3 max
        $this->assertStringNotContainsString('+', $rendered);
    }

    public function testOverflowUsesCorrectColors(): void
    {
        $group = AvatarGroup::compact(['A', 'B', 'C', 'D', 'E'], 3)
            ->withOverflowColors(Color::hex('#EF4444'), Color::hex('#FFFFFF'));
        $rendered = $group->render();

        // Should have ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Overlap handling
    // ═══════════════════════════════════════════════════════════════

    public function testOverlapReducesTotalWidth(): void
    {
        $groupNoOverlap = AvatarGroup::fromNames(['A', 'B']);
        $groupWithOverlap = AvatarGroup::fromNames(['A', 'B'])->withOverlap(3);

        [$wNoOverlap, ] = $groupNoOverlap->getInnerSize();
        [$wWithOverlap, ] = $groupWithOverlap->getInnerSize();

        $this->assertLessThan($wNoOverlap, $wWithOverlap);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = AvatarGroup::fromNames(['Alice', 'Bob']);
        $resized = $original->setSize(50, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthCalculationAccountsForOverlap(): void
    {
        $group = AvatarGroup::fromNames(['A', 'B', 'C']);
        [$w, ] = $group->getInnerSize();

        // Each avatar is 5 wide, 3 overlap means 5 + 5 - 2 + 5 - 2 = 11
        $this->assertSame(11, $w);
    }

    public function testWidthCalculationWithOverflow(): void
    {
        $group = AvatarGroup::compact(['A', 'B', 'C', 'D', 'E'], 3);
        [$w, ] = $group->getInnerSize();

        // 3 avatars (5-2 each = 3*3 + 2 = 11) + overflow indicator (5) + overlap (2) = 18
        $this->assertGreaterThan(15, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithAvatarsReturnsNewInstance(): void
    {
        $original = AvatarGroup::fromNames(['Alice']);
        $updated = $original->withAvatars([
            Avatar::fromName('Bob'),
            Avatar::fromName('Charlie'),
        ]);

        $this->assertNotSame($original, $updated);
        $rendered = $updated->render();
        $this->assertStringContainsString('Bo', $rendered);
        $this->assertStringContainsString('Ch', $rendered);
    }

    public function testWithAppendedAddsAvatar(): void
    {
        $original = AvatarGroup::fromNames(['Alice']);
        $updated = $original->withAppended(Avatar::fromName('Bob'));

        $this->assertNotSame($original, $updated);
        $rendered = $updated->render();
        $this->assertStringContainsString('Al', $rendered);
        $this->assertStringContainsString('Bo', $rendered);
    }

    public function testWithOverlapReturnsNewInstance(): void
    {
        $original = AvatarGroup::fromNames(['A', 'B']);
        $updated = $original->withOverlap(5);

        $this->assertNotSame($original, $updated);
    }

    public function testWithMaxDisplayReturnsNewInstance(): void
    {
        $original = AvatarGroup::fromNames(['A', 'B', 'C']);
        $updated = $original->withMaxDisplay(2);

        $this->assertNotSame($original, $updated);
    }

    public function testWithOverflowIndicatorReturnsNewInstance(): void
    {
        $original = AvatarGroup::fromNames(['A', 'B', 'C', 'D', 'E'])->withMaxDisplay(3);
        $updated = $original->withOverflowIndicator('...');

        $this->assertNotSame($original, $updated);
    }

    public function testWithOverflowColorsReturnsNewInstance(): void
    {
        $original = AvatarGroup::fromNames(['A', 'B', 'C']);
        $updated = $original->withOverflowColors(Color::ansi(1), Color::ansi(7));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithAppended(): void
    {
        $original = AvatarGroup::fromNames(['Alice']);
        $original->withAppended(Avatar::fromName('Bob'));
        $rendered = $original->render();

        $this->assertStringContainsString('Al', $rendered);
        $this->assertStringNotContainsString('Bo', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $group = AvatarGroup::fromNames(['Alice', 'Bob']);
        [$w, $h] = $group->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeEmptyGroup(): void
    {
        $group = AvatarGroup::fromNames([]);
        [$w, $h] = $group->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testHeightMatchesAvatarSize(): void
    {
        $group = AvatarGroup::fromNames(['A', 'B']);
        [, $h] = $group->getInnerSize();

        // Medium avatar is 5 pixels tall
        $this->assertSame(5, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSingleAvatar(): void
    {
        $group = AvatarGroup::fromNames(['Alice']);
        $rendered = $group->render();

        $this->assertStringContainsString('Al', $rendered);
    }

    public function testManyAvatars(): void
    {
        $names = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $group = AvatarGroup::fromNames($names);
        $rendered = $group->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('H', $rendered);
    }

    public function testUnicodeNames(): void
    {
        $group = AvatarGroup::fromNames(['田中', '鈴木']);
        $rendered = $group->render();

        $this->assertNotSame('', $rendered);
    }

    public function testOverflowIndicatorCustomText(): void
    {
        $group = AvatarGroup::compact(['A', 'B', 'C', 'D', 'E'], 3)
            ->withOverflowIndicator('more');
        $rendered = $group->render();

        $this->assertStringContainsString('more', $rendered);
    }

    public function testZeroOverlap(): void
    {
        $group = AvatarGroup::fromNames(['A', 'B'])->withOverlap(0);
        [$w, ] = $group->getInnerSize();

        // Two 5-wide avatars with 0 overlap = 10
        $this->assertSame(10, $w);
    }
}
