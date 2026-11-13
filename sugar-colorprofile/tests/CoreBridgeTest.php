<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\Core\Util\ColorProfile as Core;

/** CP-22: fromCore()/toCore() bridge to the legacy five-rung Core enum. */
final class CoreBridgeTest extends TestCase
{
    public function testRoundTripOnAllCoreCases(): void
    {
        foreach (Core::cases() as $core) {
            $this->assertSame($core, ColorProfile::fromCore($core)->toCore());
        }
    }

    public function testNoTtyMapsToNoTTY(): void
    {
        $this->assertSame(ColorProfile::NoTTY, ColorProfile::fromCore(Core::NoTty));
    }

    public function testLossyArmsLandOnNearestSafeRung(): void
    {
        $this->assertSame(Core::Ansi256, ColorProfile::Ansi240->toCore());
        $this->assertSame(Core::Ansi, ColorProfile::Ansi8->toCore());
        $this->assertSame(Core::Ascii, ColorProfile::Fgbg->toCore());
    }

    public function testEveryCaseProjects(): void
    {
        foreach (ColorProfile::cases() as $p) {
            $this->assertInstanceOf(Core::class, $p->toCore());
        }
    }
}
