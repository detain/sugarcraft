<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Top\Collect\CpuSnapshot;
use SugarCraft\Top\Collect\FreeBsd\Cpu;
use SugarCraft\Top\Collect\FreeBsd\Freq;
use SugarCraft\Top\Collect\FreeBsd\Memory;
use SugarCraft\Top\Collect\FreeBsd\ReplayProbe;
use SugarCraft\Top\Collect\FreeBsd\Temp;
use SugarCraft\Top\Collect\FreqMode;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\CpuPanel;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Samples;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\DeferredProbe;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;

/**
 * S-P02: the replay pump gives the blocking FreeBSD collectors a
 * loop-driven sample() with no behavioural fork. Parity with the blocking
 * result, zero blocking child waits, and a loop that keeps turning while a
 * slow child is pending.
 */
final class ReplayProbeTest extends TestCase
{
    /** @return mixed the promise's value, driving the loop until it settles */
    private static function await(PromiseInterface $promise, float $limit = 5.0): mixed
    {
        $loop = Loop::get();
        $done = false;
        $value = null;
        $error = null;
        $promise->then(static function ($v) use (&$done, &$value, $loop): void {
            $done = true;
            $value = $v;
            $loop->stop();
        }, static function ($e) use (&$done, &$error, $loop): void {
            $done = true;
            $error = $e;
            $loop->stop();
        });
        $guard = $loop->addTimer($limit, static fn () => $loop->stop());
        if (!$done) {
            $loop->run();
        }
        $loop->cancelTimer($guard);
        if ($error instanceof \Throwable) {
            throw $error;
        }
        self::assertTrue($done, 'the promise settled inside ' . $limit . 's');

        return $value;
    }

    private static function fixture(): FixtureProbe
    {
        return new FixtureProbe(
            FixtureProbe::fixture('sysctl-reference.txt'),
            ['swapinfo' => FixtureProbe::fixture('swapinfo-k.txt')],
            1000.0,
            1_700_000_000.0,
        );
    }

    public function testCpuSampleMatchesTheBlockingResultWithoutBlocking(): void
    {
        $blocking = Cpu::new(self::fixture())->sample();

        $slow = new DeferredProbe(self::fixture());
        $async = Cpu::new(ReplayProbe::over($slow));
        $this->assertTrue($async->asyncSampling());
        [$snapshot, $next] = self::await($async->sampleAsync());

        $this->assertInstanceOf(CpuSnapshot::class, $snapshot);
        $this->assertEquals($blocking[0], $snapshot, 'same snapshot as the blocking sample');
        $this->assertSame(0, $slow->blocked, 'no child was waited on from sample()');
        $this->assertNotEmpty($slow->launched);
        $this->assertTrue($next->asyncSampling(), 'the next collector keeps the async probe');
    }

    public function testMemoryTempAndFreqAdoptThePump(): void
    {
        foreach ([
            Memory::new(...),
            Temp::new(...),
            static fn (ReplayProbe $p) => Freq::new($p, FreqMode::First, false),
        ] as $make) {
            $slow = new DeferredProbe(self::fixture());
            $collector = $make(ReplayProbe::over($slow));
            [$snapshot] = self::await($collector->sampleAsync());
            $this->assertIsObject($snapshot);
            $this->assertSame(0, $slow->blocked, $collector::class . ' waited on a child');
        }
    }

    public function testDependentReadsSurfaceInLaterRoundsAndLaunchTogether(): void
    {
        $slow = new DeferredProbe(self::fixture());
        $probe = ReplayProbe::over($slow);
        $rounds = 0;
        $sample = static function () use ($probe, &$rounds): array {
            ++$rounds;
            $a = $probe->run(['swapinfo', '-k']);
            $b = $probe->run(['ps', '-ax']);
            // A read that only exists once the first answers are in.
            $c = $a !== null ? $probe->run(['mount', '-p']) : null;

            return [(object) ['a' => $a !== null, 'b' => $b, 'c' => $c], new \stdClass()];
        };

        self::await(ReplayProbe::pump($probe, $sample));

        $this->assertSame(3, $rounds, 'blind pass, the independent pair, then the dependent read');
        $this->assertSame([['swapinfo', '-k'], ['ps', '-ax'], ['mount', '-p']], $slow->launched, 'the independent pair launched in ONE round');
        $this->assertSame(0, $slow->blocked);
    }

