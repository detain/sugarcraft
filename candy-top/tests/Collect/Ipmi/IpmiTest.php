<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Ipmi;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\Gpu\Settled;
use SugarCraft\Top\Collect\Ipmi\Ipmi;
use SugarCraft\Top\Collect\Ipmi\IpmiSnapshot;
use SugarCraft\Top\Collect\Ipmi\IpmiState;
use SugarCraft\Top\Collect\Process\CommandOutcome;
use SugarCraft\Top\Collect\Process\CommandResult;

use function React\Promise\resolve;

/**
 * The live reader driven by a scripted runner: availability gates,
 * command order and cadence, one child at a time, the SDR cache, the
 * one-off threshold walk, failure backoff. No ipmitool runs here.
 */
final class IpmiTest extends TestCase
{
    private string $dir = '';

    private float $now = 1000.0;

    /** @var list<string> commands run, args after `-I open` with the cache path as CACHE */
    private array $calls = [];

    /** @var array<string, CommandResult|Deferred|\Closure> */
    private array $script = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candy-top-ipmi-' . bin2hex(random_bytes(5));
        mkdir($this->dir);
        touch($this->dir . '/ipmi0');
        $fixtures = \dirname(__DIR__, 2) . '/fixtures/ipmi/skynet2';
        $ok = static fn (string $f): CommandResult => new CommandResult(CommandOutcome::Ok, (string) file_get_contents("$fixtures/$f"), 0);
        $this->script = [
            'mc info' => $ok('mc-info.txt'),
            'fru print 0' => $ok('fru-print-0.txt'),
            'lan print 1' => $ok('lan-print-1.txt'),
            'dcmi power reading' => $ok('dcmi-power-reading.txt'),
            'chassis status' => $ok('chassis-status.txt'),
            'sel info' => $ok('sel-info.txt'),
            '-S CACHE sel elist last 1' => $ok('sel-elist-last-5.txt'),
            '-S CACHE sdr elist' => $ok('sdr-elist.txt'),
            '-S CACHE sensor list' => $ok('sensor-list.txt'),
            'sdr elist' => $ok('sdr-elist.txt'),
            'sel elist last 1' => $ok('sel-elist-last-5.txt'),
            'sensor list' => $ok('sensor-list.txt'),
            'sdr dump CACHE' => function (array $argv): CommandResult {
                // Reserved by candy-top before the spawn: private from the start.
                clearstatcache();
                $this->assertFileExists(end($argv));
                $this->assertSame('0600', substr(sprintf('%o', fileperms(end($argv))), -4));
                $this->assertSame('0700', substr(sprintf('%o', fileperms(\dirname(end($argv)))), -4));
                file_put_contents(end($argv), 'SDR');

                return new CommandResult(CommandOutcome::Ok, 'Dumping Sensor Data Repository to ...', 0);
            },
        ];
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->dir . '/*/*') ?: []), ...(glob($this->dir . '/*') ?: [])] as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** The private cache dir {@see \SugarCraft\Top\Collect\Ipmi\SdrCache} makes under the test dir. */
    private function cacheDir(): string
    {
        return $this->dir . '/candy-top-' . posix_geteuid();
    }

    private function reader(?\Closure $access = null, ?string $binary = '/usr/bin/ipmitool', ?array $devices = null, ?string $cacheDir = null, ?\Closure $stuck = null): Ipmi
    {
        return Ipmi::new(
            function (array $argv, float $timeout): PromiseInterface {
                $this->assertSame(['-I', 'open'], \array_slice($argv, 1, 2));
                $args = \array_slice($argv, 3);
                $key = preg_replace('#\S*/candy-top-sdr-\S+\.cache#', 'CACHE', implode(' ', $args)) ?? '';
                $this->calls[] = $key;
                $entry = $this->script[$key] ?? new CommandResult(CommandOutcome::Failed, '', 1);
                if ($entry instanceof \Closure) {
                    $entry = $entry($argv);
                }

                return $entry instanceof Deferred ? $entry->promise() : resolve($entry);
            },
            fn (): float => $this->now,
            $binary,
            $devices ?? [$this->dir . '/ipmi0'],
            $access ?? static fn (string $p): bool => true,
            $cacheDir ?? $this->dir,
            $stuck ?? static fn (string $binary): bool => false,
        );
    }

    private static function settle(?PromiseInterface $p): IpmiSnapshot
    {
        self::assertNotNull($p);
        $s = Settled::value($p);
        self::assertInstanceOf(IpmiSnapshot::class, $s);

        return $s;
    }

    public function testTheFirstRoundReadsEverythingOnceInOrder(): void
    {
        $ipmi = $this->reader();
        $s = self::settle($ipmi->poll(10000));
        $this->assertSame([
            'mc info', 'fru print 0', 'lan print 1', 'sdr dump CACHE', 'dcmi power reading',
            'chassis status', 'sel info', '-S CACHE sel elist last 1', '-S CACHE sdr elist',
        ], $this->calls);
        $this->assertSame(IpmiState::Ready, $s->state);
        $this->assertSame('ESC8000A-E12', $s->info?->machine());
        $this->assertSame(1, $s->info?->channel);
        $this->assertSame(2064.0, $s->power?->watts);
        $this->assertCount(107, $s->sensors ?? []);
        $this->assertFalse(($s->sensors ?? [])[0]->hasThresholds(), 'thresholds come in the next round');
        $this->assertStringContainsString('Power off/down', $s->sel?->latest ?? '');
        $this->assertTrue($s->chassis?->powerOn);
        $this->assertFileExists($ipmi->cache());
        clearstatcache();
        $this->assertSame('0600', substr(sprintf('%o', fileperms($ipmi->cache())), -4));
    }

    public function testTheSecondRoundAddsThresholdsThenOnlyPowerUntilTheSlowCadence(): void
    {
        $ipmi = $this->reader();
        self::settle($ipmi->poll(10000));
        $this->calls = [];
        $this->now += 2;
        $s = self::settle($ipmi->poll(10000));
        $this->assertSame(['dcmi power reading', '-S CACHE sensor list'], $this->calls);
        $this->assertNotNull($s->sensors, 'the threshold walk re-merges the last values');
        $merged = array_values(array_filter($s->sensors, static fn ($x) => $x->name === '+12V'))[0];
        $this->assertSame(13.804, $merged->ucr);

        $this->calls = [];
        $this->now += 2;
        $s = self::settle($ipmi->poll(10000));
        $this->assertSame(['dcmi power reading'], $this->calls);
        $this->assertNull($s->sensors);
        $this->assertNull($s->chassis);

        $this->calls = [];
        $this->now += 10;
        $s = self::settle($ipmi->poll(10000));
        $this->assertSame(['dcmi power reading', 'chassis status', 'sel info', '-S CACHE sdr elist'], $this->calls, 'SEL unchanged: no elist of it');
        $this->assertSame(13.804, array_values(array_filter($s->sensors ?? [], static fn ($x) => $x->name === '+12V'))[0]->ucr, 'thresholds stick');
        $this->assertStringContainsString('Power off/down', $s->sel?->latest ?? '', 'the latest entry is remembered');
    }

    public function testOnlyOneChildAtATime(): void
    {
        $slow = new Deferred();
        $this->script['dcmi power reading'] = $slow;
        $ipmi = $this->reader();
        $first = $ipmi->poll(10000);
        $this->assertNotNull($first);
        [$done] = Settled::peek($first);
        $this->assertFalse($done);
        $this->assertTrue($ipmi->busy());
        $before = $this->calls;
        $this->assertNull($ipmi->poll(10000), 'a round is in flight');
        $this->assertSame($before, $this->calls, 'nothing new spawned');
        $this->assertSame('dcmi power reading', end($this->calls), 'the chain waits on the pending child');

        $slow->resolve(new CommandResult(CommandOutcome::Ok, (string) file_get_contents(\dirname(__DIR__, 2) . '/fixtures/ipmi/skynet2/dcmi-power-reading.txt'), 0));
        [$done, $s] = Settled::peek($first);
        $this->assertTrue($done);
        $this->assertSame(IpmiState::Ready, $s->state);
        $this->assertFalse($ipmi->busy());
    }

    public function testMissingToolDeviceOrAccessNeverSpawns(): void
    {
        $noTool = Ipmi::new(fn () => $this->fail('spawned'), fn (): float => $this->now, null, [$this->dir . '/ipmi0'], static fn (): bool => true, $this->dir);
        $saved = getenv('PATH');
        putenv('PATH=' . $this->dir);
        try {
            $ref = new \ReflectionProperty(Ipmi::class, 'binaryResolved');
            $ref->setValue($noTool, true); // SEARCH dirs may hold a real ipmitool on this host; pin "not found"
            $s = self::settle($noTool->poll(1000));
        } finally {
            putenv('PATH=' . $saved);
        }
        $this->assertSame(IpmiState::NoTool, $s->state);

        $s = self::settle($this->reader(devices: [$this->dir . '/nope', $this->dir . '/nope2'])->poll(1000));
        $this->assertSame(IpmiState::NoDevice, $s->state);
        $this->assertSame($this->dir . '/nope', $s->device);

        $ipmi = $this->reader(static fn (string $p): bool => false);
        $s = self::settle($ipmi->poll(1000));
        $this->assertSame(IpmiState::NoAccess, $s->state);
        $this->assertSame($this->dir . '/ipmi0', $s->device);
        $this->assertSame([], $this->calls);
        $this->assertSame($this->now + Ipmi::UNAVAILABLE_RETRY, $ipmi->retryAt(), 'stops polling: next check after the backoff');
    }

    public function testAHungBmcIsKilledAndBacksOffExponentially(): void
    {
        $this->script['-S CACHE sdr elist'] = new CommandResult(CommandOutcome::Timeout);
        $ipmi = $this->reader();
        $s = self::settle($ipmi->poll(10000));
        $this->assertSame(IpmiState::TimedOut, $s->state);
        $this->assertSame($this->now + Ipmi::BACKOFF_MIN, $ipmi->retryAt());

        $this->calls = [];
        $this->now += 5;
        $this->assertSame(IpmiState::TimedOut, self::settle($ipmi->poll(10000))->state);
        $this->assertSame([], $this->calls, 'backing off: no spawn');

        $this->now += 11;
        self::settle($ipmi->poll(10000));
        $this->assertSame($this->now + 2 * Ipmi::BACKOFF_MIN, $ipmi->retryAt(), 'doubled');
        $this->assertSame(['dcmi power reading', 'chassis status', 'sel info', '-S CACHE sdr elist'], $this->calls, 'static info and the SEL entry kept from the first round');

        unset($this->script['-S CACHE sdr elist']);
        $this->script['-S CACHE sdr elist'] = new CommandResult(CommandOutcome::Ok, (string) file_get_contents(\dirname(__DIR__, 2) . '/fixtures/ipmi/skynet2/sdr-elist.txt'), 0);
        $this->now += 31;
        $this->assertSame(IpmiState::Ready, self::settle($ipmi->poll(10000))->state);
        $this->assertSame(0.0, $ipmi->retryAt(), 'recovered');
    }

    public function testABmcThatRefusesMcInfoIsNotResponding(): void
    {
        $this->script['mc info'] = new CommandResult(CommandOutcome::Failed, '', 1);
        $s = self::settle($this->reader()->poll(10000));
        $this->assertSame(IpmiState::NoResponse, $s->state);
        $this->assertSame(['mc info'], $this->calls);
    }

    public function testLanProbingStopsAtTheFirstChannelWithAnAddress(): void
    {
        unset($this->script['lan print 1']);
        $this->script['lan print 2'] = new CommandResult(CommandOutcome::Ok, "IP Address : 0.0.0.0\n", 0);
        $this->script['lan print 3'] = new CommandResult(CommandOutcome::Ok, "IP Address : 192.0.2.77\n", 0);
        $s = self::settle($this->reader()->poll(10000));
        $this->assertSame(['lan print 1', 'lan print 2', 'lan print 3'], \array_slice($this->calls, 2, 3));
        $this->assertSame('192.0.2.77', $s->info?->bmcIp);
        $this->assertSame(3, $s->info?->channel);
    }

    public function testNoChannelWithAnAddressKeepsTheFirstThatAnswered(): void
    {
        $this->script['lan print 1'] = new CommandResult(CommandOutcome::Ok, "IP Address : 0.0.0.0\n", 0);
        $s = self::settle($this->reader()->poll(10000));
        $this->assertSame('lan print 8', $this->calls[9], 'probed 1..8');
        $this->assertSame(1, $s->info?->channel);
        $this->assertSame('', $s->info?->bmcIp);
    }

    public function testDcmiIsDroppedAfterRepeatedRefusals(): void
    {
        $this->script['dcmi power reading'] = new CommandResult(CommandOutcome::Failed, '', 1);
        $ipmi = $this->reader();
        for ($i = 0; $i < Ipmi::DCMI_STRIKES; $i++) {
            $this->assertSame(IpmiState::Ready, self::settle($ipmi->poll(10000))->state, 'a BMC without DCMI is still readable');
            $this->now += 1;
        }
        $this->calls = [];
        self::settle($ipmi->poll(10000));
        $this->assertNotContains('dcmi power reading', $this->calls);
    }

    public function testWithoutAWritableCacheDirThePlainWalkIsUsed(): void
    {
        $ipmi = $this->reader(cacheDir: $this->dir . '/missing');
        self::settle($ipmi->poll(10000));
        $this->assertNotContains('sdr dump CACHE', $this->calls);
        $this->assertContains('sdr elist', $this->calls);
        $this->assertContains('sel elist last 1', $this->calls);
        $this->assertSame('', $ipmi->cache());
    }

    public function testAFailedDumpFallsBackAndLeavesNoFile(): void
    {
        $this->script['sdr dump CACHE'] = new CommandResult(CommandOutcome::Failed, '', 1);
        $ipmi = $this->reader();
        self::settle($ipmi->poll(10000));
        $this->assertSame('', $ipmi->cache());
        $this->assertSame([], glob($this->cacheDir() . '/candy-top-sdr-*') ?: [], 'the reserved file is released');
        $this->assertContains('sdr elist', $this->calls);
    }

    public function testAKilledDumpLeavesNoFileEither(): void
    {
        $this->script['sdr dump CACHE'] = new CommandResult(CommandOutcome::Timeout);
        self::settle($this->reader()->poll(10000));
        $this->assertSame([], glob($this->cacheDir() . '/candy-top-sdr-*') ?: []);
    }

    public function testStaleCachesOfDeadPidsAreSweptAtStart(): void
    {
        mkdir($this->cacheDir(), 0700);
        $stale = $this->cacheDir() . '/candy-top-sdr-2147483646-0badc0de.cache';
        touch($stale);
        self::settle($this->reader()->poll(10000));
        $this->assertFileDoesNotExist($stale);
    }

    public function testAStuckEarlierChildBacksOffWithoutSpawning(): void
    {
        $stuck = true;
        $ipmi = $this->reader(stuck: static function (string $binary) use (&$stuck): bool {
            return $stuck;
        });
        $s = self::settle($ipmi->poll(10000));
        $this->assertSame(IpmiState::Stuck, $s->state);
        $this->assertSame([], $this->calls, 'never a second ipmitool behind one stuck in the driver');
        $this->assertSame($this->now + Ipmi::BACKOFF_MIN, $ipmi->retryAt());
        $stuck = false;
        $this->now += Ipmi::BACKOFF_MIN;
        $this->assertSame(IpmiState::Ready, self::settle($ipmi->poll(10000))->state, 'reaped: back to work');
    }

    public function testARunawayOutputEndsTheRound(): void
    {
        $this->script['-S CACHE sdr elist'] = new CommandResult(CommandOutcome::TooLarge);
        $this->assertSame(IpmiState::NoResponse, self::settle($this->reader()->poll(10000))->state);
    }

    public function testTheSnapshotAnnouncesTheThresholdWalk(): void
    {
        $ipmi = $this->reader();
        $this->assertTrue(self::settle($ipmi->poll(10000))->thresholdsNext, 'values in, thresholds next');
        $this->now += 2;
        $this->assertFalse(self::settle($ipmi->poll(10000))->thresholdsNext, 'walk done');
    }

    public function testTheCacheIsRemovedWithTheReader(): void
    {
        $ipmi = $this->reader();
        self::settle($ipmi->poll(10000));
        $file = $ipmi->cache();
        $this->assertFileExists($file);
        $ipmi->__destruct();
        $this->assertFileDoesNotExist($file);
    }

    public function testAnEmptyValueWalkIsNoResponse(): void
    {
        $this->script['-S CACHE sdr elist'] = new CommandResult(CommandOutcome::Ok, "\n", 0);
        $this->assertSame(IpmiState::NoResponse, self::settle($this->reader()->poll(10000))->state);
    }

    public function testAFailedThresholdWalkIsRetriedLaterWithoutFailingTheRound(): void
    {
        $this->script['-S CACHE sensor list'] = new CommandResult(CommandOutcome::Timeout);
        $ipmi = $this->reader();
        self::settle($ipmi->poll(10000));
        $this->now += 2;
        $this->assertSame(IpmiState::Ready, self::settle($ipmi->poll(10000))->state);
        $this->calls = [];
        $this->now += 2;
        self::settle($ipmi->poll(10000));
        $this->assertNotContains('-S CACHE sensor list', $this->calls, 'not before THRESHOLD_RETRY');
        $this->now += Ipmi::THRESHOLD_RETRY;
        $this->calls = [];
        self::settle($ipmi->poll(10000));
        $this->assertContains('-S CACHE sensor list', $this->calls);
    }

    public function testSampleIsOneBlockingRound(): void
    {
        $ipmi = $this->reader();
        [$snapshot, $next] = $ipmi->sample();
        $this->assertInstanceOf(IpmiSnapshot::class, $snapshot);
        $this->assertSame($ipmi, $next);
        $this->assertSame($snapshot, $ipmi->last());
    }

    public function testFindLooksOnPathThenInSbin(): void
    {
        file_put_contents($this->dir . '/fake-tool', "#!/bin/sh\n");
        chmod($this->dir . '/fake-tool', 0755);
        $saved = getenv('PATH');
        putenv('PATH=' . $this->dir);
        try {
            $this->assertSame($this->dir . '/fake-tool', Ipmi::find('fake-tool'));
            $this->assertNull(Ipmi::find('surely-not-a-tool-' . bin2hex(random_bytes(3))));
        } finally {
            putenv('PATH=' . $saved);
        }
    }
}
