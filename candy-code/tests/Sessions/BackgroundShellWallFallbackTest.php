<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Support\ProcessContainment;

/**
 * C-TOOL-4.h: the no-`timeout` arm of the spawn wrapper carries its own wall.
 *
 * The pins below drive the wrapper through {@see ProcessContainment::spawnSpec}
 * exactly as {@see BackgroundShellRegistry::start()} does, but with a PATH that
 * hides coreutils `timeout`, so the else arm (a sleep-and-group-KILL watchdog
 * subshell) is the code path under test. Three facts must hold together:
 *
 *  - the wall FIRES: a still-running command's whole detached group is gone
 *    when the shortened deadline passes, and the exit file was never stamped —
 *    the honest "signalled shell" shape the class doc-block already promises;
 *  - the wall DISARMS: a command that finishes on its own TERMs the watchdog
 *    from the trailer before stamping, so no sleeping member outlives a
 *    settled shell — the same census the teardown sweeps use reads empty;
 *  - the timeout arm is UNTOUCHED: the parent-independent `exec timeout`
 *    segment is byte-identical to the pre-C-TOOL-4.h wrapper, and the watch
 *    literal occurs exactly once in the string (the shortening step below
 *    depends on that uniqueness, asserted rather than assumed).
 *
 * Liveness is judged only AFTER the reaping call returns — the single-shot
 * `proc_get_status()`-after-pipe-EOF shape is the documented flake family —
 * and the settle deadline POLLS, never sleeps blind.
 */
final class BackgroundShellWallFallbackTest extends TestCase
{
    /** Shortened wall used by the live pins (the shipped figure is 1800). */
    private const WALL_SECONDS = 2;

    private string $dir;

