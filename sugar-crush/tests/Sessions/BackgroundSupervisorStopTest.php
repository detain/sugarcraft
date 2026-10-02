<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Sessions\BackgroundStopOutcome;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Sessions\SessionNotificationInterface;
use SugarCraft\Crush\Sessions\SessionStopNotificationInterface;

/**
 * Audit BG-1: a background session can be stopped.
 *
 * Before the fix the daemon's `STOP` had no sender and `BackgroundSupervisor`
 * had no stop method at all, so a `/bg` task ran to its timeout (an hour by
 * default) and outlived the TUI. These tests drive
 * {@see BackgroundSupervisor::stopSession()} against REAL daemons on both live
 * rungs (authenticated IPC, and SIGTERM when the socket is gone), against a
 * SIGTERM-ignoring stand-in for the tree-kill rung, and pin the safety rule
 * that matters most: a pid whose `/proc` start time does not prove it is the
 * daemon is never signalled.
 */
final class BackgroundSupervisorStopTest extends TestCase
{
    use StoppableDaemonFixtureTrait;

    /** @var list<resource> proc_open handles of stand-in processes */
    private array $standIns = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['proc_open', 'pcntl_fork', 'pcntl_signal', 'posix_kill', 'posix_setsid', 'stream_socket_server'] as $fn) {
            if (!function_exists($fn)) {
                $this->markTestSkipped("{$fn}() unavailable");
            }
        }
        if (BackgroundSupervisor::procStartTime((int) getmypid()) === null) {
            $this->markTestSkipped('/proc start-time fingerprint unavailable');
        }

        $this->setUpDaemonFixture();
    }

    protected function tearDown(): void
    {
        foreach ($this->standIns as $proc) {
            $status = proc_get_status($proc);
            if ($status['running']) {
                @posix_kill($status['pid'], 9);
            }
            proc_close($proc);
        }
        $this->standIns = [];

        $this->tearDownDaemonFixture();

        parent::tearDown();
    }

    public function testStopSessionStopsARunningDaemonOverItsAuthenticatedSocket(): void
    {
        $listener = $this->recordingListener();
        $supervisor = (new BackgroundSupervisor())->withListener($listener);
        $spawned = $this->spawnSleepingDaemon($supervisor);
        $id = $spawned['session']->id;
        $ipc = $spawned['ipc'];
        $bufferView = $this->linkBufferView($ipc);

        $started = microtime(true);
        $outcome = $supervisor->stopSession($id);
        $elapsed = microtime(true) - $started;

        $this->assertSame(BackgroundStopOutcome::StoppedViaIpc, $outcome);
        $this->assertLessThan(
            BackgroundSupervisor::STOP_EXIT_WAIT_SECONDS,
            $elapsed,
            'an acknowledged STOP should settle well inside the exit budget',
        );

        $buffer = (string) file_get_contents($bufferView);
        $this->assertStringContainsString("[session:task:stopped]\n", $buffer, 'the daemon must record the stop itself');
        $this->assertIpcReleased($ipc);
        $this->assertStringNotContainsString('via=', $buffer, 'the IPC rung must not have needed a signal');
        $this->assertFalse(
            $this->aliveNotZombie($ipc['pid'])
                && BackgroundSupervisor::procStartTime($ipc['pid']) === ($ipc['startTime'] ?? null),
            'the daemon process is still alive',
        );
        $this->assertSame([], $this->liveSessionMembers($spawned['sid']), 'the worker outlived its daemon');

        $this->assertSame(BackgroundSessionStatus::Stopped, $supervisor->getSession($id)->status);
        $this->assertSame([], $supervisor->getActiveSessions());
        $this->assertSame(['stopped'], $listener->events);

        // The reaper must not re-label a stopped session on later ticks.
        $supervisor->tick();
        $supervisor->tick(time() + 60);
        $this->assertSame(BackgroundSessionStatus::Stopped, $supervisor->getSession($id)->status);
        $this->assertSame(['stopped'], $listener->events);

        $this->assertSame(BackgroundStopOutcome::AlreadyFinished, $supervisor->stopSession($id));
    }

    public function testStopSessionSignalsTheVerifiedDaemonWhenItsSocketIsGone(): void
    {
        $supervisor = new BackgroundSupervisor();
        $spawned = $this->spawnSleepingDaemon($supervisor);
        $id = $spawned['session']->id;
        $ipc = $spawned['ipc'];

        // The daemon's listener keeps its inode, but nothing can connect by
        // name any more — the shape of a /tmp cleaner having run.
        $this->assertTrue(unlink($ipc['socketPath']));
        $bufferView = $this->linkBufferView($ipc);

        $outcome = $supervisor->stopSession($id);

        $this->assertSame(BackgroundStopOutcome::StoppedViaSignal, $outcome);
        $buffer = (string) file_get_contents($bufferView);
        $this->assertIpcReleased($ipc);
        // via=SIGTERM is written by the DAEMON's trap: without it the default
        // disposition kills the daemon alone and the worker keeps running.
        $this->assertStringContainsString('[session:task:stopped] via=SIGTERM', $buffer);
        $this->assertStringNotContainsString('via=supervisor-kill', $buffer);
        $this->assertSame([], $this->liveSessionMembers($spawned['sid']), 'the worker was orphaned by the signal');
        $this->assertSame(BackgroundSessionStatus::Stopped, $supervisor->getSession($id)->status);

        $supervisor->tick();
        $this->assertSame(BackgroundSessionStatus::Stopped, $supervisor->getSession($id)->status);
    }

    public function testAnUnknownSessionIdIsReportedAndNothingIsTouched(): void
    {
        $supervisor = new BackgroundSupervisor();

        $this->assertSame(BackgroundStopOutcome::UnknownSession, $supervisor->stopSession('sess_20260101000000_deadbeef'));
        $this->assertNull($supervisor->getSession('sess_20260101000000_deadbeef'));
    }

    public function testARecycledPidIsNeverSignalled(): void
    {
        $standIn = $this->startStandIn();
        $start = BackgroundSupervisor::procStartTime($standIn);
        $this->assertNotNull($start);

        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->runningSession('s-recycled'));
        $buffer = $this->fixtureFile("[session:task:start]\n");
        // A live pid wearing a DIFFERENT start time than the one recorded at
        // the handshake: the daemon is gone and the number is a stranger's.
        $this->injectIpc($supervisor, 's-recycled', $buffer, $standIn, $start + 1_000_000);

        $outcome = $supervisor->stopSession('s-recycled');

        $this->assertSame(BackgroundStopOutcome::AlreadyFinished, $outcome);
        $this->assertTrue($this->aliveNotZombie($standIn), 'a recycled pid was signalled');
        $this->assertFalse($supervisor->getSession('s-recycled')->isActive());
    }

    public function testAPidWhoseIdentityWasNeverFingerprintedIsNeverSignalled(): void
    {
        $standIn = $this->startStandIn();

        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->runningSession('s-unproven'));
        $buffer = $this->fixtureFile("[session:task:start]\n");
        // No start time: liveness may be guessed from signal 0, identity may
        // not, and a signal needs identity.
        $this->injectIpc($supervisor, 's-unproven', $buffer, $standIn, null);

        $this->assertSame(BackgroundStopOutcome::CouldNotStop, $supervisor->stopSession('s-unproven'));
        $this->assertTrue($this->aliveNotZombie($standIn), 'an unverified pid was signalled');
        $this->assertSame(BackgroundSessionStatus::Running, $supervisor->getSession('s-unproven')->status);
    }

    public function testADaemonThatIgnoresSigtermIsTreeKilledAndRecordedAsStopped(): void
    {
        $childPidFile = $this->fixtureHome . '/standin-child.pid';
        $standIn = $this->startStandIn(trapsTerm: true, childPidFile: $childPidFile);
        $child = (int) trim((string) file_get_contents($childPidFile));
        $this->assertGreaterThan(1, $child);
        $this->fixturePidFiles[] = $childPidFile;

        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->runningSession('s-stubborn'));
        $buffer = $this->fixtureFile("[session:task:start]\n");
        $this->injectIpc($supervisor, 's-stubborn', $buffer, $standIn, BackgroundSupervisor::procStartTime($standIn));

        $started = microtime(true);
        $outcome = $supervisor->stopSession('s-stubborn');
        $elapsed = microtime(true) - $started;

        $this->assertSame(BackgroundStopOutcome::StoppedViaSignal, $outcome);
        $this->assertLessThan(BackgroundSupervisor::STOP_EXIT_WAIT_SECONDS + 4.0, $elapsed, 'the stop ladder is unbounded');
        $this->assertFalse($this->aliveNotZombie($standIn), 'the SIGTERM-ignoring process survived');
        $this->assertFalse($this->aliveNotZombie($child), 'the tree kill missed the child — it would be orphaned');
        $this->assertStringContainsString(
            '[session:task:stopped] via=supervisor-kill',
            (string) file_get_contents($buffer),
            'a daemon killed too hard to log its own stop must still leave a stop record',
        );
        $this->assertSame(BackgroundSessionStatus::Stopped, $supervisor->getSession('s-stubborn')->status);
    }

    public function testASessionAlreadySettledIsReportedAsFinished(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->runningSession('s-done')->withStatus(BackgroundSessionStatus::Completed));

        $this->assertSame(BackgroundStopOutcome::AlreadyFinished, $supervisor->stopSession('s-done'));
        $this->assertSame(BackgroundSessionStatus::Completed, $supervisor->getSession('s-done')->status);
    }

    public function testTickSettlesAStoppedRecordAsStoppedNotFailed(): void
    {
        $recording = $this->recordingListener();
        $supervisor = (new BackgroundSupervisor())->withListener($recording);
        $supervisor->addSession($this->runningSession('s-stopped'));
        $buffer = $this->fixtureFile("[session:task:start]\npartial\n[session:task:stopped]\n[session:daemon:exit]\n");
        $this->injectIpc($supervisor, 's-stopped', $buffer, $this->deadPid(), null);

        $supervisor->tick();

        $this->assertSame(BackgroundSessionStatus::Stopped, $supervisor->getSession('s-stopped')->status);
        $this->assertSame(['stopped'], $recording->events);

        // A listener that only knows the original contract hears a stop as
        // the session not completing, and can read why off its status.
        $plain = $this->plainListener();
        $legacy = (new BackgroundSupervisor())->withListener($plain);
        $legacy->addSession($this->runningSession('s-stopped'));
        $this->injectIpc($legacy, 's-stopped', $buffer, $this->deadPid(), null);
        $legacy->tick();
        $this->assertSame(['failed:stopped'], $plain->events);
    }

    // ---------------------------------------------------------------------

    private function runningSession(string $id): BackgroundSession
    {
        return (new BackgroundSession(
            id: $id,
            name: "Session {$id}",
            agent: new Agent('a', 'a', '', 'm', 'p', [], [], [], true),
            task: 'task',
            workingDirectory: sys_get_temp_dir(),
        ))->withStatus(BackgroundSessionStatus::Running);
    }

    private function injectIpc(BackgroundSupervisor $supervisor, string $id, string $bufferPath, int $pid, ?int $startTime): void
    {
        $prop = new \ReflectionProperty(BackgroundSupervisor::class, 'sessionIpc');
        $ipc = $prop->getValue($supervisor);
        $ipc[$id] = [
            'socketPath' => $bufferPath . '.sock',
            'bufferPath' => $bufferPath,
            'tokenPath' => $bufferPath . '.token',
            'pid' => $pid,
            'startTime' => $startTime,
        ];
        $prop->setValue($supervisor, $ipc);
    }

    private function fixtureFile(string $content): string
    {
        $path = $this->fixtureHome . '/buffer-' . bin2hex(random_bytes(4));
        file_put_contents($path, $content);
        $this->fixtureFiles[] = $path;

        return $path;
    }

    /**
     * A long-lived process this test owns, returned by pid once it is ready.
     * With $trapsTerm it ignores SIGTERM and holds a `sleep` child, which is
     * the shape of a wedged daemon with a worker below it.
     */
    private function startStandIn(bool $trapsTerm = false, string $childPidFile = ''): int
    {
        $ready = $this->fixtureHome . '/standin-' . bin2hex(random_bytes(4)) . '.ready';
        $this->fixtureFiles[] = $ready;
        $code = $trapsTerm
            ? 'pcntl_async_signals(true); pcntl_signal(SIGTERM, function () {});'
                . '$c = proc_open(["sleep", "30"], [["file", "/dev/null", "r"], ["file", "/dev/null", "a"], ["file", "/dev/null", "a"]], $p);'
                . 'file_put_contents(' . var_export($childPidFile, true) . ', proc_get_status($c)["pid"]);'
            : '';
        $code .= 'file_put_contents(' . var_export($ready, true) . ', getmypid()); for ($i = 0; $i < 60; $i++) { sleep(1); }';

        $proc = proc_open(
            [PHP_BINARY, '-r', $code],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $this->standIns[] = $proc;

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && trim((string) @file_get_contents($ready)) === '') {
            usleep(20_000);
        }
        $pid = (int) trim((string) @file_get_contents($ready));
        $this->assertGreaterThan(1, $pid, 'the stand-in never became ready');

        return $pid;
    }

    /**
     * A second name for the session buffer, outside the IPC directory.
     *
     * Settling a session now deletes its IPC files (audit BG-2), so the stop
     * record the daemon wrote is read back through a hard link taken before
     * the stop. The daemon appends by path, so every later write lands on the
     * same inode the link names. Same filesystem: the fixture home and the IPC
     * directory both live in the system temp dir.
     *
     * @param array{socketPath: string, bufferPath: string, pid: int, tokenPath?: string, startTime?: int|null} $ipc
     */
    private function linkBufferView(array $ipc): string
    {
        $view = $this->fixtureHome . '/buffer-view-' . bin2hex(random_bytes(4));
        $this->assertTrue(link($ipc['bufferPath'], $view), 'could not hard-link the session buffer');

        return $view;
    }

    /**
     * @param array{socketPath: string, bufferPath: string, pid: int, tokenPath?: string, startTime?: int|null} $ipc
     */
    private function assertIpcReleased(array $ipc): void
    {
        foreach ([$ipc['socketPath'], $ipc['bufferPath'], $ipc['bufferPath'] . '.log', $ipc['tokenPath'] ?? ''] as $path) {
            $this->assertFileDoesNotExist($path, 'a settled session left an IPC file behind (audit BG-2)');
        }
        $this->assertDirectoryDoesNotExist(dirname($ipc['bufferPath']), 'the last settled session left its private IPC directory behind');
    }

    private function aliveNotZombie(int $pid): bool
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($stat)) {
            return false;
        }
        $state = substr(trim(substr($stat, (int) strrpos($stat, ')') + 1)), 0, 1);

        return $state !== 'Z' && $state !== 'X';
    }

    private function deadPid(): int
    {
        $proc = proc_open([PHP_BINARY, '-r', 'exit(0);'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']], $pipes);
        $this->assertIsResource($proc);
        $pid = (int) proc_get_status($proc)['pid'];
        proc_close($proc);

        return $pid;
    }

    private function recordingListener(): object
    {
        return new class () implements SessionNotificationInterface, SessionStopNotificationInterface {
            /** @var list<string> */
            public array $events = [];
            public function onSessionCompleted(BackgroundSession $session): void
            {
                $this->events[] = 'completed';
            }
            public function onSessionFailed(BackgroundSession $session): void
            {
                $this->events[] = 'failed';
            }
            public function onSessionStalled(BackgroundSession $session): void
            {
                $this->events[] = 'stalled';
            }
            public function onSessionResumed(BackgroundSession $session): void
            {
                $this->events[] = 'resumed';
            }
            public function onSessionStreaming(BackgroundSession $session, string $chunk): void
            {
            }
            public function onSessionStopped(BackgroundSession $session): void
            {
                $this->events[] = 'stopped';
            }
        };
    }

    private function plainListener(): object
    {
        return new class () implements SessionNotificationInterface {
            /** @var list<string> */
            public array $events = [];
            public function onSessionCompleted(BackgroundSession $session): void
            {
                $this->events[] = 'completed';
            }
            public function onSessionFailed(BackgroundSession $session): void
            {
                $this->events[] = 'failed:' . $session->status->value;
            }
            public function onSessionStalled(BackgroundSession $session): void
            {
            }
            public function onSessionResumed(BackgroundSession $session): void
            {
            }
            public function onSessionStreaming(BackgroundSession $session, string $chunk): void
            {
            }
        };
    }
}
