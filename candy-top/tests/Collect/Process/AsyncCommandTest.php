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
use SugarCraft\Top\Collect\Process\AbsoluteSearchPath;
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
        AsyncCommand::withSpawnFactory(null);
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

    /**
     * The child of a collector is not the user's shell: a planted credential
     * must not cross the fork, and the roster the sensor actually needs
     * (PATH, HOME, the GPU visibility keys) must.
     */
    public function testTheChildGetsARebuiltEnvironmentNotTheParentOne(): void
    {
        $token = getenv('CANDY_TOP_PLANTED_TOKEN');
        $device = getenv('CUDA_VISIBLE_DEVICES');
        putenv('CANDY_TOP_PLANTED_TOKEN=sk-not-for-the-child');
        putenv('CUDA_VISIBLE_DEVICES=GPU-deadbeef');
        try {
            $script = $this->script(
                'printf \'path=%s\\nhome=%s\\ndevice=%s\\ntoken=[%s]\' "$PATH" "$HOME" "$CUDA_VISIBLE_DEVICES" "${CANDY_TOP_PLANTED_TOKEN-}"'
            );
            $result = AsyncCommand::run([$script]);
            $this->assertSame(CommandOutcome::Ok, $result->outcome, $result->stdout);
            $this->assertStringNotContainsString('sk-not-for-the-child', $result->stdout, 'a credential reached a sensor child');
            $this->assertStringContainsString('token=[]', $result->stdout);
            $lines = [];
            foreach (explode("\n", $result->stdout) as $line) {
                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                $lines[$k] = (string) $v;
            }
            $this->assertNotSame('', $lines['path'] ?? '', 'PATH must survive: the tool finds its own helpers with it');
            $this->assertSame('GPU-deadbeef', $lines['device'] ?? '', 'the GPU visibility key is part of the roster');
        } finally {
            self::restoreEnv('CANDY_TOP_PLANTED_TOKEN', $token);
            self::restoreEnv('CUDA_VISIBLE_DEVICES', $device);
        }
    }

    /**
     * The narrowing is the 5th proc_open argument. Omitting it re-enables the
     * whole-environment inheritance silently, so the call shape is pinned too.
     */
    public function testTheSpawnRebuildsTheEnvironmentRatherThanOmittingIt(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Collect/Process/AsyncCommand.php');
        $this->assertMatchesRegularExpression(
            '/proc_open\(\$argv,.*?\$pipes, null, self::childEnv\(\$env\)\)/s',
            $source,
            'proc_open without an environment inherits every parent variable',
        );
        $this->assertStringContainsString("'PATH'", $source);
        $this->assertStringNotContainsString("API_KEY", $source, 'the roster is allow-listed, not exhaustive');
    }

    private static function restoreEnv(string $key, string|false $value): void
    {
        $value === false ? putenv($key) : putenv($key . '=' . $value);
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
        // 0.3 s deadline + kill + reap, bounded with CI headroom (candy-top-053);
        // a never-timeout child sleeps 30 s, so the arm separation survives.
        $this->assertLessThan(3.0, $elapsed);
    }

    public function testARunawayChildIsKilledAtItsOutputCap(): void
    {
        $flood = $this->script('exec cat /dev/zero');
        $r = AsyncCommand::run([$flood], 5.0, 100_000);
        $this->assertSame(CommandOutcome::TooLarge, $r->outcome);
        $this->assertSame('', $r->stdout, 'nothing kept of a runaway');
        // killed at the cap, not at the deadline — 3 s still separates from
        // the 5 s deadline arm while leaving CI headroom (candy-top-053)
        $this->assertLessThan(3.0, $r->seconds);
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

    public function testTheSpawnGateRefusesABareArgvZero(): void
    {
        // proc_open's argv-array form hands argv[0] to execvp, which searches
        // PATH — the very resolution the collectors' absolute sweeps exist to
        // do themselves. The gate is the last line: a bare name must report
        // Absent even when one sits on PATH.
        $tool = 'top-gate-probe-' . bin2hex(random_bytes(4));
        $this->script('printf bare-ok', $tool);
        $path = (string) getenv('PATH');
        putenv('PATH=' . $this->dir . PATH_SEPARATOR . $path);
        try {
            $this->assertSame(CommandOutcome::Absent, AsyncCommand::run([$tool])->outcome, 'a bare name on PATH never spawns');
            $this->assertSame(CommandOutcome::Absent, AsyncCommand::run([])->outcome, 'an empty argv never spawns');
            $this->assertSame(CommandOutcome::Ok, AsyncCommand::run([$this->script('printf abs-ok')])->outcome, 'an absolute path still runs');
            $loop = new StreamSelectLoop();
            [$result] = self::await($loop, AsyncCommand::launch([$tool], $loop));
            $this->assertInstanceOf(CommandResult::class, $result);
            $this->assertSame(CommandOutcome::Absent, $result->outcome, 'launch() is gated the same way');
        } finally {
            putenv('PATH=' . $path);
        }
    }

    public function testTheResolverKeepsOnlyRealAbsoluteDirectories(): void
    {
        mkdir($this->dir . '/sub');
        $real = (string) realpath($this->dir);
        $gone = $this->dir . '/gone-' . bin2hex(random_bytes(3));
        $dirs = AbsoluteSearchPath::directories(implode(PATH_SEPARATOR, [
            $this->dir . '/sub', '', 'relative', '/nonexistent-candy-top-' . bin2hex(random_bytes(3)), $this->dir, $real . '/sub', $gone,
        ]));
        $this->assertSame([$real . '/sub', $real], $dirs, 'PATH order kept, relative and missing refused, duplicates collapsed');
        $this->assertNotEmpty(AbsoluteSearchPath::directories(), 'the real environment yields at least one directory');
        rmdir($this->dir . '/sub');
    }

    public function testDriveAbsoluteEntriesAreAcceptedOnlyOnWindows(): void
    {
        // candy-top-038: the gate is OS-based, not pattern-based — a Linux
        // "C:\dir" is a RELATIVE name (exactly the hijack the doctrine
        // refuses), while the same entry on Windows is drive-absolute.
        $base = sys_get_temp_dir() . '/candy-top-drive-' . bin2hex(random_bytes(4));
        $win = $base . '/C:\\Windows\\System32';
        mkdir($win, 0777, true);
        $oldCwd = getcwd();
        chdir($base);
        try {
            $accepted = AbsoluteSearchPath::directories('C:\\Windows\\System32', 'Windows');
            $this->assertNotEmpty($accepted, 'forced-Windows seam accepts the drive entry');
            $this->assertSame([], AbsoluteSearchPath::directories('C:\\Windows\\System32'), 'Linux refuses the same string as relative');
        } finally {
            chdir($oldCwd);
            rmdir($win); // one literal component on Linux: "C:\Windows\System32"
            rmdir($base);
        }
    }

    /** S-P18: OS-level death proof — the pid is gone (ESRCH) once reaped. */
    private static function awaitGone(int $pid, float $limit = 5.0): bool
    {
        $until = hrtime(true) / 1e9 + $limit;
        while (hrtime(true) / 1e9 < $until) {
            if (!posix_kill($pid, 0)) {
                return true;
            }
            usleep(10000);
        }

        return false;
    }

    public function testTheFactorySeesTheGatedArgvAndTheAllowlistedEnvironment(): void
    {
        $seen = null;
        $calls = 0;
        $factory = static function (array $argv, array $env) use (&$seen, &$calls) {
            ++$calls;
            $seen = [$argv, $env];

            return null; // the Absent path, exercised by the same seam
        };
        AsyncCommand::withSpawnFactory($factory);
        $script = $this->script('printf never-runs');
        $token = getenv('CANDY_TOP_PLANTED_TOKEN');
        putenv('CANDY_TOP_PLANTED_TOKEN=sk-never-for-the-child');
        try {
            // The gate stands BEFORE the seam: a bare name never reaches it.
            $this->assertSame(CommandOutcome::Absent, AsyncCommand::run(['bare-tool'])->outcome);
            $this->assertSame(0, $calls, 'the argv gate must run before the factory');

            $this->assertSame(CommandOutcome::Absent, AsyncCommand::run([$script, 'a1'])->outcome);
            $this->assertSame(1, $calls);
            $this->assertSame([$script, 'a1'], $seen[0]);
            $this->assertArrayHasKey('PATH', $seen[1]);
            $this->assertArrayNotHasKey('CANDY_TOP_PLANTED_TOKEN', $seen[1], 'a planted credential crossed the seam');
            $this->assertSame(0, AsyncCommand::liveCount(), 'a refused spawn registers nothing');
        } finally {
            $token === false ? putenv('CANDY_TOP_PLANTED_TOKEN') : putenv('CANDY_TOP_PLANTED_TOKEN=' . $token);
        }
    }

    public function testAFactoryThatReturnsGarbageFailsFast(): void
    {
        AsyncCommand::withSpawnFactory(static fn () => 'not-a-child');
        try {
            AsyncCommand::run([$this->script('printf x')]);
            $this->fail('a malformed factory return must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('[process, pipes]', $e->getMessage());
        }
    }

    public function testTheKillLadderRunsOverTheInjectedChild(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('the death proof needs posix_kill');
        }
        $pid = 0;
        $factory = static function (array $argv, array $env) use (&$pid) {
            $devnull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $pipes = [];
            $process = proc_open($argv, [0 => ['file', $devnull, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $devnull, 'w']], $pipes, null, $env);
            if (!is_resource($process)) {
                return null;
            }
            $pid = (int) proc_get_status($process)['pid'];

            return [$process, $pipes];
        };
        AsyncCommand::withSpawnFactory($factory);

        $sleeper = $this->script('exec sleep 30');
        $timeout = AsyncCommand::run([$sleeper], 0.15);
        $this->assertSame(CommandOutcome::Timeout, $timeout->outcome);
        $this->assertTrue(self::awaitGone($pid), 'the timeout arm never reached SIGKILL');
        $this->assertSame(0, AsyncCommand::liveCount(), 'a killed-and-died child leaves the registry');

        $flood = $this->script("printf 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'");
        $large = AsyncCommand::run([$flood], 5.0, 16);
        $this->assertSame(CommandOutcome::TooLarge, $large->outcome);
        $this->assertTrue(self::awaitGone($pid), 'the overflow arm never reached SIGKILL');
        $this->assertSame(0, AsyncCommand::liveCount());

        // And through the seam the ordinary outcomes keep their meaning.
        $this->assertSame(CommandOutcome::Ok, AsyncCommand::run([$this->script('printf fine')])->outcome);
        $failed = AsyncCommand::run([$this->script('exit 7')]);
        $this->assertSame(CommandOutcome::Failed, $failed->outcome);
        $this->assertSame(7, $failed->exit);
    }
}
