<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\BackgroundSessionSpawnedMsg;
use SugarCraft\Code\App\BackgroundTickMsg;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Tests\Sessions\StoppableDaemonFixtureTrait;
use SugarCraft\Code\Tests\Support\SupervisorPump;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * `/bg <task>` from an idle TUI must arm the background poll without another key.
 *
 * The spawn is registered with the supervisor only when its Cmd runs, and the
 * Msg carrying its promise does not reconcile subscriptions, so the poll is
 * armed only by a tick queued after that Cmd. The Program runs a batch's members
 * in order, so the batch here is driven the way the Program drives it.
 */
final class BackgroundSpawnPollWakeTest extends TestCase
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

    public function testIdleBgSpawnArmsThePollWithoutAnotherKey(): void
    {
        putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; printf REBUILT');
        $supervisor = new BackgroundSupervisor();

        [$next, $cmd] = $this->submit($supervisor, '/bg rebuild the docs');

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertFalse($this->pollArmed($next), 'nothing is in flight before the spawn runs');

        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch, 'the launch and the poll wake are one batch');
        $this->assertCount(2, $batch->cmds);

        // Member one registers the spawn. Its Msg is dispatched without a reconcile.
        $launched = ($batch->cmds[0])();
        $this->assertInstanceOf(AsyncCmd::class, $launched);

        // Member two is the wake: it runs after the spawn is registered.
        $wake = ($batch->cmds[1])();
        $this->assertInstanceOf(BackgroundTickMsg::class, $wake);

        [$woken] = $next->update($wake);
        $this->assertTrue($this->pollArmed($woken), 'the poll is armed by the wake, with no further key');

        // Settle the real spawn so its daemon is reaped with the rest of the fixture.
        $spawned = null;
        $launched->promise->then(static function ($msg) use (&$spawned): void {
            $spawned = $msg;
        });
        SupervisorPump::until($supervisor, static fn (): bool => $spawned !== null);
        $this->assertInstanceOf(BackgroundSessionSpawnedMsg::class, $spawned);
        $this->assertNull($spawned->error);
        $this->registerDaemon($supervisor, (string) $spawned->sessionId);
    }

    /** Whether the chat's subscriptions include the background poll tick. */
    private function pollArmed(Chat $chat): bool
    {
        $subscriptions = $chat->subscriptions();

        return $subscriptions !== null && $subscriptions->has('code.background-poll');
    }

    /** Hand the spawned daemon's files and process to the fixture, so teardown reaps them. */
    private function registerDaemon(BackgroundSupervisor $supervisor, string $sessionId): void
    {
        $ipc = $this->ipcOf($supervisor, $sessionId);
        $this->fixtureDaemons[] = ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];
        foreach (['socketPath', 'bufferPath', 'tokenPath'] as $key) {
            if (isset($ipc[$key])) {
                $this->fixtureFiles[] = $ipc[$key];
            }
        }
        $this->fixtureFiles[] = $ipc['bufferPath'] . '.log';
    }

    /** @return array{0: Chat, 1: ?\Closure} */
    private function submit(BackgroundSupervisor $supervisor, string $line): array
    {
        $chat = new Chat(inputBuf: $line, backgroundSupervisor: $supervisor);
        if ($chat->slashMenuMatches() !== []) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        }

        return $chat->update(new KeyMsg(KeyType::Enter, ''));
    }
}
