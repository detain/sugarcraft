<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Gpu;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\Gpu;
use SugarCraft\Top\Collect\Gpu\Accelerators;
use SugarCraft\Top\Collect\Gpu\Settled;
use SugarCraft\Top\Collect\GpuOutcome;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\Gpu\GpuDemand;
use SugarCraft\Top\Panel\Gpu\GpuFeed;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeGpu;
use SugarCraft\Top\Source\Source;

/**
 * The shared accelerator feed: one sample per round however many
 * consumers ask, the union of their demands, nothing without a consumer,
 * and — over a loop-driven nvidia-smi — waiting vs non-waiting vs fresh
 * requests while a spawn is in flight.
 */
final class GpuFeedTest extends TestCase
{
    private const string DEVICES = "0, 42, 2048, 10240, 61, 220.5, 10, 0, 0, 300, 1500, 9000, 2000, 9500, 30, P2, 4, 16, N/A, GPU-a, Test GPU\n";
    private const string APPS = "4242, GPU-a, 512\n";
    private const string PMON = "# gpu pid type sm mem enc dec fb command\n# Idx # C/G % % % % MB name\n    0 4242 C 37 5 - - 512 python\n";

    private float $now = 100.0;

    /** @var list<list<string>> */
    private array $spawned = [];

    /** @var list<Deferred<array{0: GpuOutcome, 1: string}>> */
    private array $pending = [];

    private bool $deferAll = false;

    /** A live-shaped collector whose nvidia-smi runner is either answered at once or held. */
    private function accelerators(): Accelerators
    {
        $runner = function (array $argv): array|PromiseInterface {
            $this->spawned[] = $argv;
            $answer = match (true) {
                str_starts_with($argv[1], '--query-gpu=') => [GpuOutcome::Ok, self::DEVICES],
                str_starts_with($argv[1], '--query-compute-apps=') => [GpuOutcome::Ok, self::APPS],
                default => [GpuOutcome::Ok, self::PMON],
            };
            if (!$this->deferAll) {
                return $answer;
            }
            $d = new Deferred();
            $this->pending[] = $d;

            return $d->promise()->then(static fn (): array => $answer);
        };
        $gpu = Gpu::new($runner, fn (): float => $this->now, candidates: ['/usr/bin/nvidia-smi']);
        $empty = sys_get_temp_dir() . '/candy-top-feed-' . bin2hex(random_bytes(4));

        return Accelerators::detect(Paths::under($empty), $gpu, fn (): float => $this->now);
    }

    /** Answer the spawns pending now (not the ones their answers start). */
    private function release(): void
    {
        $now = $this->pending;
        $this->pending = [];
        foreach ($now as $d) {
            $d->resolve(null);
        }
    }

    private static function spy(): Source
    {
        return new class () implements Source {
            public int $samples = 0;

            public function sample(): array
            {
                $this->samples++;

                return [new GpuSnapshot([]), $this];
            }
        };
    }

    /** @param PromiseInterface<GpuSnapshot> $p */
    private static function now(PromiseInterface $p): GpuSnapshot
    {
        $v = Settled::value($p);
        self::assertInstanceOf(GpuSnapshot::class, $v);

        return $v;
    }

    private function spawns(string $prefix): int
    {
        return \count(array_filter($this->spawned, static fn (array $a): bool => str_starts_with($a[1], $prefix)));
    }

    public function testOfWrapsASourceOnceAndKeepsAFeed(): void
    {
        $feed = GpuFeed::of(FakeGpu::new());
        $this->assertSame($feed, GpuFeed::of($feed));
        $this->assertInstanceOf(FakeGpu::class, $feed->source());
        $this->assertSame(0, $feed->rounds(), 'building a feed samples nothing');
        $this->assertEquals(new GpuSnapshot([]), $feed->current());
    }

    public function testOneRoundServesEveryConsumerOnce(): void
    {
        $spy = self::spy();
        $feed = GpuFeed::of($spy);
        $d = GpuDemand::new();
        foreach (['cpu', 'gpu', 'proc'] as $c) {
            $feed->request($c, $d, $c !== 'proc');
        }
        $this->assertSame(1, $spy->samples, 'three consumers, one sample');
        foreach (['cpu', 'gpu', 'proc'] as $c) {
            $feed->request($c, $d);
        }
        $this->assertSame(2, $spy->samples, 'the next tick: one more');
        $this->assertSame(2, $feed->rounds());
    }

    public function testABoxOpenedMidTickReusesTheCurrentSnapshot(): void
    {
        $spy = self::spy();
        $feed = GpuFeed::of($spy);
        $feed->request('cpu', GpuDemand::new());
        $feed->request('gpu', GpuDemand::new());
        $this->assertSame(1, $spy->samples);
    }

