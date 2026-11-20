<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tests\Support\SupervisorPump;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Background\BackgroundSession;
use SugarCraft\Code\Background\BackgroundSessionStatus;
use SugarCraft\Code\Background\BackgroundStopOutcome;
use SugarCraft\Code\Background\BackgroundSupervisor;

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

    /** One advance call may never sit on a daemon for this long. */
    private const MAX_ADVANCE_SECONDS = 0.5;

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
            usleep(20_000);
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
            usleep(20_000);
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

        $deadline = microtime(true) + 20.0;
        while (($first === null || $second === null) && microtime(true) < $deadline) {
            $supervisor->advanceStops();
            usleep(20_000);
        }

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
        $deadline = microtime(true) + 10.0;
        while ($reaps->getValue($supervisor) !== [] && microtime(true) < $deadline) {
            $supervisor->advanceSpawns();
            usleep(20_000);
        }
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
