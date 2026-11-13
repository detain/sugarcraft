<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Gpu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Gpu\PciCard;
use SugarCraft\Top\Collect\Sentinel;

/**
 * The discovery-fallback arms of PciCard (candy-top-088): uevent wins, the
 * driver symlink's basename stands in when it stays silent, the device
 * directory's own name stands in for the slot ONLY if it is a real BDF,
 * and a garbage link speed reads unmeasured instead of guessing a
 * generation.
 */
final class PciCardTest extends TestCase
{
    private GpuTree $tree;

    protected function setUp(): void
    {
        $this->tree = GpuTree::of();
    }

    protected function tearDown(): void
    {
        $this->tree->destroy();
    }

    /** @param array<string, string> $files relative to the device dir */
    private function device(string $dir, array $files): string
    {
        $path = $this->tree->root . '/' . $dir;
        foreach ($files as $name => $contents) {
            $this->tree->write("$dir/$name", $contents);
        }

        return $path;
    }

    public function testUeventWinsOverTheDriverSymlinkAndSlotFromUevent(): void
    {
        $path = $this->device('dev/uevent-first', [
            'vendor' => "0x1002\n",
            'device' => "0x744c\n",
            'revision' => "0xc4\n",
            'uevent' => "DRIVER=nvidia\nPCI_SLOT_NAME=0000:03:00.0\n",
        ]);
        @symlink('/sys/bus/pci/drivers/amdgpu', "$path/driver");

        $card = PciCard::at('card9', $path);
        $this->assertNotNull($card);
        $this->assertSame('nvidia', $card->driver, 'uevent beats the symlink');
        $this->assertSame('0000:03:00.0', $card->busId);
        $this->assertSame(0x1002, $card->vendorId);
        $this->assertSame(0x744C, $card->deviceId);
        $this->assertSame(0xC4, $card->revision);
    }

    public function testDriverSymlinkBasenameAnswersWhenUeventStaysSilent(): void
    {
        $path = $this->device('dev/symlink-only', ['vendor' => "0x8086\n", 'uevent' => "PCI_SLOT_NAME=0000:00:02.0\n"]);
        @symlink('/sys/bus/pci/drivers/i915', "$path/driver");

        $card = PciCard::at('card7', $path);
        $this->assertNotNull($card);
        $this->assertSame('i915', $card->driver, 'the link basename (where btop Asysfs::init reads the symlink)');
    }

    public function testNoDriverAnywhereReadsUnavailable(): void
    {
        $path = $this->device('dev/driverless', ['vendor' => "0x10de\n", 'uevent' => "MODALIAS=pci:v000010DEd00002204\n"]);

        $card = PciCard::at('card8', $path);
        $this->assertNotNull($card);
        $this->assertSame(Sentinel::UNAVAILABLE, $card->driver);
    }

    public function testSlotFallsBackToTheDirectoryNameOnlyForRealBdfs(): void
    {
        $path = $this->device('dev/0000:3b:00.0', ['vendor' => "0x1002\n", 'uevent' => "DRIVER=amdgpu\n"]);
        $card = PciCard::at('slot-by-name', $path);
        $this->assertNotNull($card);
        $this->assertSame('0000:3b:00.0', $card->busId, 'a real BDF directory names the slot');

        $junk = $this->device('dev/not-a-slot', ['vendor' => "0x1002\n", 'uevent' => "DRIVER=amdgpu\n"]);
        $fallback = PciCard::at('card6', $junk);
        $this->assertNotNull($fallback);
        $this->assertSame(Sentinel::UNAVAILABLE, $fallback->busId, 'a directory called anything else names nothing');
    }

    public function testLinkSpeedFoldsKnownGenerationsAndRefusesGarbage(): void
    {
        $known = $this->device('dev/fast', ['vendor' => "0x10de\n", 'current_link_speed' => "16.0 GT/s PCIe\n"]);
        $this->assertSame(4, PciCard::at('card1', $known)?->pcieGen());
        $this->device('dev/gen5', ['vendor' => "0x10de\n", 'current_link_speed' => "32 GT/s PCIe\n"]);
        $gen5 = $this->tree->root . '/dev/gen5';
        $this->assertSame(5, PciCard::at('card2', $gen5)?->pcieGen());

        foreach (['unknown', ''] as $i => $junk) {
            $path = $this->tree->root . '/dev/junk' . $i;
            $this->device("dev/junk$i", ['vendor' => "0x10de\n", 'current_link_speed' => $junk]);
            $this->assertSame(Sentinel::UNMEASURED_INT, PciCard::at("junk$i", $path)?->pcieGen(), "garbage speed '$junk' reads unmeasured, never guessed");
        }

        $outOfRange = $this->device('dev/absurd', ['vendor' => "0x10de\n", 'current_link_speed' => "999.0 GT/s PCIe\n"]);
        $this->assertSame(Sentinel::UNMEASURED_INT, PciCard::at('absurd', $outOfRange)?->pcieGen(), 'no future generation is invented');
        $this->assertSame(Sentinel::UNMEASURED_INT, PciCard::at('novalue', $this->device('dev/absent', ['vendor' => "0x10de\n"]))?->pcieGen(), 'absent node reads unmeasured');
    }

    public function testNoVendorMeansNoCard(): void
    {
        $path = $this->device('dev/headless', ['uevent' => "DRIVER=amdgpu\n"]);
        $this->assertNull(PciCard::at('card5', $path));
    }
}
