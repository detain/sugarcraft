<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App\Tabs;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\Msg;
use SugarCraft\Code\App\Tabs\CycleTabMsg;

final class CycleTabMsgTest extends TestCase
{
    public function testDefaultsToForwardOne(): void
    {
        self::assertSame(1, (new CycleTabMsg())->delta());
    }

    public function testCarriesNegativeAndLargeDeltas(): void
    {
        self::assertSame(-1, (new CycleTabMsg(-1))->delta());
        self::assertSame(5, (new CycleTabMsg(5))->delta());
        self::assertSame(0, (new CycleTabMsg(0))->delta());
    }

    public function testPublicPropertyMatchesAccessor(): void
    {
        $m = new CycleTabMsg(-3);

        self::assertSame($m->delta, $m->delta());
    }

    public function testIsAnAppMsg(): void
    {
        self::assertInstanceOf(Msg::class, new CycleTabMsg());
    }
}
