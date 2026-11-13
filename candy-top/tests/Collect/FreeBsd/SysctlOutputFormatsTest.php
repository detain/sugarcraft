<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\FreeBsd\LiveProbe;
use SugarCraft\Top\Collect\FreeBsd\Sysctl;

/**
 * sysctl(8) output grammar pinned to the man page (no live host needed):
 * `-e` prints name=value, the default prints "name: value", `-n` prints
 * values only, `-i` omits unknown OIDs.
 */
final class SysctlOutputFormatsTest extends TestCase
{
    public function testArgvUsesNameValueFlagNotValuesOnly(): void
    {
        $argv = LiveProbe::sysctlArgv(['kern.ostype', 'vm.loadavg']);
        $this->assertSame(['sysctl', '-i', '-e', 'kern.ostype', 'vm.loadavg'], $argv);
        $this->assertNotContains('-n', $argv, '-n would print values only and break name keying');
    }

    public function testEqualsFormFromDashE(): void
    {
        $this->assertSame(['kern.ostype' => 'FreeBSD', 'hw.ncpu' => '4'], Sysctl::parse("kern.ostype=FreeBSD\nhw.ncpu=4\n"));
    }

    public function testDefaultColonForm(): void
    {
        $this->assertSame(['kern.ostype' => 'FreeBSD'], Sysctl::parse("kern.ostype: FreeBSD\n"));
    }

    public function testMultiTokenValues(): void
    {
        $v = Sysctl::parse("vm.loadavg: { 0.32 0.33 0.25 }\nkern.cp_times=1 2 3 4 5 6 7 8 9 10\n");
        $this->assertSame('{ 0.32 0.33 0.25 }', $v['vm.loadavg']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], Sysctl::ints($v, 'kern.cp_times'));
    }

    public function testPercentInOidName(): void
    {
        $this->assertSame(['dev.cpu.0.%desc' => 'ACPI CPU'], Sysctl::parse("dev.cpu.0.%desc=ACPI CPU\n"));
    }

    public function testFirstSeparatorSplits(): void
    {
        $v = Sysctl::parse("hw.model=Intel(R) CPU @ 1.80GHz\nx.y=a=b\nx.z: a: b\n");
        $this->assertSame('Intel(R) CPU @ 1.80GHz', $v['hw.model']);
        $this->assertSame('a=b', $v['x.y']);
        $this->assertSame('a: b', $v['x.z']);
    }

    public function testDashIOmittedOidsSimplyAbsent(): void
    {
        $v = Sysctl::parse("kern.ostype=FreeBSD\n");
        $this->assertArrayNotHasKey('hw.acpi.battery.life', $v);
        $this->assertNull(Sysctl::int($v, 'hw.acpi.battery.life'));
    }

    public function testBlankAndGarbageLinesIgnored(): void
    {
        $this->assertSame(['a.b' => '1'], Sysctl::parse("\n   \n!!! nope\na.b=1\r\n\n"));
    }

    public function testValuesOnlyReplyYieldsNothing(): void
    {
        $this->assertSame([], Sysctl::parse("FreeBSD\n4\n"));
        $this->assertSame([], Sysctl::parse("{ 0.32 0.33 0.25 }\n"));
    }
}
