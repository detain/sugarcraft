<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Gpu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Gpu\AmdGpuIds;
use SugarCraft\Top\Collect\Gpu\PciIds;
use SugarCraft\Top\Collect\Paths;

final class NamesTest extends TestCase
{
    public function testPciIdsStreamsTheVendorBlock(): void
    {
        $ids = PciIds::locate(Paths::under(GpuTree::fixtures() . '/amd'));

        $this->assertStringEndsWith('/usr/share/misc/pci.ids', (string) $ids->file);
        $this->assertSame('Navi 21 [Radeon RX 6800/6800 XT / 6900 XT]', $ids->name(0x1002, 0x73bf));
        $this->assertSame('AMD IPU Device', $ids->name(0x1022, 0x1502));
        $this->assertSame('82579LM Gigabit Network Connection (Lewisville)', $ids->name(0x8086, 0x1502), 'same device id, other vendor');
        $this->assertNull($ids->name(0x1002, 0x0e3a), 'subsystem lines are not devices');
        $this->assertNull($ids->name(0x10de, 0xa7a0), 'stops at the end of the vendor block');
        $this->assertNull($ids->name(0x0000, 0x0000), 'class section is not a vendor');
    }

    public function testPciIdsSearchOrderAndAbsence(): void
    {
        $this->assertStringEndsWith('/usr/share/hwdata/pci.ids', (string) PciIds::locate(Paths::under(GpuTree::fixtures() . '/intel'))->file);
        $none = PciIds::locate(Paths::under(GpuTree::fixtures() . '/nonexistent'));
        $this->assertNull($none->file);
        $this->assertNull($none->name(0x1002, 0x73bf));
        $this->assertSame('[31mab', PciIds::clean("\x1b[31ma\x07b\xc2\x9b"), 'ESC, BEL and C1 bytes are dropped');
    }

    public function testAmdGpuIdsMatchesDeviceAndRevision(): void
    {
        $ids = AmdGpuIds::locate(Paths::under(GpuTree::fixtures() . '/amd'));

        $this->assertSame(6, $ids->count(), 'version line and comments are not entries');
        $this->assertSame('Radeon 8060S Graphics', $ids->name(0x1586, 0xc1));
        $this->assertSame('Radeon 8050S Graphics', $ids->name(0x1586, 0xc2));
        $this->assertSame('AMD Radeon RX 6800', $ids->name(0x73bf, 0xc3));
        $this->assertNull($ids->name(0x1586, 0x00), 'revision must match');
        $this->assertNull(AmdGpuIds::locate(Paths::under(GpuTree::fixtures() . '/intel'))->file);
        $this->assertNull(AmdGpuIds::at(null)->name(0x1586, 0xc1));
    }
}