    public static function setUpBeforeClass(): void
    {
        // A PATH with no `timeout` on it, so `command -v timeout` fails in the
        // child and the wrapper takes its fallback arm. Only the binaries the
        // wrapper reaches through PATH are linked; everything else it uses is
        // a shell builtin.
        $bin = self::toolBinDir();
        if (!@mkdir($bin, 0o700) && !is_dir($bin)) {
            self::markTestSkipped('cannot build a tool bin');
        }
        foreach (['bash', 'sh', 'sleep', 'cat'] as $tool) {
            $found = trim((string) shell_exec('command -v ' . escapeshellarg($tool)));
            if ($found === '') {
                self::markTestSkipped('wrapper tool ' . $tool . ' unavailable');
            }
            if (!is_link($bin . '/' . $tool)) {
                symlink($found, $bin . '/' . $tool);
            }
        }
        if (trim((string) shell_exec('PATH=' . escapeshellarg($bin) . ' command -v timeout 2>/dev/null')) !== '') {
            self::markTestSkipped('timeout leaked into the fake PATH');
        }
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cc-cmp-4h-wall-' . getmypid() . '-' . substr(uniqid('', true), 0, 7);
        if (!@mkdir($this->dir, 0o700)) {
            self::markTestSkipped('cannot build a shell temp dir');
        }
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->dir . '/' . $entry);
            }
        }
        @rmdir($this->dir);
    }

    public static function tearDownAfterClass(): void
    {
        $bin = self::toolBinDir();
        foreach (['bash', 'sh', 'sleep', 'cat'] as $tool) {
            @unlink($bin . '/' . $tool);
        }
        @rmdir($bin);
    }

    public function testTheWallFallbackKillsTheLiveGroupAndNeverStampsTheExitFile(): void
    {
        $wrapper = $this->fallbackWrapper('sleep 30');
        $exitPath = $this->dir . '/e.exit';

        [$pid, $status, $elapsed, $seen] = $this->runDetachedBounded($wrapper, $exitPath, function (int $pid): array {
            usleep(500_000);

            return $this->groupMembers($pid);
        });

        $sleepers = array_filter($seen, static fn (string $comm): bool => str_contains($comm, 'sleep'));
        self::assertNotSame([], $sleepers, 'the midway census must actually see the running subject for the after-claim to mean KILLED, not never-born');
        self::assertSame([], $this->groupMembers($pid), 'the group-KILL rung takes every member down — leader, subject and watchdog sleeper alike');
        self::assertLessThan(10.0, $elapsed, 'the shortened wall fired while the 30 s subject was still scheduled');
        self::assertFileDoesNotExist($exitPath, 'a walled shell dies before the trailer stamps — the honest unset exit code of the class doc-block');
        self::assertSame(9, $status, 'the reaped leader died by SIGKILL itself (proc_close reports the raw signal here; observed deterministic)');
    }

    public function testASettledFallbackShellDisarmsTheWatchdogAndStampsZero(): void
    {
        // The settle pin runs a 60 s wall — longer than the two-second census
        // window below — so the watchdog CANNOT legitimately die before the
        // assertion: the only force that empties the census on the settled
        // path is the trailer's disarm. Delete that kill and this pin goes
        // red on a live member, not on a race (the 2 s wall variant did —
        // the watchdog's own group-KILL rung suicided it just inside the
        // polling window and hid the defect; recorded so nobody shortens
        // this figure again).
        $wrapper = $this->fallbackWrapper('echo hi', 60);
        $exitPath = $this->dir . '/e.exit';

        [$pid, $status] = $this->runDetachedBounded($wrapper, $exitPath, static fn (int $p): array => []);

        self::assertSame(0, $status, 'the settled shell exits with the command status, unstopped');
        self::assertSame('0', @file_get_contents($exitPath), 'the trailer stamped the exit file — the watchdog never reached its group rung');
        self::assertStringContainsString('hi', (string) @file_get_contents($this->dir . '/e.out'), 'the recorder still landed the output');

        $deadline = microtime(true) + 2.0;
        $survivors = $this->groupMembers($pid);
        while ($survivors !== [] && microtime(true) < $deadline) {
            usleep(50_000);
            $survivors = $this->groupMembers($pid);
        }
        self::assertSame([], $survivors, 'the trailer TERMs the watchdog before stamping, so a settled shell leaves no sleeping member for a later sweep to find — with a 60 s wall out, a survivor here IS the missing disarm');
    }

    public function testTheTimeoutArmSegmentIsByteIdenticalAndTheWatchLiteralRidesOnce(): void
    {
        $built = (new \ReflectionMethod(BackgroundShellRegistry::class, 'buildWrapper'))->invoke(null, 'true');

        self::assertStringContainsString(
            '; if command -v timeout >/dev/null 2>&1; then exec timeout -s KILL ' . BackgroundShellRegistry::MAX_LIFETIME_SECONDS . ' bash -c "$SHELL_INNER"',
            $built,
            'hosts that HAVE coreutils keep the arm they shipped with, byte for byte',
        );
        self::assertSame(
            1,
            substr_count($built, 'sleep ' . BackgroundShellRegistry::MAX_LIFETIME_SECONDS),
            'the watchdog sleep is the ONLY sleep of the wall figure — the drain loop sleeps 0.05 and the timeout arm uses exec, so the shortening step stays unique-match',
        );
        self::assertStringNotContainsString(
            '; else exec bash -c "$SHELL_INNER"; fi',
            $built,
            'the bare no-wall fallback is retired — the else arm now owes a watchdog',
        );
    }

    private static function toolBinDir(): string
    {
        return sys_get_temp_dir() . '/cc-cmp-4h-wallbin-' . getmypid();
    }

    private function fallbackWrapper(string $command, int $wallSeconds = self::WALL_SECONDS): string
    {
        $built = (new \ReflectionMethod(BackgroundShellRegistry::class, 'buildWrapper'))->invoke(null, $command);
        $shortened = str_replace(
            'sleep ' . BackgroundShellRegistry::MAX_LIFETIME_SECONDS,
            'sleep ' . $wallSeconds,
            $built,
            $count,
        );
        self::assertSame(1, $count, 'the watch literal must occur exactly once for the shortening to BE the wall');

        return $shortened;
    }

    /**
     * Launches through the real detach prefix, observes once, then reaps.
     *
     * A settled wrapper's backgrounded watchdog is reparented to init the
     * moment bash exits — this process can never `waitpid` it, and neither
     * can any second `setsid`-style reap. The census is the ONLY honest
     * observation surface here, which is exactly why the settle pin below
     * shortens nothing that could suicide its evidence: it runs a wall LONGER
     * than the polling window, so a surviving (non-disarmed) watchdog stays
     * visible to the census until a red assertion, never until a race.
     *
     * @param  callable(int): array<int, string> $midway
     * @return array{0:int,1:int,2:float,3:array<int, string>}
     */
    private function runDetachedBounded(string $wrapper, string $exitPath, callable $midway): array
    {
        $spec = ProcessContainment::spawnSpec($wrapper);
        $proc = proc_open($spec, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->dir . '/sink.out', 'a'],
            2 => ['file', $this->dir . '/sink.err', 'a'],
        ], $pipes, null, [
            'PATH' => self::toolBinDir(),
            BackgroundShellRegistry::EXIT_CODE_FILE_ENV => $exitPath,
        ]);
        self::assertTrue(is_resource($proc), 'the wrapper must launch through the real prefix path');

        $launched = proc_get_status($proc);
        $pid = (int) $launched['pid'];
        $seen = $midway($pid);
        $start = microtime(true);
        $status = proc_close($proc);

        return [$pid, $status, microtime(true) - $start, $seen];
    }

    /**
     * Live (non-zombie) processes whose recorded pgid is $pgid — the same
     * census shape groupMemberAlive() applies on /proc hosts, rebuilt here so
     * the pins can name WHICH members died, not just that the group did.
     *
     * @return array<int, string> pid => comm
     */
    private function groupMembers(int $pgid): array
    {
        $members = [];
        foreach (scandir('/proc') ?: [] as $entry) {
            if ($entry === '' || $entry[0] < '1' || $entry[0] > '9') {
                continue;
            }
            $stat = @file_get_contents('/proc/' . $entry . '/stat');
            if ($stat === false) {
                continue;
            }
            $open = strpos($stat, '(');
            $close = strrpos($stat, ')');
            if ($open === false || $close === false || $close <= $open) {
                continue;
            }
            $fields = preg_split('/\s+/', trim(substr($stat, $close + 1))) ?: [];
            if (($fields[2] ?? '') === (string) $pgid && ($fields[0] ?? 'Z') !== 'Z') {
                $members[(int) $entry] = substr($stat, $open + 1, $close - $open - 1);
            }
        }

        return $members;
    }
}
