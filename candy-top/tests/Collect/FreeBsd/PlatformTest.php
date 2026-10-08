<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect;
use SugarCraft\Top\Collect\FreeBsd;
use SugarCraft\Top\Collect\FreqMode;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;

final class PlatformTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function oses(): array
    {
        return [
            'FreeBSD' => ['FreeBSD', true],
            'case-insensitive' => ['freebsd', true],
            'Linux' => ['Linux', false],
            'OpenBSD keeps the sentinel-safe Linux family' => ['OpenBSD', false],
            'Darwin' => ['Darwin', false],
        ];
    }

    /** @dataProvider oses */
    public function testSelectsTheCollectorFamilyByOs(string $os, bool $freeBsd): void
    {
        $platform = Platform::for($os, new FixtureProbe());
        $this->assertSame($freeBsd, $platform->isFreeBsd());
        $this->assertSame($os, $platform->os);

        $expect = [
            'cpu' => [FreeBsd\Cpu::class, Collect\Cpu::class],
            'memory' => [FreeBsd\Memory::class, Collect\Memory::class],
            'net' => [FreeBsd\Net::class, Collect\Net::class],
            'diskIo' => [FreeBsd\DiskIo::class, Collect\DiskIo::class],
            'mounts' => [FreeBsd\Mounts::class, Collect\Mounts::class],
            'procList' => [FreeBsd\ProcList::class, Collect\ProcList::class],
            'freq' => [FreeBsd\Freq::class, Collect\Freq::class],
            'temp' => [FreeBsd\Temp::class, Collect\Temp::class],
            'battery' => [FreeBsd\Battery::class, Collect\Battery::class],
        ];
        foreach ($expect as $method => [$bsd, $linux]) {
            $source = $platform->{$method}();
            $this->assertInstanceOf(CollectorSource::class, $source, $method);
            $this->assertInstanceOf($freeBsd ? $bsd : $linux, $source->collector(), $method);
        }
    }

    /** @dataProvider oses */
    public function testFactoriesPassTheirArgumentsAndMatchTheLiveWiringDefaults(string $os): void
    {
        $platform = Platform::for($os, new FixtureProbe());
        $prop = static fn (object $o, string $name): mixed => (new \ReflectionProperty($o, $name))->getValue($o);
        $c = static fn (\SugarCraft\Top\Source\Source $s): object => $s instanceof CollectorSource ? $s->collector() : throw new \LogicException();

        $this->assertTrue($prop($c($platform->memory()), 'zfsArcCached'), 'zfs_arc_cached defaults on, as btop');
        $this->assertFalse($prop($c($platform->memory(false)), 'zfsArcCached'));
        $this->assertFalse($prop($c($platform->diskIo()), 'physicalOnly'), 'DisksSource::live() pairs mounts with partitions: unfiltered');
        $this->assertTrue($prop($c($platform->diskIo(true)), 'physicalOnly'));
        $this->assertTrue($c($platform->mounts())->selection()->physicalOnly, 'Mounts::new() default');
        $this->assertFalse($c($platform->mounts(false))->selection()->physicalOnly);
        $freq = $c($platform->freq(FreqMode::Range, true));
        $this->assertSame(FreqMode::Range, $prop($freq, 'mode'));
        $this->assertTrue($prop($freq, 'perCore'));
        $this->assertFalse($prop($c($platform->freq()), 'perCore'));
        $this->assertSame('acpi/tz0', $prop($c($platform->temp('acpi/tz0')), 'preferred'));
        $this->assertNull($prop($c($platform->temp()), 'preferred'));
        $this->assertSame('BAT1', $c($platform->battery('BAT1'))->selected());
        $this->assertNull($c($platform->battery('Auto'))->selected());
        $this->assertSame('re0', $prop($c($platform->net('re0')), 'pinned'));
        $this->assertNull($prop($c($platform->net()), 'pinned'));
    }

    public function testDetectFollowsPhpOs(): void
    {
        $this->assertSame(PHP_OS, Platform::detect()->os);
    }

    public function testFreeBsdSourcesSampleThroughTheInjectedProbe(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'), ['swapinfo' => FixtureProbe::fixture('swapinfo-k.txt')]);
        $platform = Platform::for('FreeBSD', $probe);

        [$mem, $next] = $platform->memory()->sample();
        $this->assertSame(1005423 * 4096, $mem->total);
        $this->assertInstanceOf(CollectorSource::class, $next);

        $freq = $platform->freq(FreqMode::First, true)->collector();
        $this->assertInstanceOf(FreeBsd\Freq::class, $freq);
        $this->assertInstanceOf(FreeBsd\Freq::class, $freq->withPerCore(false), 'same retuning surface as the Linux collector');
    }

    public function testHostInfoFromTheReferenceHost(): void
    {
        $host = Platform::for('FreeBSD', new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt')))->hostInfo();

        $this->assertSame(4, $host->coreCount);
        $this->assertSame('D525', $host->cpuName, 'btop trim_name keeps the token after "CPU"');
        $this->assertTrue($host->hasSensors, 'acpi tz0 counts');
        $this->assertFalse($host->hasCpuHz, 'no cpufreq driver');
    }

    public function testHostInfoOnABareHost(): void
    {
        $host = FreeBsd\HostDetect::detect(new FixtureProbe());

        $this->assertSame(1, $host->coreCount);
        $this->assertSame('', $host->cpuName);
        $this->assertFalse($host->hasSensors);
    }
}
