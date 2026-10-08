<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Gpu;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\ExtUvLoop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\Gpu\SmiProcess;
use SugarCraft\Top\Collect\GpuOutcome;

/**
 * The nvidia-smi child, driven for real against shell scripts: the
 * blocking form and the loop-driven form must agree on every outcome,
 * the loop form must keep the loop turning while the child runs, and no
 * child may be left running or unreaped (killed on timeout, killed by the
 * exit hook).
 */
final class SmiProcessTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candy-top-smi-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        SmiProcess::shutdown();
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @return iterable<string, array{0: \Closure(): LoopInterface}> */
    public static function loops(): iterable
    {
        yield 'stream_select' => [static fn (): LoopInterface => new StreamSelectLoop()];
        if (\function_exists('uv_loop_new')) {
            yield 'ext-uv' => [static fn (): LoopInterface => new ExtUvLoop()];
        }
    }

    private function script(string $body): string
    {
        $file = $this->dir . '/smi-' . bin2hex(random_bytes(3));
        file_put_contents($file, "#!/bin/sh\n" . $body . "\n");
        chmod($file, 0755);

        return $file;
    }

    /**
     * @param PromiseInterface<array{0: GpuOutcome, 1: string}> $promise
     * @return array{0: array{0: GpuOutcome, 1: string}|null, 1: float}
     */
    private static function await(LoopInterface $loop, PromiseInterface $promise, float $limit = 5.0): array
    {
        $result = null;
        $start = hrtime(true);
        $promise->then(static function (array $r) use (&$result, $loop): void {
            $result = $r;
            $loop->stop();
        });
        $guard = $loop->addTimer($limit, static fn () => $loop->stop());
        if ($result === null) {
            $loop->run();
        }
        $loop->cancelTimer($guard);

        return [$result, (hrtime(true) - $start) / 1e9];
    }

    public function testRunReturnsTheChildOutputOnExitZero(): void
    {
        $this->assertSame([GpuOutcome::Ok, "a, b\nc\n"], SmiProcess::run([$this->script("printf 'a, b\\nc\\n'")]));
        $this->assertSame(0, SmiProcess::liveCount(), 'closed in the call');
    }

    public function testRunReadsOutputLargerThanOnePipeBuffer(): void
    {
        [$outcome, $out] = SmiProcess::run([$this->script('head -c 200000 /dev/zero | tr "\\0" x')]);
        $this->assertSame(GpuOutcome::Ok, $outcome);
        $this->assertSame(200000, \strlen($out));
    }

    public function testRunNonZeroExitIsAbsent(): void
    {
        $this->assertSame([GpuOutcome::Absent, ''], SmiProcess::run([$this->script('echo x; exit 3')]));
    }

    public function testRunOfAnUnspawnableBinaryIsAbsent(): void
    {
        $this->assertSame([GpuOutcome::Absent, ''], SmiProcess::run([$this->dir . '/missing']));
        $this->assertSame(0, SmiProcess::liveCount());
    }

    public function testRunKillsAHungChildWithinTheTimeout(): void
    {
        $start = hrtime(true);
        $result = SmiProcess::run([$this->script('exec sleep 30')], 0.3);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertSame([GpuOutcome::Timeout, ''], $result);
        $this->assertLessThan(1.5, $elapsed, 'timeout 0.3 s + REAP_WAIT 0.5 s, far below the child\'s 30 s');
        $this->assertSame(0, SmiProcess::liveCount(), 'SIGKILLed and reaped');
    }

    /** @param \Closure(): LoopInterface $make */
    #[DataProvider('loops')]
    public function testLaunchResolvesWithTheOutputWithoutBlocking(\Closure $make): void
    {
        $loop = $make();
        $promise = SmiProcess::launch([$this->script("sleep 0.3; printf '0, 42, GPU'")], $loop);
        $settled = false;
        $promise->then(static function () use (&$settled): void {
            $settled = true;
        });
        $this->assertFalse($settled, 'launch() returns before the child is done');

        $ticks = 0;
        $tick = $loop->addPeriodicTimer(0.01, static function () use (&$ticks): void {
            $ticks++;
        });
        [$result, $elapsed] = self::await($loop, $promise);
        $loop->cancelTimer($tick);

        $this->assertSame([GpuOutcome::Ok, '0, 42, GPU'], $result);
        $this->assertGreaterThanOrEqual(0.25, $elapsed);
        $this->assertGreaterThan(10, $ticks, 'the loop kept turning while the child ran');
        $this->assertSame(0, SmiProcess::liveCount(), 'reaped');
    }

    /** @param \Closure(): LoopInterface $make */
    #[DataProvider('loops')]
    public function testLaunchNonZeroExitIsAbsent(\Closure $make): void
    {
        $loop = $make();
        [$result] = self::await($loop, SmiProcess::launch([$this->script('echo x; exit 9')], $loop));
        $this->assertSame([GpuOutcome::Absent, ''], $result);
    }

    public function testLaunchOfAnUnspawnableBinaryIsSettledAbsent(): void
    {
        $result = null;
        SmiProcess::launch([$this->dir . '/missing'], new StreamSelectLoop())->then(static function (array $r) use (&$result): void {
            $result = $r;
        });
        $this->assertSame([GpuOutcome::Absent, ''], $result, 'settled at once, no loop needed');
    }

    /** @param \Closure(): LoopInterface $make */
    #[DataProvider('loops')]
    public function testLaunchKillsAndReapsAHungChild(\Closure $make): void
    {
        $loop = $make();
        [$result, $elapsed] = self::await($loop, SmiProcess::launch([$this->script('exec sleep 30')], $loop, 0.3));

        $this->assertSame([GpuOutcome::Timeout, ''], $result);
        $this->assertLessThan(1.0, $elapsed);
        // The reap poll runs on the loop: let it finish.
        $loop->addTimer(0.5, static fn () => $loop->stop());
        $loop->run();
        $this->assertSame(0, SmiProcess::liveCount(), 'the killed child was reaped');
    }

    public function testShutdownKillsAndReapsEveryLiveChild(): void
    {
        $loop = new StreamSelectLoop();
        SmiProcess::launch([$this->script('exec sleep 30')], $loop);
        SmiProcess::launch([$this->script('exec sleep 30')], $loop);
        $this->assertSame(2, SmiProcess::liveCount());

        $start = hrtime(true);
        SmiProcess::shutdown();
        $this->assertLessThan(1.0, (hrtime(true) - $start) / 1e9);
        $this->assertSame(0, SmiProcess::liveCount(), 'no child outlives the app');
    }
}
