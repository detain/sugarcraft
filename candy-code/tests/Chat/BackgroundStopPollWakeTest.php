<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\BackgroundSessionStoppedMsg;
use SugarCraft\Code\App\BackgroundTickMsg;
use SugarCraft\Code\Background\BackgroundStopOutcome;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Tests\Sessions\StoppableDaemonFixtureTrait;
use SugarCraft\Code\Tests\Support\SupervisorPump;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * `/bg stop <id>` from an idle TUI must queue the background poll's wake-up, the
 * same way `/bg <task>` does (see {@see BackgroundSpawnPollWakeTest}). The stop
 * is driven only by the supervisor's loop, and nothing reconciles the poll's
 * subscription after the stop's Cmd runs, so without the tick the answer waits
 * for a key press or the next two-second poll cadence.
 */
final class BackgroundStopPollWakeTest extends TestCase
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

    public function testAnIdleStopRequestsTheBackgroundPollTick(): void
    {
        $supervisor = new BackgroundSupervisor();
        $spawned = $this->spawnSleepingDaemon($supervisor);
        $id = $spawned['session']->id;

        [$next, $cmd] = $this->submit($supervisor, '/bg stop ' . $id);

        $this->assertInstanceOf(\Closure::class, $cmd);

        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch, 'the stop and the poll wake are one batch');
        $this->assertCount(2, $batch->cmds);

        // Member one registers the stop with the supervisor; member two is the wake.
        $launched = ($batch->cmds[0])();
        $this->assertInstanceOf(AsyncCmd::class, $launched);
        $wake = ($batch->cmds[1])();
        $this->assertInstanceOf(BackgroundTickMsg::class, $wake, 'a stop from an idle TUI must queue a tick');

        [$woken] = $next->update($wake);
        $this->assertTrue($this->pollArmed($woken), 'the in-flight stop arms the poll with no further key');

        $stopped = null;
        $launched->promise->then(static function ($msg) use (&$stopped): void {
            $stopped = $msg;
        });
        SupervisorPump::until($supervisor, static fn (): bool => $stopped !== null);
        $this->assertInstanceOf(BackgroundSessionStoppedMsg::class, $stopped);
        $this->assertSame(BackgroundStopOutcome::StoppedViaIpc, $stopped->outcome);
    }

    /** Whether the chat's subscriptions include the background poll tick. */
    private function pollArmed(Chat $chat): bool
    {
        $subscriptions = $chat->subscriptions();

        return $subscriptions !== null && $subscriptions->has('code.background-poll');
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
