<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Process;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\Ipmi\Ipmi;
use SugarCraft\Top\Collect\Ipmi\IpmiSnapshot;
use SugarCraft\Top\Collect\Ipmi\IpmiState;
use SugarCraft\Top\Collect\Process\AsyncCommand;
use SugarCraft\Top\Collect\Process\CommandOutcome;
use SugarCraft\Top\Collect\Process\CommandResult;

/**
 * The generic helper child behind nvidia-smi and ipmitool, against real
 * shell scripts (SmiProcessTest covers the shared spawn / kill / reap
 * machinery through the GPU adapter). Here: what the generic result
 * keeps — exit codes and stdout on failure — and the ipmi reader driving
 * a fake `ipmitool` on a real loop without ever blocking it.
 */
final class AsyncCommandTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candy-top-cmd-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        AsyncCommand::shutdown();
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function script(string $body, string $name = ''): string
    {
        $file = $this->dir . '/' . ($name !== '' ? $name : 'cmd-' . bin2hex(random_bytes(3)));
        file_put_contents($file, "#!/bin/sh\n" . $body . "\n");
        chmod($file, 0755);

        return $file;
    }

    /** @return array{0: mixed, 1: float} */
    private static function await(LoopInterface $loop, PromiseInterface $promise, float $limit = 10.0): array
    {
        $result = null;
        $start = hrtime(true);
        $promise->then(static function (mixed $r) use (&$result, $loop): void {
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

    public function testRunKeepsStdoutAndTheExitCodeOfAFailure(): void
    {
        $ok = AsyncCommand::run([$this->script('printf ok')]);
        $this->assertSame(CommandOutcome::Ok, $ok->outcome);
        $this->assertSame('ok', $ok->stdout);
        $this->assertSame(0, $ok->exit);
        $this->assertTrue($ok->ok());

        $failed = AsyncCommand::run([$this->script('echo partial; exit 4')]);
        $this->assertSame(CommandOutcome::Failed, $failed->outcome);
        $this->assertSame("partial\n", $failed->stdout);
        $this->assertSame(4, $failed->exit);

        $this->assertSame(CommandOutcome::Absent, AsyncCommand::run([$this->dir . '/missing'])->outcome);
        $timeout = AsyncCommand::run([$this->script('exec sleep 30')], 0.2);
        $this->assertSame(CommandOutcome::Timeout, $timeout->outcome);
        $this->assertGreaterThanOrEqual(0.2, $timeout->seconds);
        $this->assertSame(0, AsyncCommand::liveCount());
    }

    public function testLaunchResolvesTheSameOutcomesOnTheLoop(): void
    {
        $loop = new StreamSelectLoop();
        [$r] = self::await($loop, AsyncCommand::launch([$this->script('echo x; exit 2')], $loop));
        $this->assertInstanceOf(CommandResult::class, $r);
        $this->assertSame(CommandOutcome::Failed, $r->outcome);
        $this->assertSame(2, $r->exit);
        $this->assertSame("x\n", $r->stdout);

        [$r, $elapsed] = self::await($loop, AsyncCommand::launch([$this->script('exec sleep 30')], $loop, 0.3));
        $this->assertSame(CommandOutcome::Timeout, $r->outcome);
        $this->assertLessThan(2.0, $elapsed);
    }

    public function testARunawayChildIsKilledAtItsOutputCap(): void
    {
        $flood = $this->script('exec cat /dev/zero');
        $r = AsyncCommand::run([$flood], 5.0, 100_000);
        $this->assertSame(CommandOutcome::TooLarge, $r->outcome);
        $this->assertSame('', $r->stdout, 'nothing kept of a runaway');
        $this->assertLessThan(2.0, $r->seconds, 'killed at the cap, not at the deadline');
        $this->assertSame(0, AsyncCommand::liveCount());

        $loop = new StreamSelectLoop();
        [$r] = self::await($loop, AsyncCommand::launch([$flood], $loop, 5.0, 100_000));
        $this->assertSame(CommandOutcome::TooLarge, $r->outcome);
        // Resolved at the kill; the reap poll runs on the next loop turns.
        $loop->addTimer(0.3, static fn () => $loop->stop());
        $loop->run();
        $this->assertSame(0, AsyncCommand::liveCount(), 'reaped on the loop');

        $ok = AsyncCommand::run([$this->script('head -c 50000 /dev/zero')], 5.0, 100_000);
        $this->assertSame(CommandOutcome::Ok, $ok->outcome);
        $this->assertSame(50000, \strlen($ok->stdout), 'under the cap: all of it');
        $this->assertSame(4 * 1024 * 1024, AsyncCommand::MAX_OUTPUT);
    }

    public function testLiveForCountsOneProgramsChildren(): void
    {
        $sleeper = $this->script('exec sleep 30');
        $loop = new StreamSelectLoop();
        AsyncCommand::launch([$sleeper], $loop);
        $this->assertSame(1, AsyncCommand::liveFor($sleeper));
        $this->assertSame(0, AsyncCommand::liveFor('/usr/bin/ipmitool'));
        AsyncCommand::shutdown();
        $this->assertSame(0, AsyncCommand::liveFor($sleeper));
    }

    /**
     * A fake ipmitool that answers from the AMI captures and takes 0.4 s
     * over its value walk: the reader's round runs on the loop, the loop
     * keeps turning (a 10 ms timer ticks through the walk), and a second
     * poll while busy spawns nothing.
     */
    public function testTheIpmiReaderNeverBlocksTheLoop(): void
    {
        $fixtures = \dirname(__DIR__, 2) . '/fixtures/ipmi/skynet2';
        $tool = $this->script(<<<SH
            while [ "\$1" = "-I" ] || [ "\$1" = "open" ]; do shift; done
            if [ "\$1" = "-S" ]; then shift 2; fi
            case "\$*" in
              "mc info") cat $fixtures/mc-info.txt ;;
              "fru print 0") cat $fixtures/fru-print-0.txt ;;
              "lan print 1") cat $fixtures/lan-print-1.txt ;;
              "dcmi power reading") cat $fixtures/dcmi-power-reading.txt ;;
              "chassis status") cat $fixtures/chassis-status.txt ;;
              "sel info") cat $fixtures/sel-info.txt ;;
              "sel elist last 1") cat $fixtures/sel-elist-last-5.txt ;;
              "sdr elist") sleep 0.4; cat $fixtures/sdr-elist.txt ;;
              sdr\ dump\ *) printf SDR > "\$3" ;;
              *) exit 1 ;;
            esac
            SH, 'ipmitool');
        touch($this->dir . '/ipmi0');
        $loop = new StreamSelectLoop();
        $ipmi = Ipmi::new(
            static fn (array $argv, float $t): PromiseInterface => AsyncCommand::launch($argv, $loop, $t),
            null,
            $tool,
            [$this->dir . '/ipmi0'],
            static fn (): bool => true,
            $this->dir,
        );
        $ticks = 0;
        $timer = $loop->addPeriodicTimer(0.01, static function () use (&$ticks): void {
            $ticks++;
        });
        $round = $ipmi->poll(10000);
        $this->assertNotNull($round);
        $this->assertNull($ipmi->poll(10000), 'busy');
        [$snapshot, $elapsed] = self::await($loop, $round);
        $loop->cancelTimer($timer);

        $this->assertInstanceOf(IpmiSnapshot::class, $snapshot);
        $this->assertSame(IpmiState::Ready, $snapshot->state);
        $this->assertSame('ESC8000A-E12', $snapshot->info?->machine());
        $this->assertCount(107, $snapshot->sensors ?? []);
        $this->assertGreaterThan(0.4, $elapsed);
        $this->assertGreaterThan(20, $ticks, 'the loop kept turning while ipmitool ran');
        $this->assertSame(0, AsyncCommand::liveCount(), 'every child reaped');
        $this->assertNotSame('', $ipmi->cache());
        $ipmi->__destruct();
    }
}