    public function testRoundsAreBoundedSoAnEndlessChainStillAnswers(): void
    {
        $slow = new DeferredProbe(self::fixture());
        $probe = ReplayProbe::over($slow);
        $n = 0;
        $sample = static function () use ($probe, &$n): array {
            ++$n;
            $probe->run(['tool', (string) $n]); // a new, never-seen read every pass

            return [new \stdClass(), new \stdClass()];
        };

        self::await(ReplayProbe::pump($probe, $sample));

        $this->assertSame(4, $n, 'MAX_ROUNDS passes, then the last result is returned');
    }

    public function testOutsideAPassTheProbeBlocksLikeBefore(): void
    {
        $slow = new DeferredProbe(self::fixture());
        $probe = ReplayProbe::over($slow);

        $this->assertNotNull($probe->run(['swapinfo', '-k']));
        $this->assertNotSame([], $probe->sysctl(['hw.ncpu']));
        $this->assertSame(2, $slow->blocked, 'startup detection and non-pumped callers keep the blocking path');
    }

    public function testAPlainProbeSamplesSynchronouslyThroughTheSamePump(): void
    {
        $collector = Cpu::new(self::fixture());
        $this->assertFalse($collector->asyncSampling());

        [$snapshot] = self::await($collector->sampleAsync());
        $this->assertEquals($collector->sample()[0], $snapshot);
    }

    public function testCollectorSourceReportsAsyncOnlyOverAReplayProbe(): void
    {
        $this->assertFalse(CollectorSource::of(Cpu::new(self::fixture()))->isAsync());
        $this->assertTrue(CollectorSource::of(Cpu::new(ReplayProbe::over(new DeferredProbe(self::fixture()))))->isAsync());
    }

    public function testTheLoopKeepsTurningWhileASlowChildIsPending(): void
    {
        $slow = new DeferredProbe(self::fixture(), 0.25);
        $source = CollectorSource::of(Cpu::new(ReplayProbe::over($slow)));

        // The "input and render" stand-in: a 10 ms heartbeat. A blocking
        // sample would hold it off for the whole child; the pump lets it fire.
        $beats = 0;
        $loop = Loop::get();
        $beat = $loop->addPeriodicTimer(0.01, static function () use (&$beats): void {
            ++$beats;
        });
        $started = microtime(true);
        self::await($source->sampleAsync());
        $elapsed = microtime(true) - $started;
        $loop->cancelTimer($beat);

        $this->assertGreaterThanOrEqual(0.2, $elapsed, 'the slow child really was pending');
        $this->assertGreaterThan(8, $beats, 'the heartbeat fired through the wait (a blocking sample would give ~0)');
    }

    public function testCpuPanelDeliversItsSampleThroughAnAsyncCmdOnAReplayProbe(): void
    {
        $slow = new DeferredProbe(new FixtureProbe(FixtureProbe::fixture('sysctl-cpufreq.synthetic.txt')));
        $platform = Platform::for('FreeBSD', ReplayProbe::over($slow));
        $panel = CpuPanel::standard(PanelPaint::host(), Config::new(), false, $platform);
        $config = Config::new()->with('show_gpu_info', 'Off')->with('check_temp', false)->with('show_battery', false);

        $msg = ($panel->collect(PanelPaint::context('cpu', $config, null)))();

        $this->assertInstanceOf(AsyncCmd::class, $msg, 'the sample rides the loop, not the update path');
        $this->assertSame(0, $slow->blocked, 'collect() itself waited on nothing');
        $sampled = self::await($msg->promise);
        $this->assertInstanceOf(SampledMsg::class, $sampled);
        $this->assertInstanceOf(Samples::class, $sampled->snapshot);
        $this->assertInstanceOf(CpuSnapshot::class, $sampled->snapshot->get('cpu'));
        $this->assertSame(0, $slow->blocked);
    }
}