    public function testASingleConsumerSamplesOnEveryRequest(): void
    {
        $feed = GpuFeed::of(FakeGpu::new(1, 0));
        $a = self::now($feed->request('gpu', GpuDemand::new()));
        $b = self::now($feed->request('gpu', GpuDemand::new()));
        $this->assertNotEquals($a, $b, 'the fake advanced: a box with a feed of its own behaves as before');
    }

    public function testPerProcessRowsFollowTheUnionOfDemands(): void
    {
        $feed = GpuFeed::of(FakeGpu::new());
        $this->assertNull(self::now($feed->request('cpu', GpuDemand::new()))->processes, 'nobody wants processes');

        $feed->request('proc', GpuDemand::new(processes: true), false);
        $s = self::now($feed->request('cpu', GpuDemand::new()));
        $this->assertNotNull($s->processes, 'the proc box asked last round: the union includes processes');
        $this->assertTrue($feed->source()->processesEnabled());
    }

    public function testAConsumerThatStopsAskingDropsOutAfterOneMoreRound(): void
    {
        $feed = GpuFeed::of(FakeGpu::new());
        $feed->request('cpu', GpuDemand::new());
        $feed->request('proc', GpuDemand::new(processes: true), false);
        // The proc box is hidden from here on: only the cpu box asks.
        $this->assertNotNull(self::now($feed->request('cpu', GpuDemand::new()))->processes, 'asked during this round');
        $this->assertNotNull(self::now($feed->request('cpu', GpuDemand::new()))->processes, 'asked during the previous round');
        $this->assertNull(self::now($feed->request('cpu', GpuDemand::new()))->processes, 'dropped out');
        $this->assertFalse($feed->source()->processesEnabled());
    }

    public function testVendorsAreTheUnionOfShownGpus(): void
    {
        $feed = GpuFeed::of(CollectorSource::of($this->accelerators()));
        $feed->request('cpu', GpuDemand::new(['amd']));
        $feed->request('gpu', GpuDemand::new(['intel']));
        $feed->request('cpu', GpuDemand::new(['amd']));
        $collector = $feed->source()->collector();
        $vendors = (new \ReflectionProperty($collector, 'vendors'))->getValue($collector);
        $this->assertEqualsCanonicalizing(['amd', 'intel'], array_map(static fn ($v) => $v->value, $vendors));
        $this->assertSame(0, $this->spawns('--query-gpu='), 'nvidia not shown: never spawned');
    }

    public function testDemandFromConfig(): void
    {
        $config = Config::new()->with('shown_gpus', 'nvidia intel');
        $this->assertSame(['nvidia', 'intel'], GpuDemand::of($config)->vendors);
        $this->assertFalse(GpuDemand::of($config)->processes);
        $this->assertTrue(GpuDemand::of($config, true)->processes);
        $u = GpuDemand::new(['amd'])->union(GpuDemand::new(['amd', 'nvidia'], true));
        $this->assertSame(['amd', 'nvidia'], $u->vendors);
        $this->assertTrue($u->processes);
        $this->assertTrue(GpuDemand::new()->withProcesses()->processes);
    }

    public function testALiveCollectorIsSampledOnceAcrossConsumersAndCadenced(): void
    {
        $feed = GpuFeed::of(CollectorSource::of($this->accelerators()));
        for ($tick = 0; $tick < 6; $tick++) {
            $feed->request('cpu', GpuDemand::new());
            $feed->request('gpu', GpuDemand::new());
            $feed->request('proc', GpuDemand::new(processes: true), false);
            $this->now += 2.0;
        }
        // Tick 0: devices only (the proc box's demand is not known yet); tick 1: its
        // opt-in queries at once; then every 5 s on the 2 s ticks (6 s): ticks 4.
        $this->assertSame(3, $this->spawns('--query-gpu='), 'one nvidia-smi cadence, not three');
        $this->assertSame(2, $this->spawns('pmon'));
        $this->assertSame(2, $this->spawns('--query-compute-apps='));
    }

    public function testWaitingRequestsShareTheSpawnInFlight(): void
    {
        $this->deferAll = true;
        $feed = GpuFeed::of(CollectorSource::of($this->accelerators()));
        $cpu = $feed->request('cpu', GpuDemand::new());
        $gpu = $feed->request('gpu', GpuDemand::new());
        $proc = $feed->request('proc', GpuDemand::new(processes: true), false);

        $this->assertTrue($feed->busy());
        $this->assertFalse(Settled::peek($cpu)[0], 'pending while nvidia-smi runs');
        $this->assertFalse(Settled::peek($gpu)[0]);
        $this->assertTrue(Settled::peek($proc)[0], 'a non-waiting ask gets the previous snapshot at once');
        $this->assertSame([], self::now($proc)->devices);
        $this->assertSame(1, $this->spawns('--query-gpu='));

        $this->release();
        $this->assertFalse($feed->busy());
        $this->assertSame('Test GPU', self::now($cpu)->devices[0]->name);
        $this->assertSame(self::now($cpu), self::now($gpu), 'the same snapshot');
        $this->assertSame('Test GPU', self::now($feed->request('proc', GpuDemand::new(processes: true), false))->devices[0]->name, 'next ask: the landed one');
    }

