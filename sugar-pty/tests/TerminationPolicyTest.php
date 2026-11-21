<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Pty\Posix\PosixChild;
use SugarCraft\Pty\TerminationPolicy;

/**
 * P1-8a: the teardown policy as a value object, and its apply path against
 * real children. The children trap SIGHUP and announce it on stdout before the
 * test signals them, so a pass proves the SIGKILL rung did the work after the
 * grace period, not a hangup that landed before the trap was installed.
 *
 * Async legs run on their own StreamSelectLoop, never the shared Loop::get(),
 * each behind a safety timer so a ladder that never settles fails instead of
 * hanging.
 */
final class TerminationPolicyTest extends TestCase
{
    private const GRACE = 0.2;
    private const KILL_GRACE = 1.0;
    /** Scheduling headroom on a loaded host; the bound is grace + kill grace + this. */
    private const SLACK = 1.5;

    /** @var list<array{PosixChild, array<int, resource>}> */
    private array $spawned = [];

    /** @var list<int> process groups a test created; killed whole on teardown */
    private array $groups = [];

    protected function tearDown(): void
    {
        foreach ($this->groups as $group) {
            @\posix_kill(-$group, 9);
        }
        $this->groups = [];
        foreach ($this->spawned as [$child, $pipes]) {
            if (!$child->exited()) {
                $child->kill(9);
                $child->wait();
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    \fclose($pipe);
                }
            }
        }
        $this->spawned = [];
    }

    public function testDefaultsAreHangupCoreBudgetsAndGroupReach(): void
    {
        $policy = TerminationPolicy::new();

        $this->assertSame(TerminationPolicy::SIGHUP, $policy->signal);
        $this->assertSame(1, $policy->signal);
        $this->assertSame(BoundedShutdown::TERMINATE_GRACE_SECONDS, $policy->graceSeconds);
        $this->assertSame(BoundedShutdown::KILL_GRACE_SECONDS, $policy->killGraceSeconds);
        $this->assertTrue($policy->groupReach);
        $this->assertEquals(new TerminationPolicy(), $policy);
    }

    public function testWithSettersReturnNewInstancesAndLeaveTheOriginalUntouched(): void
    {
        $base = TerminationPolicy::new();

        $term = $base->withSignal(15);
        $grace = $base->withGraceSeconds(0.5);
        $killGrace = $base->withKillGraceSeconds(2.5);
        $solo = $base->withGroupReach(false);

        foreach ([$term, $grace, $killGrace, $solo] as $derived) {
            $this->assertNotSame($base, $derived);
        }
        $this->assertEquals(TerminationPolicy::new(), $base, 'with*() must never mutate the receiver');

        $this->assertSame(15, $term->signal);
        $this->assertSame(0.5, $grace->graceSeconds);
        $this->assertSame(2.5, $killGrace->killGraceSeconds);
        $this->assertFalse($solo->groupReach);

        // Each setter carries every other field across unchanged.
        $this->assertSame(BoundedShutdown::TERMINATE_GRACE_SECONDS, $term->graceSeconds);
        $this->assertSame(TerminationPolicy::SIGHUP, $grace->signal);
        $this->assertTrue($killGrace->groupReach);
        $this->assertSame(BoundedShutdown::KILL_GRACE_SECONDS, $solo->killGraceSeconds);

        $chained = $base->withSignal(15)->withGraceSeconds(0.0)->withGroupReach(false);
        $this->assertEquals(new TerminationPolicy(15, 0.0, BoundedShutdown::KILL_GRACE_SECONDS, false), $chained);
    }

    /** @return iterable<string, array{float}> */
    public static function badBudgets(): iterable
    {
        yield 'negative' => [-0.1];
        yield 'infinite' => [\INF];
        yield 'not a number' => [\NAN];
    }

    #[DataProvider('badBudgets')]
    public function testABadGraceValueIsRejected(float $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/TerminationPolicy graceSeconds must be a finite number of seconds >= 0/');

        TerminationPolicy::new()->withGraceSeconds($seconds);
    }

    #[DataProvider('badBudgets')]
    public function testABadKillGraceValueIsRejected(float $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/TerminationPolicy killGraceSeconds must be a finite number of seconds >= 0/');

        new TerminationPolicy(killGraceSeconds: $seconds);
    }

    public function testANonPositiveSignalIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/signal must be a signal number from 1 to \\d+; got 0/');

        TerminationPolicy::new()->withSignal(0);
    }

    public function testASignalPastTheHostRangeIsRejectedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/signal must be a signal number from 1 to \\d+; got 200/');

        TerminationPolicy::new()->withSignal(200);
    }

    public function testTheHighestPortableSignalIsAccepted(): void
    {
        // 31 is in range on Linux and Darwin alike (Darwin's NSIG is 32).
        $this->assertSame(31, TerminationPolicy::new()->withSignal(31)->signal);
    }

    public function testTheLibcGroupProbeTellsALiveGroupFromAGoneOne(): void
    {
        [$child, $grandchild] = $this->spawnGroupWithGrandchild();
        $this->requireFfi();

        $this->assertFalse(TerminationPolicy::groupEmptyViaLibc($child->pid()), 'a group with live members is not empty');

        \posix_kill(-$child->pid(), 9);
        $child->wait();
        $deadline = \microtime(true) + 5.0;
        while (!TerminationPolicy::groupEmptyViaLibc($child->pid()) && \microtime(true) < $deadline) {
            \usleep(10_000);
        }

        $this->assertTrue(TerminationPolicy::groupEmptyViaLibc($child->pid()), 'ESRCH must read as an empty group');
        $this->assertFalse(self::liveNonZombie($grandchild));
    }

    /**
     * Without ext-posix, core's group probe answers "empty" outright. The
     * policy must not trust that: a leader that dies on SIGHUP would end the
     * ladder at once and spare a grandchild that ignores it. Run in a PHP
     * whose posix_kill() is disabled, the SIGKILL rung must still reach the
     * group through libc.
     */
    public function testWithoutExtPosixGroupReachStillKillsAGrandchildThatIgnoresHangup(): void
    {
        $this->requirePosix();
        $this->requireFfi();
        if (!\is_executable('/usr/bin/setsid')) {
            $this->markTestSkipped('/usr/bin/setsid is required to give the child its own process group.');
        }

        $script = <<<'PHP'
            require $argv[1];
            if (\function_exists('posix_kill')) {
                echo \json_encode(['error' => 'posix_kill is still defined']);
                exit;
            }
            $proc = \proc_open(
                ['/usr/bin/setsid', '/bin/sh', '-c', '/bin/sh -c \'trap "" HUP; echo ready; exec sleep 30\' & echo $!; wait'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
            $child = new \SugarCraft\Pty\Posix\PosixChild((int) \proc_get_status($proc)['pid'], $proc);
            $grandchild = 0;
            for ($i = 0; $i < 2; $i++) {
                $line = \trim((string) \fgets($pipes[1]));
                if (\ctype_digit($line)) {
                    $grandchild = (int) $line;
                }
            }
            $started = \hrtime(true);
            $gone = \SugarCraft\Pty\TerminationPolicy::new()
                ->withGraceSeconds((float) $argv[2])
                ->withKillGraceSeconds((float) $argv[3])
                ->apply($child);
            echo \json_encode([
                'group' => $child->pid(),
                'grandchild' => $grandchild,
                'gone' => $gone,
                'elapsed' => (\hrtime(true) - $started) / 1e9,
            ]);
            PHP;

        $command = \implode(' ', \array_map('escapeshellarg', [
            \PHP_BINARY, '-d', 'disable_functions=posix_kill', '-r', $script,
            \dirname(__DIR__) . '/vendor/autoload.php', (string) self::GRACE, (string) self::KILL_GRACE,
        ]));
        $output = \shell_exec($command . ' 2>&1 </dev/null');
        $this->assertIsString($output);
        $result = \json_decode($output, true);
        $this->assertIsArray($result, "the posix-less runner printed: {$output}");
        $this->assertArrayNotHasKey('error', $result, (string) ($result['error'] ?? ''));
        $this->groups[] = (int) $result['group'];
        $this->assertGreaterThan(0, $result['grandchild'], 'the runner must report the grandchild pid');

        $this->assertGreaterThanOrEqual(self::GRACE, $result['elapsed'], 'an exited leader must not end the ladder while its group lives');
        $this->assertFalse(self::liveNonZombie((int) $result['grandchild']), 'the SIGKILL rung must reach the group without ext-posix');
        $this->assertTrue($result['gone'], 'apply() must report the killed group as gone without ext-posix');
    }

    /**
     * Without ext-posix, apply() must still read a group left holding only an
     * unreaped zombie as gone, as it does with ext-posix: the libc arm walks
     * /proc on the sync path. The zombie is a group member whose parent (the
     * runner) sits outside the group and never reaps it, the shape a PHP
     * running as PID 1 in a container leaves behind.
     */
    public function testWithoutExtPosixApplyReadsAZombieOnlyGroupAsGone(): void
    {
        $this->requirePosix();
        $this->requireFfi();
        if (!\function_exists('posix_setpgid') || !\is_readable('/proc/self/stat')) {
            $this->markTestSkipped('posix_setpgid() and a readable /proc are required to build a zombie-only group.');
        }

        $script = <<<'PHP'
            require $argv[1];
            if (\function_exists('posix_kill')) {
                echo \json_encode(['error' => 'posix_kill is still defined']);
                exit;
            }
            $io = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
            // The leader: its own group in this session, exits when stdin closes.
            $leaderProc = \proc_open(
                [\PHP_BINARY, '-r', 'posix_setpgid(0, 0); echo "ready\n"; fgets(STDIN);'],
                $io,
                $leaderPipes,
            );
            $leader = new \SugarCraft\Pty\Posix\PosixChild((int) \proc_get_status($leaderProc)['pid'], $leaderProc);
            \fgets($leaderPipes[1]);
            $group = $leader->pid();
            // The zombie: joins the leader's group and exits; its handle is
            // never polled, so it stays unreaped for the rest of the run.
            $zombieProc = \proc_open(
                [\PHP_BINARY, '-r', 'echo posix_setpgid(0, (int) $argv[1]) ? "joined" : "failed";', (string) $group],
                $io,
                $zombiePipes,
            );
            $zombie = (int) \proc_get_status($zombieProc)['pid'];
            $joined = \stream_get_contents($zombiePipes[1]);
            $state = '';
            $deadline = \microtime(true) + 5.0;
            while (\microtime(true) < $deadline) {
                $stat = (string) @\file_get_contents("/proc/{$zombie}/stat");
                if (\preg_match('/^\d+ \(.*\) ([A-Za-z]) \d+ (\d+) /s', $stat, $m) === 1 && $m[1] === 'Z') {
                    $state = $m[1] . ':' . $m[2];
                    break;
                }
                \usleep(10_000);
            }
            \fclose($leaderPipes[0]);
            $leader->wait();
            $started = \hrtime(true);
            $gone = \SugarCraft\Pty\TerminationPolicy::new()
                ->withGraceSeconds((float) $argv[2])
                ->withKillGraceSeconds((float) $argv[3])
                ->apply($leader);
            $elapsed = (\hrtime(true) - $started) / 1e9;
            $libcLive = !\SugarCraft\Pty\TerminationPolicy::groupEmptyViaLibc($group);
            \proc_close($zombieProc);
            echo \json_encode([
                'group' => $group,
                'joined' => $joined,
                'state' => $state,
                'libcLive' => $libcLive,
                'gone' => $gone,
                'elapsed' => $elapsed,
            ]);
            PHP;

        $command = \implode(' ', \array_map('escapeshellarg', [
            \PHP_BINARY, '-d', 'disable_functions=posix_kill', '-r', $script,
            \dirname(__DIR__) . '/vendor/autoload.php', (string) self::GRACE, (string) self::KILL_GRACE,
        ]));
        $output = \shell_exec($command . ' 2>&1 </dev/null');
        $this->assertIsString($output);
        $result = \json_decode($output, true);
        $this->assertIsArray($result, "the posix-less runner printed: {$output}");
        $this->assertArrayNotHasKey('error', $result, (string) ($result['error'] ?? ''));
        $this->groups[] = (int) $result['group'];

        $this->assertSame('joined', $result['joined'], 'the zombie must have joined the leader\'s group');
        $this->assertSame('Z:' . $result['group'], $result['state'], 'the group must hold an unreaped zombie when apply() runs');
        $this->assertTrue($result['libcLive'], 'kill(-pgid, 0) alone must still see the zombie, or this test proves nothing');
        $this->assertTrue($result['gone'], 'a zombie-only group must read as gone without ext-posix');
        $this->assertLessThan(self::GRACE, $result['elapsed'], 'a zombie-only group must be answered at once, with no ladder run');
    }

    public function testApplyKillsAChildThatTrapsHangupWithinTheGraceBound(): void
    {
        $child = $this->spawnHangupTrapper();
        $policy = $this->fastPolicy();

        $started = \hrtime(true);
        $gone = $policy->apply($child);
        $elapsed = (\hrtime(true) - $started) / 1e9;

        $this->assertTrue($gone, 'SIGKILL must land within the kill grace');
        $this->assertTrue($child->exited());
        $this->assertGreaterThanOrEqual(self::GRACE, $elapsed, 'the trapped SIGHUP must not end the child; only SIGKILL after the grace may');
        $this->assertLessThan(self::GRACE + self::KILL_GRACE + self::SLACK, $elapsed);
    }

    public function testApplyEndsAChildThatHonorsHangupBeforeTheGraceRunsOut(): void
    {
        $child = $this->spawnReady('echo ready; exec sleep 30');
        $policy = $this->fastPolicy()->withGraceSeconds(5.0);

        $started = \hrtime(true);
        $this->assertTrue($policy->apply($child));
        $elapsed = (\hrtime(true) - $started) / 1e9;

        $this->assertLessThan(5.0, $elapsed, 'a child that dies on SIGHUP must not wait out the grace');
    }

    public function testApplyOnAnAlreadyExitedChildAnswersAtOnce(): void
    {
        $child = $this->spawnReady('echo ready');
        $child->wait();

        $this->assertTrue($this->fastPolicy()->apply($child));
        $settled = null;
        $this->fastPolicy()->applyAsync($child, new StreamSelectLoop())->then(static function (bool $gone) use (&$settled): void {
            $settled = $gone;
        });
        $this->assertTrue($settled, 'an exited child must resolve without a loop tick');
    }

    public function testGroupReachKillsAGrandchildThatTrapsHangup(): void
    {
        [$child, $grandchild] = $this->spawnGroupWithGrandchild();

        $this->assertTrue($this->fastPolicy()->apply($child));

        $this->assertTrue($child->exited());
        $this->assertTrue(BoundedShutdown::groupEmpty($child->pid()), 'the grandchild must die with the group');
        $this->assertFalse(self::liveNonZombie($grandchild));
    }

    public function testWithoutGroupReachOnlyTheChildIsSignalled(): void
    {
        [$child, $grandchild] = $this->spawnGroupWithGrandchild();

        $this->assertTrue($this->fastPolicy()->withGroupReach(false)->apply($child));

        $this->assertTrue($child->exited());
        $this->assertTrue(self::liveNonZombie($grandchild), 'a leader-only policy must leave the rest of the group alone');
    }

    public function testApplyAsyncKillsAChildThatTrapsHangupOnLoopTimers(): void
    {
        $child = $this->spawnHangupTrapper();
        $loop = new StreamSelectLoop();
        $settled = null;

        $started = \hrtime(true);
        $safety = $loop->addTimer(self::GRACE + self::KILL_GRACE + self::SLACK, static fn () => $loop->stop());
        $this->fastPolicy()->applyAsync($child, $loop)->then(static function (bool $gone) use (&$settled, $loop, $safety): void {
            $settled = $gone;
            $loop->cancelTimer($safety);
        });
        $loop->run();
        $elapsed = (\hrtime(true) - $started) / 1e9;

        $this->assertTrue($settled, 'the async ladder must resolve true once SIGKILL lands');
        $this->assertTrue($child->exited());
        $this->assertGreaterThanOrEqual(self::GRACE, $elapsed);
    }

    public function testCancellingApplyAsyncStopsTheLadder(): void
    {
        $child = $this->spawnHangupTrapper();
        $loop = new StreamSelectLoop();
        $rejected = null;

        $promise = $this->fastPolicy()->applyAsync($child, $loop);
        $promise->then(null, static function (\Throwable $e) use (&$rejected): void {
            $rejected = $e;
        });
        $promise->cancel();

        // No safety timer: with nothing armed run() returns at once, and a
        // leaked ladder timer is itself bounded (it stops after the kill grace)
        // but would cost at least the grace before returning.
        $started = \hrtime(true);
        $loop->run();

        $this->assertInstanceOf(\RuntimeException::class, $rejected);
        $this->assertLessThan(self::GRACE, (\hrtime(true) - $started) / 1e9, 'a cancelled ladder must leave no timer armed');
        $this->assertFalse($child->exited(), 'the trapped SIGHUP was the only signal sent');
    }

    /**
     * A child this uid may not signal (root-owned, say a shell started via
     * sudo) must read as "not gone", never throw: apply() returns false and
     * applyAsync() resolves false, with no exception escaping the loop timer.
     * PID 1 stands in for that child; the handle's proc resource is a live
     * sleep so exited() stays false, and the policy is leader-only so no
     * group signal (kill(-1, ...)) is ever sent.
     */
    public function testARefusedSignalReadsAsNotGoneInsteadOfThrowing(): void
    {
        $this->requirePosix();
        if (!\function_exists('posix_kill') || @\posix_kill(1, 0) || \posix_get_last_error() !== 1) {
            $this->markTestSkipped('needs a PID 1 this uid is refused (EPERM) permission to signal.');
        }

        $proc = \proc_open(['sleep', '30'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($proc);
        try {
            $unsignalable = new PosixChild(1, $proc);
            $this->assertFalse($unsignalable->exited(), 'the stand-in must read as running');
            $policy = $this->fastPolicy()->withGroupReach(false)->withGraceSeconds(0.05)->withKillGraceSeconds(0.1);

            $this->assertFalse($policy->apply($unsignalable), 'a refused signal must read as not gone');

            $loop = new StreamSelectLoop();
            $settled = null;
            $safety = $loop->addTimer(0.15 + self::SLACK, static fn () => $loop->stop());
            $policy->applyAsync($unsignalable, $loop)->then(static function (bool $gone) use (&$settled, $loop, $safety): void {
                $settled = $gone;
                $loop->cancelTimer($safety);
            });
            $loop->run();

            $this->assertFalse($settled, 'the async ladder must resolve false, not throw from its timer');
        } finally {
            \proc_terminate($proc, 9);
            \proc_close($proc);
        }
    }

    private function fastPolicy(): TerminationPolicy
    {
        return TerminationPolicy::new()
            ->withGraceSeconds(self::GRACE)
            ->withKillGraceSeconds(self::KILL_GRACE);
    }

    private function spawnHangupTrapper(): PosixChild
    {
        // `trap "" HUP` sets the disposition to ignore, which survives exec.
        return $this->spawnReady('trap "" HUP; echo ready; exec sleep 30');
    }

    /**
     * Spawn `/bin/sh -c $script` and block until it prints its first line,
     * so every signal the test sends lands after the script's setup.
     *
     * @param list<string> $prefix argv placed before `/bin/sh`
     */
    private function spawnReady(string $script, array $prefix = [], ?string &$firstLine = null): PosixChild
    {
        $this->requirePosix();
        $proc = \proc_open(
            [...$prefix, '/bin/sh', '-c', $script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $status = \proc_get_status($proc);
        $child = new PosixChild((int) $status['pid'], $proc);
        $this->spawned[] = [$child, $pipes];

        $line = \fgets($pipes[1]);
        $this->assertIsString($line, 'the child must announce itself before it is signalled');
        $firstLine = \trim($line);

        return $child;
    }

    /**
     * A session leader (via setsid(1)) that ignores SIGHUP, with a
     * background grandchild in the same group that ignores it too.
     *
     * @return array{PosixChild, int} the leader and the grandchild pid
     */
    private function spawnGroupWithGrandchild(): array
    {
        if (!\is_executable('/usr/bin/setsid')) {
            $this->markTestSkipped('/usr/bin/setsid is required to give the child its own process group.');
        }
        if (!\function_exists('posix_kill') || !\function_exists('posix_getpgid')) {
            $this->markTestSkipped('ext-posix is required to observe the process group.');
        }

        $grandchild = null;
        // proc_open's child is not a group leader, so setsid(1) execs in place
        // and the spawned pid becomes the leader of a fresh group.
        $child = $this->spawnReady('trap "" HUP; sleep 30 & echo $!; wait', ['/usr/bin/setsid'], $grandchild);
        $this->assertSame($child->pid(), \posix_getpgid($child->pid()), 'the child must lead its own group');
        $this->groups[] = $child->pid();
        $this->assertSame($child->pid(), \posix_getpgid((int) $grandchild), 'the grandchild must share the group');

        return [$child, (int) $grandchild];
    }

    private function requireFfi(): void
    {
        if (!\SugarCraft\Pty\Tests\Support\PtyCapability::ffiUsable()) {
            $this->markTestSkipped('ext-ffi is required for the libc group probe.');
        }
    }

    private function requirePosix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('TerminationPolicy::apply() drives POSIX signals.');
        }
        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is not executable on this host.');
        }
    }

    /** Whether $pid answers signals and is not a zombie waiting on its reaper. */
    private static function liveNonZombie(int $pid): bool
    {
        if (!@\posix_kill($pid, 0)) {
            return false;
        }
        $stat = @\file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return true;
        }

        return \preg_match('/^\d+ \(.*\) ([ZX]) /s', $stat) !== 1;
    }
}
