<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Tests\Dock;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\Dock\Side;

/**
 * Side enum — the dock's left/right pairing helper.
 */
final class SideTest extends TestCase
{
    public function testSideHasExactlyTwoCases(): void
    {
        $this->assertSame([Side::Left, Side::Right], Side::cases());
    }

    public function testOtherFlipsLeftToRight(): void
    {
        $this->assertSame(Side::Right, Side::Left->other());
    }

    public function testOtherFlipsRightToLeft(): void
    {
        $this->assertSame(Side::Left, Side::Right->other());
    }

    public function testOtherIsAnInvolution(): void
    {
        foreach (Side::cases() as $side) {
            $this->assertSame($side, $side->other()->other());
        }
    }
}
