<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Sessions\SessionNotificationInterface;

final class BackgroundSupervisorTest extends TestCase
{
    private function makeAgent(): Agent
    {
        return new Agent(
            name: 'test-agent',
            description: 'Test agent',
            prompt: '',
            model: 'test-model',
            provider: 'test',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true
        );
    }

    private function makeSession(string $id, BackgroundSessionStatus $status): BackgroundSession
    {
        $session = new BackgroundSession(
            id: $id,
            name: "Session $id",
            agent: $this->makeAgent(),
            task: 'test task',
            workingDirectory: '/tmp',
        );
        return $session->withStatus($status);
    }

    public function testHasActiveSessionsReturnsFalseWhenEmpty(): void
    {
        $supervisor = new BackgroundSupervisor();
        $this->assertFalse($supervisor->hasActiveSessions());
    }

    public function testHasActiveSessionsReturnsTrueWhenActiveSessionExists(): void
    {
        $supervisor = new BackgroundSupervisor();
        $session = $this->makeSession('s1', BackgroundSessionStatus::Running);
        $supervisor->addSession($session);
        $this->assertTrue($supervisor->hasActiveSessions());
    }

    public function testGetActiveSessionsFiltersOnlyActive(): void
    {
        $supervisor = new BackgroundSupervisor();
        $active = $this->makeSession('s1', BackgroundSessionStatus::Running);
        $completed = $this->makeSession('s2', BackgroundSessionStatus::Completed);
        $supervisor->addSession($active);
        $supervisor->addSession($completed);

        $result = $supervisor->getActiveSessions();
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('s1', $result);
        $this->assertArrayNotHasKey('s2', $result);
    }

    public function testTickMarksStalledSession(): void
    {
        $supervisor = new BackgroundSupervisor();
        $session = $this->makeSession('s1', BackgroundSessionStatus::Running);
        // Force lastHeartbeat to 20 seconds ago via reflection
        $refl = new \ReflectionClass($session);
        $prop = $refl->getProperty('lastHeartbeat');
        $prop->setAccessible(true);
        $prop->setValue($session, time() - 20);
        $supervisor->addSession($session);

        $supervisor->tick();

        $this->assertSame(BackgroundSessionStatus::Stalled, $supervisor->getSession('s1')->status);
    }

    public function testTickUnmarksResumedSession(): void
    {
        $supervisor = new BackgroundSupervisor();
        $session = $this->makeSession('s1', BackgroundSessionStatus::Stalled);
        // Heartbeat is now recent
        $session->recordHeartbeat();
        $supervisor->addSession($session);

        $supervisor->tick();

        $this->assertSame(BackgroundSessionStatus::Running, $supervisor->getSession('s1')->status);
    }

    public function testRemoveSession(): void
    {
        $supervisor = new BackgroundSupervisor();
        $session = $this->makeSession('s1', BackgroundSessionStatus::Running);
        $supervisor->addSession($session);
        $supervisor->removeSession('s1');
        $this->assertNull($supervisor->getSession('s1'));
    }

    public function testWithListenerReturnsClone(): void
    {
        $supervisor = new BackgroundSupervisor();
        $listener = new class implements SessionNotificationInterface {
            public bool $completedCalled = false;
            public function onSessionCompleted(BackgroundSession $session): void { $this->completedCalled = true; }
            public function onSessionFailed(BackgroundSession $session): void {}
            public function onSessionStalled(BackgroundSession $session): void {}
            public function onSessionResumed(BackgroundSession $session): void {}
            public function onSessionStreaming(BackgroundSession $session, string $chunk): void {}
        };
        $clone = $supervisor->withListener($listener);
        $this->assertNotSame($supervisor, $clone);
    }

    public function testReconnectReturnsEmptyWhenAlreadyReconnected(): void
    {
        $supervisor = new BackgroundSupervisor();
        $session = $this->makeSession('s1', BackgroundSessionStatus::Running);
        $supervisor->addSession($session);

        // First reconnect returns the session
        $result1 = $supervisor->reconnect();
        $this->assertCount(1, $result1);

        // Second reconnect (already reconnected) returns empty
        $result2 = $supervisor->reconnect();
        $this->assertCount(0, $result2);
    }

