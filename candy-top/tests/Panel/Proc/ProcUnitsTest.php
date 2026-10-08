<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Proc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Panel\Proc\ProcUnits;

/** btop floating_humanizer digits as the proc list prints them. */
final class ProcUnitsTest extends TestCase
{
    public function testHumanizerMatchesBtopDigits(): void
    {
        $this->assertSame('0 Byte', ProcUnits::human(0));
        $this->assertSame('1.50 KiB', ProcUnits::human(1536));
        $this->assertSame('10.0 GiB', ProcUnits::human(10 * 1024 ** 3));
        $this->assertSame('11.7 MiB', ProcUnits::human(12_345_678));
        $this->assertSame('2.00 KiB/s', ProcUnits::human(2048, false, 0, true));
        $this->assertSame('500B', ProcUnits::human(500, true));
        $this->assertSame('1.5K', ProcUnits::human(1536, true));
        $this->assertSame('1.0K', ProcUnits::human(1023, true));
        $this->assertSame('976K', ProcUnits::human(999_999, true));
        $this->assertSame('117M', ProcUnits::human(117 * 1024 ** 2, true));
        $this->assertSame('1.0G', ProcUnits::human(1023 * 1024 ** 2, true));
        $this->assertSame('1.5k', ProcUnits::human(1500, true, 0, false, true), 'base_10_sizes');
        $this->assertSame('0B', ProcUnits::human(-5, true), 'negative clamps to 0');
    }
}
