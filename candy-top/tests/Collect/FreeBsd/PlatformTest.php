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

    /**
     * candy-top-003/-004: Linux and FreeBSD are the whole tested boundary;
     * the Darwin and OpenBSD families btop ships (1.1.0 / 1.3.0) do not
     * exist here, so isSupported() must say false for them, not pretend
     * the /proc fallback is a platform.
     */
    public function testNamesTheTestedPlatformBoundary(): void
    {
        $this->assertTrue(Platform::for('Linux')->isSupported());
        $this->assertTrue(Platform::for('linux')->isSupported());
        $this->assertTrue(Platform::for('FreeBSD')->isSupported());

        foreach (['Darwin', 'OpenBSD', 'NetBSD', 'WINNT', 'CYGWIN'] as $os) {
            $this->assertFalse(Platform::for($os)->isSupported(), $os);
        }
    }

    /** @dataProvider oses */
    public function testSelectsTheCollectorFamilyByOs(string $os, bool $freeBsd): void    {
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

    public function testAPartiallyHealthyBoxKeepsEverySourceAnswering(): void
    {
        // S-P21 (candy-top-089): one mixed-health FreeBSD box wired through
        // the real Platform: sysctl-based panels and the two survivors
        // (swapinfo, ps) answer with real numbers, while netstat, ifconfig,
        // iostat, mount and procstat all fail. No collector may throw, and
        // a dead tool must degrade ONLY its own panel — never the box.
        $probe = new FixtureProbe(
            FixtureProbe::fixture('sysctl-reference.txt'),
            [
                'swapinfo' => FixtureProbe::fixture('swapinfo-k.txt'),
                'ps' => FixtureProbe::fixture('ps-root.synthetic.txt'),
                'netstat' => null,
                'ifconfig' => null,
                'iostat' => null,
                'mount' => null,
                'procstat' => null,
            ],
        );
        $platform = Platform::for('FreeBSD', $probe);

        [$cpu] = $platform->cpu()->sample();
        $this->assertGreaterThan(1, count($cpu->cores), 'kern.cp_times survives: every core answers');

        [$mem] = $platform->memory()->sample();
        $this->assertSame(1005423 * 4096, $mem->total, 'swapinfo survived');

        [$net] = $platform->net('')->sample();
        $this->assertSame([], $net->interfaces, 'netstat+ifconfig down: no interfaces, no exception');

        [$disk] = $platform->diskIo(true)->sample();
        $this->assertSame([], $disk->devices, 'iostat down: an empty device list');

        [$mounts] = $platform->mounts(true)->sample();
        $this->assertSame([], $mounts->mounts, 'mount down: an empty table');

        [$proc] = $platform->procList()->sample();
        $this->assertGreaterThan(0, count($proc->processes), 'ps survived — the proc table stands without procstat');

        [$freq] = $platform->freq(FreqMode::First, true)->sample();
        $this->assertIsObject($freq, 'the sysctl freq panel answers through kern.cp_times despite no cpufreq driver');

        foreach ([$platform->temp(), $platform->battery()] as $source) {
            [$snap] = $source->sample();
            $this->assertIsObject($snap, 'sysctl-backed panels answer on a sensor-less/battery-less box');
        }
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
