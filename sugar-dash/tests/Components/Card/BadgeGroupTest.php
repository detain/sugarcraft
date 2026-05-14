<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\Badge;
use SugarCraft\Dash\Components\Card\BadgeGroup;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use PHPUnit\Framework\TestCase;

final class BadgeGroupTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testBadgeGroupImplementsSizer(): void
    {
        $group = BadgeGroup::fromLabels(['A', 'B']);
        $this->assertInstanceOf(Sizer::class, $group);
    }

    public function testBadgeGroupImplementsItem(): void
    {
        $group = BadgeGroup::fromLabels(['A', 'B']);
        $this->assertInstanceOf(Item::class, $group);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $group = BadgeGroup::fromLabels(['Active', 'Pending']);
        $rendered = $group->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsAllLabels(): void
    {
        $group = BadgeGroup::fromLabels(['Alpha', 'Beta', 'Gamma']);
        $rendered = $group->render();

        $this->assertStringContainsString('Alpha', $rendered);
        $this->assertStringContainsString('Beta', $rendered);
        $this->assertStringContainsString('Gamma', $rendered);
    }

    public function testEmptyGroupRendersEmpty(): void
    {
        $group = new BadgeGroup();
        $rendered = $group->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testFromLabels(): void
    {
        $group = BadgeGroup::fromLabels(['One', 'Two']);
        $rendered = $group->render();

        $this->assertStringContainsString('One', $rendered);
        $this->assertStringContainsString('Two', $rendered);
    }

    public function testSuccessFactory(): void
    {
        $group = BadgeGroup::success(['Done', 'Complete']);
        $rendered = $group->render();

        $this->assertStringContainsString('Done', $rendered);
        $this->assertStringContainsString('Complete', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testWarningFactory(): void
    {
        $group = BadgeGroup::warning(['Caution', 'Warning']);
        $rendered = $group->render();

        $this->assertStringContainsString('Caution', $rendered);
        $this->assertStringContainsString('Warning', $rendered);
    }

    public function testDangerFactory(): void
    {
        $group = BadgeGroup::danger(['Error', 'Failed']);
        $rendered = $group->render();

        $this->assertStringContainsString('Error', $rendered);
        $this->assertStringContainsString('Failed', $rendered);
    }

    public function testInfoFactory(): void
    {
        $group = BadgeGroup::info(['Info', 'Notice']);
        $rendered = $group->render();

        $this->assertStringContainsString('Info', $rendered);
        $this->assertStringContainsString('Notice', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Gap handling
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultGap(): void
    {
        $group = BadgeGroup::fromLabels(['A', 'B']);
        $rendered = $group->render();

        // Badges should be adjacent with single space gap
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testCustomGap(): void
    {
        $group1 = BadgeGroup::fromLabels(['A', 'B'])->withGap(3);
        $group2 = BadgeGroup::fromLabels(['A', 'B'])->withGap(1);

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
        $group = BadgeGroup::fromLabels(['A', 'B'])->withGap(0);
        $rendered = $group->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = BadgeGroup::fromLabels(['Test']);
        $resized = $original->setSize(50, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $group = BadgeGroup::fromLabels(['Hi', 'Bye']);
        [$w, $h] = $group->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThanOrEqual(1, $h);
    }

    public function testEmptyGroupHasZeroSize(): void
    {
        $group = new BadgeGroup();
        [$w, $h] = $group->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithBadgesReturnsNewInstance(): void
    {
        $original = BadgeGroup::fromLabels(['Original']);
        $updated = $original->withBadges([
            Badge::new('Updated'),
        ]);

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
        $this->assertStringNotContainsString('Updated', $original->render());
    }

    public function testWithAppendedReturnsNewInstance(): void
    {
        $original = BadgeGroup::fromLabels(['First']);
        $updated = $original->withAppended(Badge::new('Second'));

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('First', $updated->render());
        $this->assertStringContainsString('Second', $updated->render());
    }

    public function testWithGapReturnsNewInstance(): void
    {
        $original = BadgeGroup::fromLabels(['A', 'B']);
        $updated = $original->withGap(5);

        $this->assertNotSame($original, $updated);
    }

    public function testWithWrapReturnsNewInstance(): void
    {
        $original = BadgeGroup::fromLabels(['A', 'B']);
        $updated = $original->withWrap(true);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithBadges(): void
    {
        $original = BadgeGroup::fromLabels(['Original']);
        $original->withBadges([Badge::new('Changed')]);
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongLabels(): void
    {
        $group = BadgeGroup::fromLabels([
            str_repeat('A', 50),
            str_repeat('B', 50),
        ]);
        $rendered = $group->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testUnicodeLabels(): void
    {
        $group = BadgeGroup::fromLabels(['日本語', '中文', '한국어']);
        $rendered = $group->render();

        $this->assertStringContainsString('日本語', $rendered);
        $this->assertStringContainsString('中文', $rendered);
        $this->assertStringContainsString('한국어', $rendered);
    }

    public function testSingleBadge(): void
    {
        $group = BadgeGroup::fromLabels(['Only']);
        $rendered = $group->render();

        $this->assertStringContainsString('Only', $rendered);
    }
}
