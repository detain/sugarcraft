<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\App\BackgroundSessionStoppedMsg;
use SugarCraft\Code\Background\BackgroundSession;
use SugarCraft\Code\Background\BackgroundSessionStatus;
use SugarCraft\Code\Background\BackgroundStopOutcome;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Background\SessionNotificationInterface;
use SugarCraft\Code\Background\SessionStopNotificationInterface;
use SugarCraft\Code\Host\Commands\BackgroundCommand;

/**
 * The `/bg stop` thunk answers every stop, including one whose listener throws while
 * the supervisor settles an already-finished daemon. That settle runs synchronously
 * inside stopSessionAsync(), so a throw there must become a CouldNotStop answer, not
 * an exception out of the Cmd.
 */
final class BackgroundStopThunkTest extends TestCase
{
    public function testAStopWhoseListenerThrowsWhileSettlingAFinishedDaemonIsAnsweredNotThrown(): void
    {
        $supervisor = new BackgroundSupervisor(new class implements SessionNotificationInterface, SessionStopNotificationInterface {
            public function onSessionCompleted(BackgroundSession $session): void
            {
            }

            public function onSessionFailed(BackgroundSession $session): void
            {
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

            public function onSessionStopped(BackgroundSession $session): void
            {
                throw new \RuntimeException('listener refused the stop');
            }
        });

        $id = 'sess_20260101000000_feedface';
        $buffer = $this->tempFile("[session:task:stopped] via=test\n");
        $this->injectFinishedDaemon($supervisor, $id, $buffer);

        $answer = null;
        $thrown = null;
        try {
            BackgroundCommand::stopThunk($supervisor, $id)()->then(
                static function (BackgroundSessionStoppedMsg $msg) use (&$answer): void {
                    $answer = $msg;
                },
            );
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNull($thrown, 'the stop thunk must never throw out of its Cmd');
        $this->assertInstanceOf(BackgroundSessionStoppedMsg::class, $answer, 'the stop must still be answered');
        $this->assertSame(BackgroundStopOutcome::CouldNotStop, $answer->outcome);
        $this->assertSame('listener refused the stop', $answer->error);
        @unlink($buffer);
    }

    /**
     * Register an active session whose daemon pid is gone, with a buffer that records a
     * stop, so stopSessionAsync() settles it straight away through the listener.
     */
    private function injectFinishedDaemon(BackgroundSupervisor $supervisor, string $id, string $buffer): void
    {
        $proc = proc_open(['true'], [], $pipes);
        $deadPid = (int) proc_get_status($proc)['pid'];
        proc_close($proc);

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
        $session = (new BackgroundSession(
            id: $id,
            name: 'stop thunk',
            agent: $agent,
            task: 'stopped while the listener refuses',
            workingDirectory: '.',
            timeoutSeconds: 30,
        ))->withStatus(BackgroundSessionStatus::Running);

        $sessions = new \ReflectionProperty(BackgroundSupervisor::class, 'sessions');
        $sessions->setValue($supervisor, [$id => $session]);

        $ipc = new \ReflectionProperty(BackgroundSupervisor::class, 'sessionIpc');
        $ipc->setValue($supervisor, [$id => [
            'socketPath' => $buffer . '.sock',
            'bufferPath' => $buffer,
            'tokenPath' => $buffer . '.token',
            'pid' => $deadPid,
            'startTime' => null,
        ]]);
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bg-stop-thunk-');
        $this->assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
