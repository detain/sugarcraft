<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Badge;

final class BadgeTest extends TestCase
{
    public function testNewCreatesBadge(): void
    {
        $badge = Badge::new('Label');
        $this->assertNotNull($badge);
    }

    public function testSuccessCreatesSuccessBadge(): void
    {
        $badge = Badge::success('Done');
        $this->assertNotNull($badge);
        $this->assertStringContainsString('✓', $badge->render());
    }

    public function testWarningCreatesWarningBadge(): void
    {
        $badge = Badge::warning('Caution');
        $this->assertNotNull($badge);
    }

    public function testErrorCreatesErrorBadge(): void
    {
        $badge = Badge::error('Error');
        $this->assertNotNull($badge);
        $this->assertStringContainsString('✗', $badge->render());
    }

    public function testInfoCreatesInfoBadge(): void
    {
        $badge = Badge::info('Info');
        $this->assertNotNull($badge);
        $this->assertStringContainsString('ℹ', $badge->render());
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $badge = Badge::new('Label');
        $this->assertNotSame('', $badge->render());
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $badge = Badge::new('Label');
        [$width, $height] = $badge->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithStyleReturnsNewInstance(): void
    {
        $badge = Badge::new('Label');
        $newBadge = $badge->withStyle(Badge::StyleOutline);
        $this->assertNotSame($badge, $newBadge);
    }

    public function testWithSizeReturnsNewInstance(): void
    {
        $badge = Badge::new('Label');
        $newBadge = $badge->withSize(Badge::SizeSm);
        $this->assertNotSame($badge, $newBadge);
    }

    public function testWithIconReturnsNewInstance(): void
    {
        $badge = Badge::new('Label');
        $newBadge = $badge->withIcon('★');
        $this->assertNotSame($badge, $newBadge);
    }
}
