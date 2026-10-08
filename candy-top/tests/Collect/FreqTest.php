<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Freq;
use SugarCraft\Top\Collect\FreqMode;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;

final class FreqTest extends TestCase
{
    public function testFirstModeAndLimits(): void
    {
        $freq = Freq::new(FixtureTree::committed());
        [$snap, $next] = $freq->sample();

        $this->assertSame(3400.0, $snap->mhz);
        $this->assertSame([3400.0, 2200.0], $snap->cores, 'zero reading dropped');
        $this->assertSame(800.0, $snap->minMhz);
        $this->assertSame(4500.0, $snap->maxMhz);
        $this->assertTrue($snap->boost);
        $this->assertSame('3.4 GHz', $snap->label);
        $this->assertSame($freq, $next);
    }

    /**
     * @return iterable<string, array{FreqMode, float, string}>
     */
    public static function modes(): iterable
    {
        yield 'average' => [FreqMode::Average, 2800.0, '2.8 GHz'];
        yield 'highest' => [FreqMode::Highest, 3400.0, '3.4 GHz'];
        yield 'lowest' => [FreqMode::Lowest, 2200.0, '2.2 GHz'];
        yield 'range' => [FreqMode::Range, 2200.0, '2.2 GHz - 3.4 GHz'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function testModes(FreqMode $mode, float $mhz, string $label): void
    {
        [$snap] = Freq::new(FixtureTree::committed(), $mode)->sample();

        $this->assertSame($mhz, $snap->mhz);
        $this->assertSame($label, $snap->label);
    }

    public function testCpuinfoFallback(): void
    {
        $tree = FixtureTree::copy();
        try {
            $tree->remove('sys/devices/system/cpu/cpufreq');
            [$snap] = Freq::new($tree->paths())->sample();

            $this->assertSame(2400.0, $snap->mhz);
            $this->assertSame('2.4 GHz', $snap->label);
            $this->assertNull($snap->boost);
            $this->assertSame(Sentinel::UNMEASURED, $snap->maxMhz);
        } finally {
            $tree->destroy();
        }
    }

    public function testNothingReadableIsUnmeasured(): void
    {
        $tree = FixtureTree::empty();
        try {
            [$snap] = Freq::new($tree->paths())->sample();

            $this->assertSame(Sentinel::UNMEASURED, $snap->mhz);
            $this->assertSame('', $snap->label);
            $this->assertSame([], $snap->cores);
        } finally {
            $tree->destroy();
        }
    }

    public function testNormalizeMirrorsBtop(): void
    {
        $this->assertSame('800 MHz', Freq::normalize(800.0));
        $this->assertSame('1.0 GHz', Freq::normalize(1000.0), 'three chars fit "1.0" whole');
        $this->assertSame('3.4 GHz', Freq::normalize(3449.0));
        $this->assertSame('12 GHz', Freq::normalize(12345.0));
        $this->assertSame('1.0 THz', Freq::normalize(1000000.0));
    }

    public function testPerCoreIsOffByDefault(): void
    {
        [$snap] = Freq::new(FixtureTree::committed())->sample();

        $this->assertSame([], $snap->perCore);
        $this->assertSame([], $snap->perCoreMin);
        $this->assertSame([], $snap->perCoreMax);
    }

    public function testPerCoreReadsCpuNCpufreqKeyedByIndex(): void
    {
        $freq = Freq::new(FixtureTree::committed())->withPerCore(true);
        [$snap, $next] = $freq->sample();

        $this->assertSame([0 => 3400.0, 1 => 2200.0, 2 => Sentinel::UNMEASURED, 10 => 1200.5], $snap->perCore, 'offline cpu2 → sentinel, numeric order');
        $this->assertSame([0 => 800.0, 1 => 800.0, 2 => Sentinel::UNMEASURED, 10 => 400.0], $snap->perCoreMin);
        $this->assertSame([0 => 4500.0, 1 => 4500.0, 2 => Sentinel::UNMEASURED, 10 => 3000.0], $snap->perCoreMax);
        $this->assertSame('3.4 GHz', $snap->label, 'aggregate keeps policy semantics');
        $this->assertSame($freq, $next);
        $this->assertSame($freq, $freq->withPerCore(true));
        [$off] = $freq->withPerCore(false)->sample();
        $this->assertSame([], $off->perCore);
    }

    public function testPerCoreFallsBackToCpuinfoPerProcessor(): void
    {
        $tree = FixtureTree::copy();
        try {
            foreach (['cpu0', 'cpu1', 'cpu10'] as $cpu) {
                $tree->remove("sys/devices/system/cpu/{$cpu}/cpufreq");
            }
            $tree->write('proc/cpuinfo', "processor\t: 0\ncpu MHz\t\t: 2400.000\n\nprocessor\t: 1\ncpu MHz\t\t: 1800.5\n\nprocessor\t: 2\nmodel name\t: x\n");
            [$snap] = Freq::new($tree->paths(), perCore: true)->sample();

            $this->assertSame([0 => 2400.0, 1 => 1800.5, 2 => Sentinel::UNMEASURED, 10 => Sentinel::UNMEASURED], $snap->perCore);
            $this->assertSame(Sentinel::UNMEASURED, $snap->perCoreMax[1]);
            $this->assertCount(4, $snap->perCoreMin);
        } finally {
            $tree->destroy();
        }
    }

    public function testPerCoreUsesTheAggregatePlausibilityRule(): void
    {
        $tree = FixtureTree::copy();
        try {
            // 500 kHz = 0.5 MHz and 1 kHz are failed reads, as is ≥ 999999999 MHz.
            $tree->write('sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq', "500\n");
            $tree->write('sys/devices/system/cpu/cpu1/cpufreq/scaling_cur_freq', "999999999000\n");
            $tree->write('sys/devices/system/cpu/cpu1/cpufreq/scaling_min_freq', "1000\n");
            [$snap] = Freq::new($tree->paths(), perCore: true)->sample();

            $this->assertSame(Sentinel::UNMEASURED, $snap->perCore[0]);
            $this->assertSame(Sentinel::UNMEASURED, $snap->perCore[1]);
            $this->assertSame(Sentinel::UNMEASURED, $snap->perCoreMin[1], '1 MHz is not a frequency');
            $this->assertSame(1200.5, $snap->perCore[10], 'plausible cores untouched');

            // The same raw reading through the aggregate path agrees.
            foreach (array_diff(scandir($tree->root . '/sys/devices/system/cpu/cpufreq') ?: [], ['.', '..', 'boost']) as $policy) {
                $tree->write("sys/devices/system/cpu/cpufreq/{$policy}/scaling_cur_freq", "500\n");
            }
            $tree->write('proc/cpuinfo', "processor\t: 0\ncpu MHz\t\t: 0.5\n\nprocessor\t: 1\ncpu MHz\t\t: 9999999999\n");
            [$agg] = Freq::new($tree->paths())->sample();
            $this->assertSame(Sentinel::UNMEASURED, $agg->mhz);

            foreach (['cpu0', 'cpu1', 'cpu10'] as $cpu) {
                $tree->remove("sys/devices/system/cpu/{$cpu}/cpufreq");
            }
            [$fallback] = Freq::new($tree->paths(), perCore: true)->sample();
            $this->assertSame(Sentinel::UNMEASURED, $fallback->perCore[0], 'cpuinfo fallback: 0.5 MHz rejected');
            $this->assertSame(Sentinel::UNMEASURED, $fallback->perCore[1], 'cpuinfo fallback: absurd value rejected');
        } finally {
            $tree->destroy();
        }
    }

    public function testPerCoreOnEmptyTreeIsEmpty(): void
    {
        $tree = FixtureTree::empty();
        try {
            [$snap] = Freq::new($tree->paths(), perCore: true)->sample();
            $this->assertSame([], $snap->perCore);
        } finally {
            $tree->destroy();
        }
    }

    public function testLabelIsTheSharedFormatter(): void
    {
        $this->assertSame('3.4 GHz', Freq::label(3449.0));
        $this->assertSame('800 MHz', Freq::label(800.0));
        $this->assertSame('', Freq::label(Sentinel::UNMEASURED));
        $this->assertSame('', Freq::label(0.0));
        $this->assertSame('', Freq::label(INF));
        $this->assertSame('', Freq::label(NAN));
        $this->assertSame('1 MHz', Freq::label(1.0), 'upstream cutoff is <= 0, not <= 1');
    }

    public function testLivePerCoreSmoke(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_dir('/sys/devices/system/cpu')) {
            $this->markTestSkipped('needs a Linux /sys');
        }
        [$snap] = Freq::new(perCore: true)->sample();

        foreach ($snap->perCore as $cpu => $mhz) {
            $this->assertIsInt($cpu);
            $this->assertTrue($mhz === Sentinel::UNMEASURED || $mhz > 1.0);
        }
    }
}
