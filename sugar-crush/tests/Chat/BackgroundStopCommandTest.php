<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\BackgroundSessionSpawnedMsg;
use SugarCraft\Crush\BackgroundSessionStoppedMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Sessions\BackgroundStopOutcome;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Tests\Sessions\StoppableDaemonFixtureTrait;

/**
 * `/bg stop <id>` (audit BG-1): the user-facing door to
 * {@see BackgroundSupervisor::stopSession()}.
 *
 * The parsing rule is the risky half — "stop" is an ordinary first word for a
 * task — so it is pinned from both sides: an id (known, or id-shaped) makes it
 * the sub-command, and prose after `stop` still backgrounds a task.
 */
final class BackgroundStopCommandTest extends TestCase
{
    use StoppableDaemonFixtureTrait;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['proc_open', 'pcntl_fork', 'pcntl_signal', 'posix_kill', 'posix_setsid', 'stream_socket_server'] as $fn) {
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

    public function testBgStopWithASpawnedSessionIdStopsIt(): void
    {
        $supervisor = new BackgroundSupervisor();
        $spawned = $this->spawnSleepingDaemon($supervisor);
        $id = $spawned['session']->id;

        [$next, $cmd] = $this->submit($supervisor, "/bg stop {$id}");

        // Off-turn, like the spawn: the prompt is free before the stop lands.
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertSame('', $next->inputBuf);
        $this->assertSame("/bg stop {$id}", $next->history[count($next->history) - 1]->content);
        $this->assertTrue($supervisor->getSession($id)->isActive(), 'update() itself must not block on the stop');

        $stopped = $this->resolve($cmd);
        $this->assertInstanceOf(BackgroundSessionStoppedMsg::class, $stopped);
        $this->assertSame(BackgroundStopOutcome::StoppedViaIpc, $stopped->outcome);
        $this->assertSame(BackgroundSessionStatus::Stopped, $supervisor->getSession($id)->status);

        [$reported] = $next->update($stopped);
        $this->assertSame("Stopped background session {$id} ('stoppable').", $this->lastAssistant($reported));
        $this->assertSame('stopped', $reported->backgroundStatuses()[$id] ?? null, 'the poll would announce the stop a second time');
    }

    public function testBackgroundAliasAndAKnownNonShapedIdRouteToStop(): void
    {
        $supervisor = new BackgroundSupervisor();
        // Registered but never spawned: no daemon to address.
        $supervisor->addSession($this->session('custom-id'));

        [, $cmd] = $this->submit($supervisor, '/background stop custom-id');
        $stopped = $this->resolve($cmd);

        $this->assertInstanceOf(BackgroundSessionStoppedMsg::class, $stopped);
        $this->assertSame(BackgroundStopOutcome::CouldNotStop, $stopped->outcome);
        [$reported] = (new Chat(backgroundSupervisor: $supervisor))->update($stopped);
        $this->assertStringStartsWith("Could not stop background session custom-id ('Session custom-id')", $this->lastAssistant($reported));
    }

    public function testAnIdShapedButUnknownSessionIsReportedAsUnknown(): void
    {
        $chat = new Chat(backgroundSupervisor: new BackgroundSupervisor());

        [, $cmd] = $this->submit(new BackgroundSupervisor(), '/bg stop sess_20260101000000_deadbeef');
        $stopped = $this->resolve($cmd);

        $this->assertInstanceOf(BackgroundSessionStoppedMsg::class, $stopped);
        $this->assertSame(BackgroundStopOutcome::UnknownSession, $stopped->outcome);
        [$reported] = $chat->update($stopped);
        $this->assertStringStartsWith('No background session sess_20260101000000_deadbeef', $this->lastAssistant($reported));
    }

    public function testBareBgStopShowsUsageAndTheActiveSessionIds(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->session('sess_20260101000000_0badf00d'));

        [$next, $cmd] = $this->submit($supervisor, '/bg stop');

        $this->assertNull($cmd, 'a bare /bg stop must not background the word "stop"');
        $this->assertSame(
            "Usage: /bg stop <session-id>\nActive background sessions: sess_20260101000000_0badf00d ('Session sess_20260101000000_0badf00d')",
            $this->lastAssistant($next),
        );

        [$empty] = $this->submit(new BackgroundSupervisor(), '/bg stop');
        $this->assertSame("Usage: /bg stop <session-id>\nNo active background sessions.", $this->lastAssistant($empty));
    }

    public function testAProseTaskThatStartsWithStopIsStillBackgrounded(): void
    {
        putenv('SUGARCRUSH_BACKEND_CMD=cat >/dev/null; printf REBUILT');
        $supervisor = new BackgroundSupervisor();

        foreach (['/bg stop the dev server and rebuild', '/bg stop everything'] as $line) {
            [, $cmd] = $this->submit($supervisor, $line);
            $spawned = $this->resolve($cmd);

            $this->assertInstanceOf(BackgroundSessionSpawnedMsg::class, $spawned, "{$line} was not backgrounded");
            $this->assertNull($spawned->error);
            $this->assertNotNull($spawned->sessionId);
            $this->assertStringStartsWith('stop ', strtolower($spawned->name));

            $ipc = $this->ipcOf($supervisor, $spawned->sessionId);
            $this->fixtureDaemons[] = ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];
            array_push($this->fixtureFiles, $ipc['socketPath'], $ipc['bufferPath'], $ipc['bufferPath'] . '.log', $ipc['tokenPath'] ?? '');

            $session = $supervisor->getSession($spawned->sessionId);
            $this->assertStringStartsWith('stop ', $session->task, 'the whole argument, "stop" included, is the task');

            [$reported] = (new Chat(backgroundSupervisor: $supervisor))->update($spawned);
            $this->assertStringContainsString("/bg stop {$spawned->sessionId} to cancel", $this->lastAssistant($reported));
        }
    }

    // ---------------------------------------------------------------------

    /** @return array{0: Chat, 1: ?\Closure} */
    private function submit(BackgroundSupervisor $supervisor, string $line): array
    {
        $chat = new Chat(inputBuf: $line, backgroundSupervisor: $supervisor);
        if ($chat->slashMenuMatches() !== []) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        }

        return $chat->update(new KeyMsg(KeyType::Enter, ''));
    }

    private function resolve(?\Closure $cmd): mixed
    {
        $this->assertInstanceOf(\Closure::class, $cmd);
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);
        $resolved = null;
        $async->promise->then(static function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    private function lastAssistant(Chat $chat): string
    {
        for ($i = count($chat->history) - 1; $i >= 0; $i--) {
            if ($chat->history[$i]->role === Role::Assistant) {
                return $chat->history[$i]->content;
            }
        }

        return '';
    }

    private function session(string $id): BackgroundSession
    {
        return (new BackgroundSession(
            id: $id,
            name: "Session {$id}",
            agent: new Agent('a', 'a', '', 'm', 'p', [], [], [], true),
            task: 'task',
            workingDirectory: sys_get_temp_dir(),
        ))->withStatus(BackgroundSessionStatus::Running);
    }
}
