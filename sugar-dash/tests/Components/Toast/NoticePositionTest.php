<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Toast;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Toast\NoticePosition;
use SugarCraft\Toast\Position as ToastPosition;

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

    // ═══════════════════════════════════════════════════════════════
    // sugar-toast Position mapping
    // ═══════════════════════════════════════════════════════════════

    /**
     * @return iterable<string, array{0: NoticePosition, 1: ToastPosition}>
     */
    public static function provideToastPositionPairs(): iterable
    {
        yield 'top-left'      => [NoticePosition::TopLeft, ToastPosition::TopLeft];
        yield 'top-center'    => [NoticePosition::TopCenter, ToastPosition::TopCenter];
        yield 'top-right'     => [NoticePosition::TopRight, ToastPosition::TopRight];
        yield 'bottom-left'   => [NoticePosition::BottomLeft, ToastPosition::BottomLeft];
        yield 'bottom-center' => [NoticePosition::BottomCenter, ToastPosition::BottomCenter];
        yield 'bottom-right'  => [NoticePosition::BottomRight, ToastPosition::BottomRight];
        yield 'center-left'   => [NoticePosition::CenterLeft, ToastPosition::MiddleLeft];
        yield 'center-right'  => [NoticePosition::CenterRight, ToastPosition::MiddleRight];
        yield 'center'        => [NoticePosition::Center, ToastPosition::MiddleCenter];
    }

    /**
     * @dataProvider provideToastPositionPairs
     */
    public function testToToastPositionMapping(NoticePosition $position, ToastPosition $expected): void
    {
        $this->assertSame($expected, $position->toToastPosition());
    }

    public function testAnchorFallsBackToTopRight(): void
    {
        // Anchor (element-relative) has no sugar-toast equivalent.
        $this->assertSame(ToastPosition::TopRight, NoticePosition::Anchor->toToastPosition());
    }

    public function testEveryPositionMapsToAToastPosition(): void
    {
        foreach (NoticePosition::cases() as $position) {
            $this->assertInstanceOf(ToastPosition::class, $position->toToastPosition());
        }
    }
}