    // =========================================================================
    // Daemon spawn plumbing (crush_feat.md section 5 E3)
    // =========================================================================

    public function testParseHandshakePidExtractsTheDaemonPid(): void
    {
        $this->assertSame(4321, BackgroundSupervisor::parseHandshakePid("HELLO:sess_x:4321\n"));
    }

    public function testParseHandshakePidRejectsAnythingElse(): void
    {
        // The pre-W3 daemon handshake was the bare session id — it carries no
        // pid, so it must fall back rather than be misread as one.
        $this->assertNull(BackgroundSupervisor::parseHandshakePid("sess_x\n"));
        $this->assertNull(BackgroundSupervisor::parseHandshakePid('HELLO:sess_x:not-a-pid'));
        $this->assertNull(BackgroundSupervisor::parseHandshakePid('HELLO:sess_x:0'));
        $this->assertNull(BackgroundSupervisor::parseHandshakePid(''));
    }

    public function testAutoloadPathResolvesToARealComposerAutoloader(): void
    {
        $path = BackgroundSupervisor::autoloadPath();
        $this->assertIsString($path);
        $this->assertFileExists($path);
    }

    public function testDaemonCodeBootstrapsTheRunnerWithTheSpawnedTask(): void
    {
        $code = (new BackgroundSupervisor())->buildSessionDaemonCode(
            socketPath: '/tmp/s.sock',
            bufferPath: '/tmp/s.buffer',
            sessionId: 'sess_x',
            task: 'summarize the audit',
            workingDirectory: '/tmp',
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            timeoutSeconds: 60,
        );

        // The pre-W3 daemon never ran the task at all: it looped on
        // HEARTBEAT/RESUME/STOP and exited. These assertions fail against it.
        $this->assertStringContainsString('BackgroundSessionRunner::main', $code);
        $this->assertStringContainsString('summarize the audit', $code);
        $this->assertStringContainsString('require $autoload;', $code);
        $this->assertStringContainsString('pcntl_fork', $code);
    }

    public function testTickTreatsAnAdvancingSessionBufferAsAHeartbeat(): void
    {
        $supervisor = new BackgroundSupervisor();
        $session = $this->makeSession('s1', BackgroundSessionStatus::Running);
        $this->ageHeartbeat($session, 20);
        $supervisor->addSession($session);

        $bufferPath = tempnam(sys_get_temp_dir(), 'crush_bg_tick_');
        file_put_contents($bufferPath, "[session:heartbeat] pid=1\n");
        $this->setIpc($supervisor, 's1', $bufferPath);

        try {
            $supervisor->tick();
        } finally {
            @unlink($bufferPath);
        }

        $this->assertSame(BackgroundSessionStatus::Running, $supervisor->getSession('s1')->status);
    }

    public function testTickStillStallsASessionWhoseBufferStoppedMoving(): void
    {
        $supervisor = new BackgroundSupervisor();
        $session = $this->makeSession('s1', BackgroundSessionStatus::Running);
        $supervisor->addSession($session);

        $bufferPath = tempnam(sys_get_temp_dir(), 'crush_bg_tick_');
        file_put_contents($bufferPath, "[session:heartbeat] pid=1\n");
        $this->setIpc($supervisor, 's1', $bufferPath);

        try {
            // First tick absorbs the initial mtime; the file never changes
            // afterwards, so the session must still be reported as stalled.
            $supervisor->tick();
            $this->ageHeartbeat($supervisor->getSession('s1'), 20);
            $supervisor->tick();
        } finally {
            @unlink($bufferPath);
        }

        $this->assertSame(BackgroundSessionStatus::Stalled, $supervisor->getSession('s1')->status);
    }