    public function testAFreshProcessRequestWaitsAndCollectsProcessesAtOnce(): void
    {
        $feed = GpuFeed::of(CollectorSource::of($this->accelerators()));
        $this->assertNull(self::now($feed->request('cpu', GpuDemand::new()))->processes);
        $this->now += 1.0; // devices queried 1 s ago: not due on their own

        $this->deferAll = true;
        $fresh = $feed->request('proc', GpuDemand::new(processes: true), false, true);
        $this->assertFalse(Settled::peek($fresh)[0], 'a fresh ask waits for the spawn');
        $this->release(); // devices
        $this->release(); // compute-apps
        $this->release(); // pmon
        $s = self::now($fresh);
        $this->assertNotNull($s->processes, 'the opt-in queried at once');
        $this->assertSame(37.0, $s->processes[0]->utilization);
    }

    public function testAFreshRequestFollowsARoundInFlightThatLackedProcesses(): void
    {
        $this->deferAll = true;
        $feed = GpuFeed::of(CollectorSource::of($this->accelerators()));
        $feed->request('cpu', GpuDemand::new()); // devices only, in flight
        $fresh = $feed->request('proc', GpuDemand::new(processes: true), false, true);
        $this->release();
        $this->assertFalse(Settled::peek($fresh)[0], 'a second round, with processes, is in flight');
        $this->release();
        $this->release();
        $this->release();
        $this->assertNotNull(self::now($fresh)->processes);
    }

    public function testSourceViewNeverWaits(): void
    {
        $this->deferAll = true;
        $feed = GpuFeed::of(CollectorSource::of($this->accelerators()));
        [$snap, $next] = $feed->sample();
        $this->assertSame($feed, $next);
        $this->assertSame([], $snap->devices, 'pending: the previous (empty) snapshot');
        $this->release();
        $this->assertSame('Test GPU', $feed->current()->devices[0]->name);
    }

    public function testARejectedRoundNobodyWaitsOnIsAFailedSampleNotAnUnhandledRejection(): void
    {
        $collector = new class () {
            /** @var list<Deferred<array<mixed>>> */
            public array $pending = [];

            public function sample(): array
            {
                throw new \LogicException('blocking path unused');
            }

            public function sampleAsync(): PromiseInterface
            {
                $d = new Deferred();
                $this->pending[] = $d;

                return $d->promise();
            }
        };
        $unhandled = [];
        $previous = \React\Promise\set_rejection_handler(static function (\Throwable $e) use (&$unhandled): void {
            $unhandled[] = $e;
        });
        try {
            $feed = GpuFeed::of(CollectorSource::of($collector));
            // A first good round, so there is a device to report unmeasured.
            $first = $feed->request('cpu', GpuDemand::new());
            $collector->pending[0]->resolve([new GpuSnapshot([new \SugarCraft\Top\Collect\GpuDevice(0, 'G', 50.0, 1, 2, 60.0, 10.0)]), $collector]);
            $this->assertSame(50.0, self::now($first)->devices[0]->utilization);

            // The proc box starts the next round and does not wait on it.
            $proc = $feed->request('proc', GpuDemand::new(processes: true), false);
            $proc = $feed->request('proc', GpuDemand::new(processes: true), false);
            $this->assertTrue(Settled::peek($proc)[0]);
            $this->assertTrue($feed->busy());
            $collector->pending[1]->reject(new \RuntimeException('collector bug'));
            unset($proc);
            gc_collect_cycles();

            $this->assertSame([], $unhandled, 'no unhandled rejection reaches stderr');
            $this->assertFalse($feed->busy());
            $this->assertInstanceOf(\RuntimeException::class, $feed->lastError(), 'not swallowed silently');
            $next = self::now($feed->request('cpu', GpuDemand::new()));
            $this->assertCount(1, $next->devices, 'the next consumer sees the device ...');
            $this->assertSame(-1.0, $next->devices[0]->utilization, '... unmeasured, like a failed query');
            $this->assertNull($next->processes);

            // A waiting consumer gets the failed sample too, and a good round clears the error.
            $cpu = $feed->request('cpu', GpuDemand::new());
            $collector->pending[2]->resolve([new GpuSnapshot([]), $collector]);
            $this->assertSame([], self::now($cpu)->devices);
            $this->assertNull($feed->lastError());
        } finally {
            \React\Promise\set_rejection_handler($previous);
        }
    }

    public function testASynchronousSourceThatThrowsIsAFailedSampleToo(): void
    {
        $source = new class () implements Source {
            public function sample(): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $feed = GpuFeed::of($source);
        $this->assertSame([], self::now($feed->request('gpu', GpuDemand::new()))->devices);
        $this->assertInstanceOf(\RuntimeException::class, $feed->lastError());
    }
}
