<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Toast;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Toast\NoticePosition;

final class NoticePositionTest extends TestCase
{
    public function testAnchorPositionExists(): void
    {
        $position = NoticePosition::Anchor;
        $this->assertSame('anchor', $position->value);
    }

    public function testIsAnchor(): void
    {
        $anchor = NoticePosition::Anchor;
        $topLeft = NoticePosition::TopLeft;

        $this->assertTrue($anchor->isAnchor());
        $this->assertFalse($topLeft->isAnchor());
    }

    public function testOtherPositionsAreNotAnchor(): void
    {
        $positions = [
            NoticePosition::TopLeft,
            NoticePosition::TopCenter,
            NoticePosition::TopRight,
            NoticePosition::BottomLeft,
            NoticePosition::BottomCenter,
            NoticePosition::BottomRight,
            NoticePosition::CenterLeft,
            NoticePosition::CenterRight,
            NoticePosition::Center,
        ];

        foreach ($positions as $position) {
            $this->assertFalse($position->isAnchor(), "{$position->name} should not be anchor");
        }
    }
}