    public function testSpawnedSessionActuallyRunsItsTaskAndBecomesRetrievable(): void
    {
        foreach (['proc_open', 'pcntl_fork', 'posix_setsid', 'stream_socket_server'] as $fn) {
            if (!function_exists($fn)) {
                $this->markTestSkipped("{$fn}() unavailable");
            }
        }

        $previousCmd = getenv('SUGARCRUSH_BACKEND_CMD');
        $previousProvider = getenv('SUGARCRUSH_PROVIDER');
        putenv('SUGARCRUSH_PROVIDER=');
        putenv('SUGARCRUSH_BACKEND_CMD=cat >/dev/null; printf BGDONE42');

        $supervisor = new BackgroundSupervisor();
        $agent = new Agent(
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

        try {
            $session = $supervisor->spawnSession(
                name: 'bg task',
                agent: $agent,
                task: 'run the background work',
                workingDirectory: sys_get_temp_dir(),
                timeoutSeconds: 30,
            );

            $ipc = (new \ReflectionProperty(BackgroundSupervisor::class, 'sessionIpc'))
                ->getValue($supervisor)[$session->id];

            $deadline = microtime(true) + 20.0;
            $buffer = '';
            while (microtime(true) < $deadline) {
                $buffer = (string) @file_get_contents($ipc['bufferPath']);
                if (str_contains($buffer, '[session:daemon:exit]')) {
                    break;
                }
                usleep(100_000);
            }

            $this->assertStringContainsString('[session:task:start]', $buffer);
            $this->assertStringContainsString('BGDONE42', $buffer, 'the daemon never ran the task');
            $this->assertStringContainsString('[session:task:completed]', $buffer);

            // Wait for the daemon process itself to be gone so reconnect() sees
            // a settled session rather than a still-running one.
            while (microtime(true) < $deadline && posix_kill($ipc['pid'], 0)) {
                usleep(100_000);
            }

            $restored = $supervisor->reconnect();
            $this->assertArrayHasKey($session->id, $restored);
            $this->assertStringContainsString('BGDONE42', $restored[$session->id]->output);
            $this->assertSame(BackgroundSessionStatus::Completed, $restored[$session->id]->status);
        } finally {
            putenv($previousCmd === false ? 'SUGARCRUSH_BACKEND_CMD' : 'SUGARCRUSH_BACKEND_CMD=' . $previousCmd);
            putenv($previousProvider === false ? 'SUGARCRUSH_PROVIDER' : 'SUGARCRUSH_PROVIDER=' . $previousProvider);
            if (isset($ipc)) {
                @unlink($ipc['socketPath']);
                @unlink($ipc['bufferPath']);
                @unlink($ipc['bufferPath'] . '.log');
            }
        }
    }

    public function testTickReapsAFinishedDaemonInsteadOfCallingItStalled(): void
    {
        $listener = new class implements SessionNotificationInterface {
            /** @var list<string> */
            public array $events = [];
            public function onSessionCompleted(BackgroundSession $session): void { $this->events[] = 'completed'; }
            public function onSessionFailed(BackgroundSession $session): void { $this->events[] = 'failed'; }
            public function onSessionStalled(BackgroundSession $session): void { $this->events[] = 'stalled'; }
            public function onSessionResumed(BackgroundSession $session): void { $this->events[] = 'resumed'; }
            public function onSessionStreaming(BackgroundSession $session, string $chunk): void {}
        };
        $supervisor = (new BackgroundSupervisor())->withListener($listener);
        $session = $this->makeSession('s1', BackgroundSessionStatus::Running);
        // A finished daemon stops touching its buffer, so the heartbeat looks
        // exactly as dead as a wedged one - the pid is what tells them apart.
        $this->ageHeartbeat($session, 20);
        $supervisor->addSession($session);

        $bufferPath = tempnam(sys_get_temp_dir(), 'crush_bg_reap_');
        file_put_contents(
            $bufferPath,
            "[session:task:start]\nthe background answer\n[session:task:completed]\n[session:daemon:exit]\n"
        );
        $this->setIpc($supervisor, 's1', $bufferPath, $this->deadPid());

        try {
            $supervisor->tick();
        } finally {
            @unlink($bufferPath);
        }

        $settled = $supervisor->getSession('s1');
        $this->assertSame(BackgroundSessionStatus::Completed, $settled->status);
        $this->assertSame("the background answer\n", $settled->output);
        $this->assertSame(['completed'], $listener->events);
        $this->assertSame([], $supervisor->getActiveSessions());
    }

    public function testTickReportsAFinishedDaemonThatNeverCompletedAsFailed(): void
    {
        $listener = new class implements SessionNotificationInterface {
            /** @var list<string> */
            public array $events = [];
            public function onSessionCompleted(BackgroundSession $session): void { $this->events[] = 'completed'; }
            public function onSessionFailed(BackgroundSession $session): void { $this->events[] = 'failed'; }
            public function onSessionStalled(BackgroundSession $session): void { $this->events[] = 'stalled'; }
            public function onSessionResumed(BackgroundSession $session): void { $this->events[] = 'resumed'; }
            public function onSessionStreaming(BackgroundSession $session, string $chunk): void {}
        };
        $supervisor = (new BackgroundSupervisor())->withListener($listener);
        $supervisor->addSession($this->makeSession('s1', BackgroundSessionStatus::Running));

        $bufferPath = tempnam(sys_get_temp_dir(), 'crush_bg_reap_');
        file_put_contents($bufferPath, "[session:task:start]\n[session:task:failed] provider refused\n");
        $this->setIpc($supervisor, 's1', $bufferPath, $this->deadPid());

        try {
            $supervisor->tick();
        } finally {
            @unlink($bufferPath);
        }

        $this->assertSame(BackgroundSessionStatus::Failed, $supervisor->getSession('s1')->status);
        $this->assertSame(['failed'], $listener->events);
    }

    public function testTickDoesNotFlapAStalledSessionBackToRunning(): void
    {
        $supervisor = new BackgroundSupervisor();
        $session = $this->makeSession('s1', BackgroundSessionStatus::Running);
        $this->ageHeartbeat($session, 20);
        $supervisor->addSession($session);

        $supervisor->tick();
        $this->assertSame(BackgroundSessionStatus::Stalled, $supervisor->getSession('s1')->status);

        // Marking a session Stalled must not itself count as a heartbeat, or
        // the transcript fills with alternating stalled/running notices.
        $supervisor->tick();
        $this->assertSame(BackgroundSessionStatus::Stalled, $supervisor->getSession('s1')->status);
    }

    private function ageHeartbeat(BackgroundSession $session, int $seconds): void
    {
        $prop = new \ReflectionProperty(BackgroundSession::class, 'lastHeartbeat');
        $prop->setValue($session, time() - $seconds);
    }

    /**
     * Register IPC state for a session. The pid defaults to this very test
     * process so the session reads as a LIVE daemon — a dead pid is the
     * supervisor's completion signal and would settle the session instead.
     */
    private function setIpc(BackgroundSupervisor $supervisor, string $id, string $bufferPath, ?int $pid = null): void
    {
        $prop = new \ReflectionProperty(BackgroundSupervisor::class, 'sessionIpc');
        $ipc = $prop->getValue($supervisor);
        $ipc[$id] = [
            'socketPath' => $bufferPath . '.sock',
            'bufferPath' => $bufferPath,
            'pid' => $pid ?? getmypid(),
        ];
        $prop->setValue($supervisor, $ipc);
    }

    /** A pid that is guaranteed to be gone: spawn a process and wait it out. */
    private function deadPid(): int
    {
        $proc = proc_open(
            escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('exit(0);'),
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']],
            $pipes
        );
        $this->assertIsResource($proc);
        $pid = (int) proc_get_status($proc)['pid'];
        proc_close($proc);

        return $pid;
    }

    public function testOnSessionStreamingAppendsOutput(): void
    {
        $supervisor = new BackgroundSupervisor();
        $session = $this->makeSession('s1', BackgroundSessionStatus::Running);
        $supervisor->addSession($session);

        // Simulate streaming chunks
        $supervisor->onSessionStreaming($session, 'Hello, ');
        $supervisor->onSessionStreaming($session, 'World!');

        // Verify output is accumulated in the session stored in supervisor
        $updatedSession = $supervisor->getSession('s1');
        $this->assertSame('Hello, World!', $updatedSession->output);
    }
}
