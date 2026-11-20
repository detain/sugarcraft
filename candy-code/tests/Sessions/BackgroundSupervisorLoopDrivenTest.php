<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\BackgroundTickMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Support\ForkedChild;
use SugarCraft\Code\Tests\Support\ReapsForkedChildrenTrait;
use SugarCraft\Code\Tests\Support\SupervisorPump;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Background\BackgroundSession;
use SugarCraft\Code\Background\BackgroundSessionStatus;
use SugarCraft\Code\Background\BackgroundStopOutcome;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Support\ProcessReaper;

/**
 * The accept and the stop run from the supervisor's loop (AUD §7 #2, CC-1 row f):
 * a launch returns at once, the daemon's handshake is advanced by
 * {@see BackgroundSupervisor::advanceSpawns()}, and a stop is advanced by
 * {@see BackgroundSupervisor::advanceStops()}. Each advance must return without
 * waiting on the daemon, and the promise must stay pending until the loop has
 * actually driven it.
 */
final class BackgroundSupervisorLoopDrivenTest extends TestCase
{
    use StoppableDaemonFixtureTrait;
    use ReapsForkedChildrenTrait;

    /** One advance call may never sit on a daemon for this long. */
    private const MAX_ADVANCE_SECONDS = 0.5;

    /** The chat's background poll cadence (Chat::BACKGROUND_POLL_SECONDS). */
    private const POLL_CADENCE_SECONDS = 2.0;

    /** Ticks allowed before a reap the poll never collects fails the test (about 20 s of poll). */
    private const MAX_POLL_TICKS = 10;

