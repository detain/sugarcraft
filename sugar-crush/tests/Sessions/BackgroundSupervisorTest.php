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
