<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Collect\VmDomain;

final class VmDomainTest extends TestCase
{
    /** The shape of a libvirt status file (kvm521's, trimmed and renamed). */
    public const STATUS_XML = <<<'XML'
        <!--
        WARNING: THIS IS AN AUTO-GENERATED FILE.
        -->
        <domstatus state='running' reason='booted' pid='4242'>
          <taint flag='high-privileges'/>
          <vcpus>
            <vcpu id='0' pid='4250'/>
          </vcpus>
          <domain type='kvm' id='12'>
            <name>web01</name>
            <uuid>94e3c619-047e-46ae-91aa-964592b61598</uuid>
            <memory unit='KiB'>4194304</memory>
            <currentMemory unit='KiB'>2097152</currentMemory>
            <vcpu placement='static'>4</vcpu>
            <devices>
              <disk type='file' device='disk'>
                <source file='/vz/web01/os.qcow2' index='1'/>
                <target dev='sda' bus='scsi'/>
              </disk>
              <interface type='bridge'>
                <mac address='00:16:3E:33:65:93'/>
                <source bridge='br0'/>
                <target dev='vnet3'/>
              </interface>
              <interface type='bridge'>
                <mac address='52:54:00:01:02:03'/>
                <target dev='vnet4'/>
              </interface>
            </devices>
          </domain>
        </domstatus>
        XML;

    public function testStatusXml(): void
    {
        $d = VmDomain::fromXml(self::STATUS_XML);
        $this->assertNotNull($d);
        $this->assertSame('web01', $d->name);
        $this->assertSame(12, $d->domainId);
        $this->assertSame(4, $d->vcpus);
        $this->assertSame(4 * 1024 ** 3, $d->memBytes);
        $this->assertSame(2 * 1024 ** 3, $d->currentMemBytes);
        // Disk targets (sda) are not interfaces.
        $this->assertSame(['vnet3', 'vnet4'], $d->interfaces);
        $this->assertSame(['00:16:3e:33:65:93', '52:54:00:01:02:03'], $d->macs);
        $this->assertSame(4242, $d->pid);
        $this->assertTrue($d->fromXml);
    }

    public function testHotplugCurrentVcpusAndUnits(): void
    {
        $xml = "<domain type='kvm'><name>a&amp;b</name><memory unit='GiB'>8</memory><vcpu placement='static' current='2'>8</vcpu></domain>";
        $d = VmDomain::fromXml($xml);
        $this->assertNotNull($d);
        $this->assertSame('a&b', $d->name);
        $this->assertSame(Sentinel::UNMEASURED_INT, $d->domainId);
        $this->assertSame(2, $d->vcpus);
        $this->assertSame(8 * 1024 ** 3, $d->memBytes);
        $this->assertSame([], $d->interfaces, 'no NIC: known and empty, not unknown');
        $this->assertSame(0, $d->pid);
    }

    public function testNotADomain(): void
    {
        $this->assertNull(VmDomain::fromXml(''));
        $this->assertNull(VmDomain::fromXml('<network><name>default</name></network>'));
        $this->assertNull(VmDomain::fromXml("<domain type='kvm'><name> </name></domain>"));
    }

    public function testUnsafeTapNamesAreDropped(): void
    {
        $xml = "<domain><name>x</name><interface><target dev='../../etc'/></interface><interface><target dev='vnet9'/></interface></domain>";
        $this->assertSame(['vnet9'], VmDomain::fromXml($xml)?->interfaces);
    }

    public function testCommandLineFacts(): void
    {
        $cmdline = implode("\0", [
            '/usr/bin/kvm', '-name', 'guest=db-1,debug-threads=on', '-smp', '2,sockets=2,cores=1,threads=1', '-m', 'size=2097152k',
            '-netdev', 'tap,fd=30,id=hostnet0',
            '-device', '{"driver":"virtio-net-pci","netdev":"hostnet0","id":"net0","mac":"52:54:00:AA:BB:CC","bus":"pci.0"}',
            '-device', 'e1000,netdev=hostnet1,mac=52:54:00:11:22:33',
        ]) . "\0";
        $d = VmDomain::fromCmdline($cmdline, 'db-1', 13, 777);
        $this->assertSame('db-1', $d->name);
        $this->assertSame(13, $d->domainId);
        $this->assertSame(2, $d->vcpus);
        $this->assertSame(2 * 1024 ** 3, $d->memBytes);
        $this->assertNull($d->interfaces, 'taps unknown until matched by MAC');
        $this->assertSame(['52:54:00:aa:bb:cc', '52:54:00:11:22:33'], $d->macs);
        $this->assertSame(777, $d->pid);
        $this->assertFalse($d->fromXml);
        $this->assertSame(['vnet7'], $d->withInterfaces(['vnet7'])->interfaces);
    }

    public function testUnreadableCommandLineKeepsTheScopeName(): void
    {
        $d = VmDomain::fromCmdline(null, 'vps1', 3, 0);
        $this->assertSame('vps1', $d->name);
        $this->assertSame(Sentinel::UNMEASURED_INT, $d->vcpus);
        $this->assertSame([], $d->macs);
    }
}