    /** @var list<int> launchers the SIGTERM-ignoring tests started, killed in tearDown if left alive */
    private array $stubbornLauncherPids = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['proc_open', 'pcntl_fork', 'posix_setsid', 'stream_socket_server', 'posix_kill'] as $fn) {
            if (!function_exists($fn)) {
                $this->markTestSkipped("{$fn}() unavailable");
            }
        }
        $this->setUpDaemonFixture();
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        foreach ($this->stubbornLauncherPids as $pid) {
            if (posix_kill($pid, 0)) {
                @posix_kill($pid, 9);
            }
        }
        $this->tearDownDaemonFixture();
        parent::tearDown();
    }

    public function testASpawnIsPendingUntilTheLoopAcceptsItsDaemonAndNoAdvanceWaitsOnIt(): void
    {
        putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; sleep 1; printf LOOPSPAWN42');
        $supervisor = new BackgroundSupervisor();

        $launchStarted = microtime(true);
        $promise = $supervisor->spawnSessionAsync(
            name: 'loop accept',
            agent: $this->agent(),
            task: 'accept from the loop',
            workingDirectory: $this->fixtureHome,
            timeoutSeconds: 30,
        );
        $this->assertLessThan(1.0, microtime(true) - $launchStarted, 'the launch itself must not wait for the daemon');

        $session = null;
        $promise->then(static function (BackgroundSession $settled) use (&$session): void {
            $session = $settled;
        });
        $this->assertNull($session, 'nothing settles a spawn before the loop advances it');

        $deadline = microtime(true) + 20.0;
        $slowest = 0.0;
        while ($session === null && microtime(true) < $deadline) {
            $advanceStarted = microtime(true);
            $supervisor->advanceSpawns();
            $slowest = max($slowest, microtime(true) - $advanceStarted);
            SupervisorPump::pace();
        }

        $this->assertInstanceOf(BackgroundSession::class, $session, 'the loop never accepted the daemon');
        $this->assertLessThan(self::MAX_ADVANCE_SECONDS, $slowest, 'an advance blocked on the daemon');
        $this->assertSame($session->id, $supervisor->getSession($session->id)?->id, 'an accepted session is registered');

        $ipc = $this->ipcOf($supervisor, $session->id);
        $this->fixtureDaemons[] = ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];
        $this->settle($supervisor);
    }

    public function testTickDrivesTheAcceptToo(): void
    {
        putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; sleep 1; printf LOOPTICK42');
        $supervisor = new BackgroundSupervisor();

        $session = null;
        $supervisor->spawnSessionAsync(
            name: 'tick accept',
            agent: $this->agent(),
            task: 'accept from tick',
            workingDirectory: $this->fixtureHome,
            timeoutSeconds: 30,
        )->then(static function (BackgroundSession $settled) use (&$session): void {
            $session = $settled;
        });

        SupervisorPump::until($supervisor, static function () use (&$session): bool {
            return $session !== null;
        });

        $this->assertInstanceOf(BackgroundSession::class, $session, 'tick() must advance the pending accept');
        $ipc = $this->ipcOf($supervisor, $session->id);
        $this->fixtureDaemons[] = ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];
        $this->settle($supervisor);
    }

    public function testASpawnThatNeverAuthenticatesIsRejectedAtItsDeadlineAndLeavesNoFiles(): void
    {
        putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; sleep 1; printf LOOPLATE42');
        $supervisor = new BackgroundSupervisor();

        $error = null;
        $session = null;
        $promise = $supervisor->spawnSessionAsync(
            name: 'deadline',
            agent: $this->agent(),
            task: 'never settles its handshake in time',
            workingDirectory: $this->fixtureHome,
            timeoutSeconds: 30,
        );
        $promise->then(
            static function (BackgroundSession $settled) use (&$session): void {
                $session = $settled;
            },
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        $pending = (new \ReflectionProperty(BackgroundSupervisor::class, 'pendingSpawns'))->getValue($supervisor);
        $this->assertCount(1, $pending, 'the launch is recorded as pending');
        $socketPath = array_values($pending)[0]['socketPath'];
        $bufferPath = array_values($pending)[0]['bufferPath'];

        // Advancing past the deadline settles the spawn whichever way the race went:
        // the daemon authenticated, or the deadline fails it. Nothing stays pending.
        // The advance itself must not wait on the launcher it retires.
        $advanceStarted = microtime(true);
        $supervisor->advanceSpawns(microtime(true) + 60.0);
        $this->assertLessThan(self::MAX_ADVANCE_SECONDS, microtime(true) - $advanceStarted, 'a deadline failure blocked the loop on its launcher');

        $this->assertNotNull($session ?? $error, 'a pending spawn past its deadline must settle');
        $this->assertSame([], (new \ReflectionProperty(BackgroundSupervisor::class, 'pendingSpawns'))->getValue($supervisor));
        $this->drainLaunchers($supervisor);
        if ($error !== null) {
            $this->assertInstanceOf(\RuntimeException::class, $error);
            $this->assertFileDoesNotExist($socketPath, 'a failed spawn left its socket behind');
            $this->assertFileDoesNotExist($bufferPath, 'a failed spawn left its buffer behind');
            $this->assertNull($supervisor->getSession(array_key_first($pending) ?? ''));
        } else {
            $ipc = $this->ipcOf($supervisor, $session->id);
            $this->fixtureDaemons[] = ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];
            $this->settle($supervisor);
        }
    }

    public function testATeardownFailsASpawnStillWaitingOnItsDaemonAndLeavesNoFiles(): void
    {
        putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; sleep 1; printf LOOPTEAR42');
        $supervisor = new BackgroundSupervisor();

        $error = null;
        $session = null;
        $supervisor->spawnSessionAsync(
            name: 'teardown',
            agent: $this->agent(),
            task: 'abandoned inside the launch window',
            workingDirectory: $this->fixtureHome,
            timeoutSeconds: 30,
        )->then(
            static function (BackgroundSession $settled) use (&$session): void {
                $session = $settled;
            },
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );
        [$socketPath, $bufferPath] = $this->pendingPaths($supervisor);

        $supervisor->teardown();

        $this->assertNull($session, 'a torn-down spawn must not register a session');
        $this->assertInstanceOf(\RuntimeException::class, $error, 'a torn-down spawn must reject its promise');
        $this->assertSame([], (new \ReflectionProperty(BackgroundSupervisor::class, 'pendingSpawns'))->getValue($supervisor));
        $this->assertFileDoesNotExist($socketPath, 'a torn-down spawn left its socket behind');
        $this->assertFileDoesNotExist($bufferPath, 'a torn-down spawn left its buffer behind');
        $this->assertFalse($supervisor->hasActiveSessions(), 'a torn-down spawn must not stay active');
        $this->drainLaunchers($supervisor);
    }

    public function testDroppingTheSupervisorFailsItsPendingSpawn(): void
    {
        putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; sleep 1; printf LOOPDROP42');
        $supervisor = new BackgroundSupervisor();

        $error = null;
        $supervisor->spawnSessionAsync(
            name: 'dropped',
            agent: $this->agent(),
            task: 'the TUI quits inside the launch window',
            workingDirectory: $this->fixtureHome,
            timeoutSeconds: 30,
        )->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );
        [$socketPath, $bufferPath] = $this->pendingPaths($supervisor);

        unset($supervisor);

        $this->assertInstanceOf(\RuntimeException::class, $error, 'dropping the supervisor must reject its pending spawn');
        $this->assertFileDoesNotExist($socketPath, 'a dropped spawn left its socket behind');
        $this->assertFileDoesNotExist($bufferPath, 'a dropped spawn left its buffer behind');
    }

    public function testAForkedChildNeitherAdvancesNorFailsItsParentsPendingSpawn(): void
    {
        putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; sleep 1; printf LOOPFORK42');
        $supervisor = new BackgroundSupervisor();

        $error = null;
        $session = null;
        $supervisor->spawnSessionAsync(
            name: 'forked',
            agent: $this->agent(),
            task: 'a forked turn child runs inside the launch window',
            workingDirectory: $this->fixtureHome,
            timeoutSeconds: 30,
        )->then(
            static function (BackgroundSession $settled) use (&$session): void {
                $session = $settled;
            },
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );
        [$socketPath] = $this->pendingPaths($supervisor);

        $child = $this->forkTracked();
        $this->assertGreaterThanOrEqual(0, $child, 'pcntl_fork() failed');
        if ($child === 0) {
            // Past the spawn's deadline on purpose: an advance that still owned the
            // parent's spawn would fail it now and discard its socket.
            $supervisor->advanceSpawns(microtime(true) + 3600.0);
            $supervisor->advanceStops();
            ForkedChild::exitNow(0);
        }
        pcntl_waitpid($child, $status, 0);
        $this->forgetForkedChild($child);

        $this->assertNull($error, 'a forked child must not fail its parent\'s spawn');
        $this->assertNull($session, 'a forked child must not settle its parent\'s spawn');
        $this->assertCount(1, array_values((new \ReflectionProperty(BackgroundSupervisor::class, 'pendingSpawns'))->getValue($supervisor)), 'the parent still has its spawn pending');
        $this->assertFileExists($socketPath, 'a forked child discarded its parent\'s socket');

        $supervisor->teardown();
        $this->assertInstanceOf(\RuntimeException::class, $error, 'the parent tears its own spawn down');
        $this->drainLaunchers($supervisor);
    }

    public function testAForkedChildNeitherDrivesNorSettlesItsParentsStop(): void
    {
        $supervisor = new BackgroundSupervisor();
        $spawned = $this->spawnSleepingDaemon($supervisor);
        $id = $spawned['session']->id;

        $outcome = null;
        $supervisor->stopSessionAsync($id)->then(static function (BackgroundStopOutcome $o) use (&$outcome): void {
            $outcome = $o;
        });

        $child = $this->forkTracked();
        $this->assertGreaterThanOrEqual(0, $child, 'pcntl_fork() failed');
        if ($child === 0) {
            // Past every rung's deadline on purpose: an advance that still owned the
            // stop would walk it into the signal rungs against the daemon.
            $supervisor->advanceStops(microtime(true) + 3600.0);
            ForkedChild::exitNow(0);
        }
        pcntl_waitpid($child, $status, 0);
        $this->forgetForkedChild($child);

        $this->assertNull($outcome, 'a forked child must not settle its parent\'s stop');

        SupervisorPump::until($supervisor, static function () use (&$outcome): bool {
            return $outcome !== null;
        });
        $this->assertSame(BackgroundStopOutcome::StoppedViaIpc, $outcome, 'the parent must still drive its own stop through the acknowledged STOP');
        $this->assertSame(BackgroundSessionStatus::Stopped, $supervisor->getSession($id)?->status);
        $this->assertFileDoesNotExist($spawned['ipc']['socketPath'], 'a stopped daemon left its socket behind');
    }

    public function testAStopIsPendingUntilTheLoopDrivesItThroughItsRungs(): void
    {
        $supervisor = new BackgroundSupervisor();
        $spawned = $this->spawnSleepingDaemon($supervisor);
        $id = $spawned['session']->id;

        $outcome = null;
        $promise = $supervisor->stopSessionAsync($id);
        $promise->then(static function (BackgroundStopOutcome $settled) use (&$outcome): void {
            $outcome = $settled;
        });
        $this->assertNull($outcome, 'a stop that needs the daemon must not resolve inside the call');

        $deadline = microtime(true) + 20.0;
        $slowest = 0.0;
        while ($outcome === null && microtime(true) < $deadline) {
            $advanceStarted = microtime(true);
            $supervisor->advanceStops();
            $slowest = max($slowest, microtime(true) - $advanceStarted);
            SupervisorPump::pace();
        }

        $this->assertSame(BackgroundStopOutcome::StoppedViaIpc, $outcome, 'the acknowledged STOP was not driven to its outcome');
        $this->assertLessThan(self::MAX_ADVANCE_SECONDS, $slowest, 'an advance blocked on the daemon');
        $this->assertSame(BackgroundSessionStatus::Stopped, $supervisor->getSession($id)?->status);
        $this->assertFileDoesNotExist($spawned['ipc']['socketPath'], 'a stopped daemon left its socket behind');
    }

    public function testASecondStopWhileOneIsPendingSharesItsOutcome(): void
    {
        $supervisor = new BackgroundSupervisor();
        $spawned = $this->spawnSleepingDaemon($supervisor);
        $id = $spawned['session']->id;

        $first = null;
        $second = null;
        $supervisor->stopSessionAsync($id)->then(static function (BackgroundStopOutcome $o) use (&$first): void {
            $first = $o;
        });
        $supervisor->stopSessionAsync($id)->then(static function (BackgroundStopOutcome $o) use (&$second): void {
            $second = $o;
        });

        SupervisorPump::until($supervisor, static function () use (&$first, &$second): bool {
            return $first !== null && $second !== null;
        });

        $this->assertSame(BackgroundStopOutcome::StoppedViaIpc, $first);
        $this->assertSame($first, $second, 'a second stop must join the first, not race it');
    }

    public function testAStopOfAnUnknownSessionResolvesAtOnce(): void
    {
        $supervisor = new BackgroundSupervisor();

        $outcome = null;
        $supervisor->stopSessionAsync('sess_20260101000000_deadbeef')->then(
            static function (BackgroundStopOutcome $o) use (&$outcome): void {
                $outcome = $o;
            },
        );

        $this->assertSame(BackgroundStopOutcome::UnknownSession, $outcome);
    }

    public function testTeardownSettlesAStopThisProcessStillOwes(): void
    {
        $supervisor = new BackgroundSupervisor();
        $spawned = $this->spawnSleepingDaemon($supervisor);
        $id = $spawned['session']->id;

        $outcome = null;
        $supervisor->stopSessionAsync($id)->then(static function (BackgroundStopOutcome $o) use (&$outcome): void {
            $outcome = $o;
        });
        $this->assertNull($outcome, 'the stop is still in flight when the TUI quits');

        $supervisor->teardown();

        $this->assertSame(BackgroundStopOutcome::StoppedViaIpc, $outcome, 'quitting mid-stop must still drive the stop to its outcome');
        $this->assertSame(BackgroundSessionStatus::Stopped, $supervisor->getSession($id)?->status);
        $this->assertFileDoesNotExist($spawned['ipc']['socketPath'], 'a stop settled by teardown left its socket behind');
    }

    public function testAFailedSpawnsLauncherThatIgnoresSigtermIsSigkilledAndCollectedByThePollAlone(): void
    {
        $supervisor = new BackgroundSupervisor();
        ['pid' => $pid, 'error' => $error, 'started' => $started] = $this->failASpawnWhoseLauncherIgnoresSigterm($supervisor);

        $this->assertInstanceOf(\RuntimeException::class, $error, 'the spawn must fail at its deadline');
        $this->assertSame([], (new \ReflectionProperty(BackgroundSupervisor::class, 'pendingSpawns'))->getValue($supervisor));
        $reaps = new \ReflectionProperty(BackgroundSupervisor::class, 'launcherReaps');
        $this->assertCount(1, $reaps->getValue($supervisor), 'the failed launcher is retired for collection');
        $this->assertTrue(posix_kill($pid, 0), 'nothing collects the launcher before the poll runs');

        // With no other session, only the retired launcher can keep the poll armed.
        $this->assertTrue($supervisor->hasActiveSessions(), 'a retired launcher must keep the background poll armed');

        // Drive the poll the way the TUI does: the chat's subscription must stay armed
        // while the reap is pending, and each poll tick arrives through Chat::update().
        $chat = new Chat(backgroundSupervisor: $supervisor);
        $this->assertTrue($this->pollArmed($chat), 'the chat must keep the background poll armed while a reap is pending');

        $ticks = 0;
        while ($this->pollArmed($chat) && $ticks < self::MAX_POLL_TICKS) {
            SupervisorPump::pace(self::POLL_CADENCE_SECONDS);
            [$chat] = $chat->update(new BackgroundTickMsg());
            $ticks++;
        }

        $this->assertFalse($this->pollArmed($chat), 'the poll must disarm once the launcher is collected');
        $this->assertFalse($supervisor->hasActiveSessions(), 'the supervisor must agree the poll may disarm');
        $this->assertSame([], $reaps->getValue($supervisor), 'the poll never collected the retired launcher');
        $this->assertGreaterThanOrEqual(
            ProcessReaper::TERMINATE_GRACE_SECONDS,
            microtime(true) - $started,
            'SIGTERM is ignored, so only the SIGKILL stage after its grace can have ended the launcher',
        );
        $this->assertFalse(posix_kill($pid, 0), 'a launcher ignoring SIGTERM was left alive');
    }

    /** Whether the chat's subscriptions include the background poll tick. */
    private function pollArmed(Chat $chat): bool
    {
        $subscriptions = $chat->subscriptions();

        return $subscriptions !== null && $subscriptions->has('code.background-poll');
    }

    public function testTeardownCollectsAFailedSpawnsLauncherThatIgnoresSigterm(): void
    {
        $supervisor = new BackgroundSupervisor();
        ['pid' => $pid, 'error' => $error] = $this->failASpawnWhoseLauncherIgnoresSigterm($supervisor);

        $this->assertInstanceOf(\RuntimeException::class, $error, 'the spawn must fail at its deadline');
        $reaps = new \ReflectionProperty(BackgroundSupervisor::class, 'launcherReaps');
        $this->assertCount(1, $reaps->getValue($supervisor), 'the failed launcher is retired for collection');

        $started = microtime(true);
        $supervisor->teardown();

        $this->assertLessThan(
            ProcessReaper::TERMINATE_GRACE_SECONDS + ProcessReaper::KILL_GRACE_SECONDS + 5.0,
            microtime(true) - $started,
            'teardown must collect the launcher within the reap budget',
        );
        $this->assertSame([], $reaps->getValue($supervisor), 'teardown left a retired launcher uncollected');
        $this->assertFalse(posix_kill($pid, 0), 'teardown left a launcher ignoring SIGTERM alive');
        $this->assertFalse($supervisor->hasActiveSessions(), 'a torn-down supervisor must not stay active');
    }

    /**
     * Start a spawn whose launcher ignores SIGTERM, then fail it at its deadline.
     *
     * The real launcher is a `php -r` bootstrap that never authenticates. Its handle
     * is swapped for a shell that traps TERM and execs `sleep`, so the retired
     * launcher can leave only through SIGKILL. Nothing here is driven by the poll.
     *
     * @return array{pid: int, error: ?\Throwable, started: float}
     */
    private function failASpawnWhoseLauncherIgnoresSigterm(BackgroundSupervisor $supervisor): array
    {
        if (!is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh unavailable');
        }

        $error = null;
        $supervisor->spawnSessionAsync(
            name: 'stubborn launcher',
            agent: $this->agent(),
            task: 'never authenticates and ignores SIGTERM',
            workingDirectory: $this->fixtureHome,
            timeoutSeconds: 30,
        )->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $pendingProp = new \ReflectionProperty(BackgroundSupervisor::class, 'pendingSpawns');
        $pending = $pendingProp->getValue($supervisor);
        $this->assertCount(1, $pending, 'the launch is recorded as pending');
        $sessionId = array_key_first($pending);

        // The real launcher is closed here so the stubborn one is the only launcher in flight.
        $real = $pending[$sessionId]['proc'];
        @proc_terminate($real, 9);
        proc_close($real);

        // The ready marker is written only after the trap is installed: a SIGTERM that
        // arrived first would hit the shell's default disposition and end it at once.
        $ready = $this->fixtureHome . '/launcher-ready';
        $stubborn = proc_open(
            ['/bin/sh', '-c', 'trap "" TERM; : > ' . escapeshellarg($ready) . '; exec sleep 60'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($stubborn, 'the stubborn launcher could not start');
        $pid = (int) proc_get_status($stubborn)['pid'];
        $this->stubbornLauncherPids[] = $pid;
        SupervisorPump::until($supervisor, static fn (): bool => is_file($ready), 10.0);
        $this->assertFileExists($ready, 'the stubborn launcher never installed its SIGTERM trap');
        $pending[$sessionId]['proc'] = $stubborn;
        $pendingProp->setValue($supervisor, $pending);

        $started = microtime(true);
        $supervisor->advanceSpawns(microtime(true) + 60.0);

        return ['pid' => $pid, 'error' => $error, 'started' => $started];
    }

    /** @return array{0: string, 1: string} the socket and buffer paths of the one pending spawn */
    private function pendingPaths(BackgroundSupervisor $supervisor): array
    {
        $pending = array_values((new \ReflectionProperty(BackgroundSupervisor::class, 'pendingSpawns'))->getValue($supervisor));
        $this->assertCount(1, $pending, 'the launch is recorded as pending');

        return [$pending[0]['socketPath'], $pending[0]['bufferPath']];
    }

    /** Advance the supervisor until every retired launcher is collected, bounded. */
    private function drainLaunchers(BackgroundSupervisor $supervisor): void
    {
        $reaps = new \ReflectionProperty(BackgroundSupervisor::class, 'launcherReaps');
        SupervisorPump::until($supervisor, static fn (): bool => $reaps->getValue($supervisor) === [], 10.0);
        $this->assertSame([], $reaps->getValue($supervisor), 'a retired launcher was never collected');
    }

    /** Tick until nothing this supervisor spawned is still active, bounded. */
    private function settle(BackgroundSupervisor $supervisor): void
    {
        SupervisorPump::until($supervisor, static fn (): bool => !$supervisor->hasActiveSessions());
    }

    private function agent(): Agent
    {
        return new Agent(
            name: 'bg-agent',
            description: 'Background agent',
            prompt: '',
            model: '',
            provider: '',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }
}
