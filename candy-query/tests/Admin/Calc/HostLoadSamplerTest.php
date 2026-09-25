<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Calc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Calc\HostLoadMode;
use SugarCraft\Query\Admin\Calc\HostLoadSampler;

/**
 * Parses /proc fixtures from temp files — never the live /proc — so the
 * math and mode selection stay deterministic on any machine.
 */
final class HostLoadSamplerTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    /** scripted wall clock */
    private float $now = 1000.0;

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->tmpFiles = [];
    }

    public function testLocalModeParsesGnuLoadAverageShape(): void
    {
        $sampler = HostLoadSampler::local($this->write('loadavg', "0.52 0.58 0.59 2/389 12345\n"), $this->statFixture());

        $sampler->sample(null, null);

        self::assertSame(HostLoadMode::HostProc, $sampler->mode());
        self::assertSame([0.52], $sampler->loadWindow());
    }

    public function testLocalModeParsesZeroedLoadAverageShape(): void
    {
        $sampler = HostLoadSampler::local($this->write('loadavg', "0.00 0.01 0.05 1/2 3\n"), $this->statFixture());

        $sampler->sample(null, null);

        self::assertSame(0.0, $sampler->latestLoad());
    }

    public function testBusyFractionIsJiffiesDeltaRatio(): void
    {
        $statPath = $this->write('stat', $this->statText(100, 10, 20, 800, 60, 5, 5, 10));
        $sampler = HostLoadSampler::local($this->write('loadavg', "0.10 0.10 0.10 1/1 1\n"), $statPath);

        $sampler->sample(null, null); // primes the previous jiffies read
        self::assertSame([], $sampler->busyWindow(), 'first read has no delta to divide by');

        // totals 1010 -> 1195, busy 150 -> 225: ratio 75/185
        file_put_contents($statPath, $this->statText(150, 10, 40, 900, 70, 5, 5, 15));
        $sampler->sample(null, null);

        self::assertCount(1, $sampler->busyWindow());
        self::assertEqualsWithDelta(75.0 / 185.0, $sampler->latestBusy(), 1e-9);
    }

    public function testIdenticalJiffiesReadsAreSkipped(): void
    {
        $statPath = $this->statFixture();
        $sampler = HostLoadSampler::local($this->write('loadavg', "1.0 1.0 1.0 1/1 1\n"), $statPath);

        $sampler->sample(null, null);
        $sampler->sample(null, null); // same contents: deltaTotal 0, aliasing guard

        self::assertSame([], $sampler->busyWindow());
        self::assertCount(2, $sampler->loadWindow(), 'loadavg keeps sampling even when jiffies stall');
    }

    public function testUnreadableProcFilesSkipTheirPoints(): void
    {
        $sampler = HostLoadSampler::local('/definitely/not/here/loadavg', '/definitely/not/here/stat');

        $sampler->sample(null, null);

        self::assertSame([], $sampler->loadWindow());
        self::assertSame([], $sampler->busyWindow());
        self::assertNotNull($sampler->lastSampleAt(), 'the stamp still advances so cadence keeps working');
    }

    public function testInjectedClockStampsSamples(): void
    {
        $this->now = 555.0;
        $sampler = HostLoadSampler::local(
            $this->write('loadavg', "0.5 0.5 0.5 1/1 1\n"),
            $this->statFixture(),
            fn (): float => $this->now,
        );

        $sampler->sample(null, null);
        self::assertSame(555.0, $sampler->lastSampleAt());

        $this->now = 556.5;
        $sampler->sample(null, null);
        self::assertSame(556.5, $sampler->lastSampleAt());
    }

    public function testWindowsTrimOldestFirst(): void
    {
        $loadavgPath = $this->write('loadavg', "0.25 0.25 0.25 1/1 1\n");
        $sampler = HostLoadSampler::local($loadavgPath, '/definitely/not/here/stat');

        for ($i = 0; $i < HostLoadSampler::WINDOW + 5; $i++) {
            $sampler->sample(null, null);
        }

        self::assertCount(HostLoadSampler::WINDOW, $sampler->loadWindow());
        self::assertSame(0.25, $sampler->latestLoad());
    }

    public function testProxyModeNeverReadsFilesystem(): void
    {
        // Even pointed at unreadable paths (which is what proxy() does — empty
        // strings), proxy mode consumes only the status/server frames.
        $sampler = HostLoadSampler::forDsn('mysql:host=174.138.179.252;port=3306;dbname=my');

        $sampler->sample(['Threads_running' => '3', 'Threads_connected' => '10'], ['max_connections' => '20']);

        self::assertSame(HostLoadMode::BusyProxy, $sampler->mode());
        self::assertSame([], $sampler->loadWindow(), 'a remote box has no local loadavg to show');
        self::assertSame([0.5], $sampler->busyWindow(), 'max(3/10, 10/20)');
    }

    public function testProxyFallsBackToCapacityShareWhenIdle(): void
    {
        $sampler = HostLoadSampler::proxy();

        $sampler->sample(['Threads_running' => '0', 'Threads_connected' => '151'], ['max_connections' => '151']);

        self::assertSame([1.0], $sampler->busyWindow());
    }

    public function testProxyWithoutSamplesRecordsNothing(): void
    {
        $sampler = HostLoadSampler::proxy();

        $sampler->sample(null, null);
        $sampler->sample([], null);

        self::assertSame([], $sampler->busyWindow());
    }

    /**
     * DSN boundary parse picks the honest source for each host spelling.
     *
     * @return array<string, array{0: string, 1: HostLoadMode}>
     */
    public static function dsnShapes(): array
    {
        return [
            'loopback name' => ['mysql:host=localhost;dbname=my', HostLoadMode::HostProc],
            'ipv4 loopback' => ['mysql:host=127.0.0.1;dbname=my', HostLoadMode::HostProc],
            'ipv6 loopback' => ['mysql:host=::1;dbname=my', HostLoadMode::HostProc],
            'upper case' => ['mysql:host=LOCALHOST;dbname=my', HostLoadMode::HostProc],
            'unix socket' => ['mysql:unix_socket=/var/run/mysqld.sock;dbname=my', HostLoadMode::HostProc],
            'remote tcp' => ['mysql:host=10.0.0.5;port=3306', HostLoadMode::BusyProxy],
            'empty dsn' => ['', HostLoadMode::BusyProxy],
            'host-less dsn' => ['mysql:dbname=my', HostLoadMode::BusyProxy],
        ];
    }

    /**
     * @dataProvider dsnShapes
     */
    public function testForDsnSelectsMode(string $dsn, HostLoadMode $expected): void
    {
        self::assertSame($expected, HostLoadSampler::forDsn($dsn)->mode());
    }

    private function statFixture(): string
    {
        return $this->write('stat', $this->statText(100, 10, 20, 800, 60, 5, 5, 10));
    }

    /**
     * Aggregate cpu row plus a per-cpu row that must be ignored by the parser.
     */
    private function statText(int $user, int $nice, int $system, int $idle, int $iowait, int $irq, int $softirq, int $steal): string
    {
        return sprintf(
            "cpu  %d %d %d %d %d %d %d %d 0 0\ncpu0 %d %d %d %d %d %d %d %d 0 0\nintr 0\n",
            $user, $nice, $system, $idle, $iowait, $irq, $softirq, $steal,
            $user, $nice, $system, $idle, $iowait, $irq, $softirq, $steal,
        );
    }

    private function write(string $stem, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cq-hostload-' . $stem);
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;

        return $path;
    }
}
