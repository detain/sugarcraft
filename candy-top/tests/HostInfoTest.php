<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\HostInfo;

final class HostInfoTest extends TestCase
{
    /** @return iterable<string, array{string, string}> btop trim_name cases */
    public static function names(): iterable
    {
        yield 'intel core' => ['Intel(R) Core(TM) i7-8700K CPU @ 3.70GHz', 'i7-8700K'];
        yield 'xeon' => ['Intel(R) Xeon(R) CPU E5-2678 v3 @ 2.50GHz', 'E5-2678'];
        yield 'ryzen' => ['AMD Ryzen 7 5800X 8-Core Processor', 'Ryzen 7 5800X'];
        yield 'ryzen skips PRO/AI tokens' => ['AMD Ryzen AI 9 HX 370 w/ Radeon 890M', 'Ryzen AI 9 HX 370'];
        // btop's blanket s_replace("Core") eats the "Core" of "64-Core" too — kept faithful.
        yield 'epyc fallback strips vendor junk' => ['AMD EPYC 7B13 64-Core Processor', 'EPYC 7B13 64-'];
        yield 'new intel without CPU token' => ['Intel(R) Core(TM) Ultra 7 155H', 'Ultra 7 155H'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('names')]
    public function testTrimNameMatchesBtop(string $raw, string $expected): void
    {
        $this->assertSame($expected, HostInfo::trimName($raw));
    }

    public function testDetectReadsCpuinfoFixture(): void
    {
        $host = HostInfo::detect(Paths::under(__DIR__ . '/fixtures/host'));
        $this->assertSame('i7-8700K', $host->cpuName);
        $this->assertSame(2, $host->coreCount);
        $this->assertFalse($host->hasSensors);
        $this->assertFalse($host->hasCpuHz);
    }

    public function testDetectSurvivesAnEmptyTree(): void
    {
        $host = HostInfo::detect(Paths::under(__DIR__ . '/fixtures/does-not-exist'));
        $this->assertSame('', $host->cpuName);
        $this->assertSame(1, $host->coreCount);
    }

    public function testNewSanitizesAndClamps(): void
    {
        $host = HostInfo::new("evil\x1b[2Jname", 0, "us\x07er", 'host');
        $this->assertStringNotContainsString("\x1b", $host->cpuName);
        $this->assertStringNotContainsString("\x07", $host->user);
        $this->assertSame(1, $host->coreCount);
    }
}
