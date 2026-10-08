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
}
