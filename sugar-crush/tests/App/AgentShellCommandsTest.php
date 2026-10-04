<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\AgentControlMsg;
use SugarCraft\Crush\AgentMessageSentMsg;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Live\AgentInbox;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tui\Commands\CancelAgentCmd;
use SugarCraft\Crush\Tui\Commands\GroupInputCmd;
use SugarCraft\Crush\Tui\Commands\ResumeAgentCmd;
use SugarCraft\Crush\Tui\Commands\StopAllAgentsCmd;
use SugarCraft\Crush\Tui\Components\AgentDashboardPane;

/**
 * Roadmap P-D3: the four shell commands that used to be inert —
 * `CancelAgentCmd`, `ResumeAgentCmd`, `StopAllAgentsCmd`, `GroupInputCmd` —
 * and the Agent View's `Ctrl+X` chords now act on the delegated runs, each
 * translated by the shell into an {@see AgentControlMsg} the hosted chat
 * delivers through the runs' mailboxes.
 */
final class AgentShellCommandsTest extends TestCase
{
    private string $session;

    private CancellationToken $token;

    protected function setUp(): void
    {
        Renderer::setAgentView(null);
        $this->session = 'shell-cmds-' . bin2hex(random_bytes(5));
        $this->token = new CancellationToken();
    }

    protected function tearDown(): void
    {
        Renderer::setAgentView(null);
    }

    public function testCancelAsksSoftlyThenStopsOnTheSecondPress(): void
    {
        $app = $this->app();
        $row = $this->rowOf($app, 'run-2');

        [$asked, $cmd] = $app->consumeShellCmd(new CancelAgentCmd($row));
        $this->assertNull($cmd);
        $this->assertSame(['cancel'], $this->controls('run-2'));
        $this->assertSame([], $this->token->takeToolCancels(), 'the first press is soft');
        $this->assertStringContainsString('again within 3 s', (string) $asked->status);

        [$stopped] = $asked->consumeShellCmd(new CancelAgentCmd($row));
        $this->assertSame(['call_2'], $this->token->takeToolCancels(), 'the second stops the run\'s Task call now');
        $this->assertNull($stopped->agentCancelArmed, 'and the arm is spent');
    }

    public function testCancelOfAnotherRunIsAFreshFirstPress(): void
    {
        $app = $this->app();
        [$asked] = $app->consumeShellCmd(new CancelAgentCmd($this->rowOf($app, 'run-2')));
        $asked->consumeShellCmd(new CancelAgentCmd($this->rowOf($app, 'run-1')));

        $this->assertSame([], $this->token->takeToolCancels());
        $this->assertSame(['cancel'], $this->controls('run-1'));
    }

    public function testStopAllAsksEveryRunningRunAndNoFinishedOne(): void
    {
        $app = $this->app(finishedThird: true);

        $app->consumeShellCmd(new StopAllAgentsCmd());

        $this->assertSame(['cancel'], $this->controls('run-1'));
        $this->assertSame(['cancel'], $this->controls('run-2'));
        $this->assertSame([], $this->controls('run-3'), 'a finished run has nothing to stop');
    }

    public function testResumeLetsAPausedRunGoOn(): void
    {
        $app = $this->app();
        $view = $app->openAgentView('run-2');
        [$paused] = $view->update(self::ctrlX())[0]->update(new KeyMsg(KeyType::Char, 'p'));
        $this->assertTrue($paused->isAgentPaused('run-2'));
        $this->assertSame(['pause'], $this->controls('run-2'));

        [$going] = $paused->consumeShellCmd(new ResumeAgentCmd($this->rowOf($app, 'run-2')));

        $this->assertSame(['resume'], $this->controls('run-2'));
        $this->assertFalse($going->isAgentPaused('run-2'));
    }

    public function testResumeOnAFinishedRunContinuesIt(): void
    {
        $app = $this->app(finishedThird: true);

        [$next, $cmd] = $app->consumeShellCmd(new ResumeAgentCmd($this->rowOf($app, 'run-3')));

        // This chat runs on the echo backend, which cannot continue a run —
        // what is asserted is that a finished run is CONTINUED, not mailed,
        // and that the reason it could not be is said.
        $this->assertSame([], $this->controls('run-3'), 'no mailbox line for a run nobody reads');
        $this->assertInstanceOf(\Closure::class, $cmd);
        $answer = $cmd();
        $this->assertInstanceOf(AgentMessageSentMsg::class, $answer);
        $this->assertSame('Continue.', $answer->text);
        $this->assertSame(AgentMessageSentMsg::FAILED, $answer->status);
        $this->assertStringContainsString('engine backend', (string) $answer->detail);
        $this->assertNotSame($app, $next);
    }

    public function testADashboardRowThatIsNotARunIsRefusedOnTheStatusLine(): void
    {
        $app = $this->app()->withChat(null);

        [$next, $cmd] = $app->consumeShellCmd(new CancelAgentCmd(0));

        $this->assertNull($cmd);
        $this->assertSame('No agent to stop.', $next->status);
    }

    public function testGroupInputBroadcastsTheComposerToEveryRunningRun(): void
    {
        [$group] = $this->app()->consumeShellCmd(new GroupInputCmd());

        $this->assertTrue($group->agentBroadcast);
        $this->assertSame('run-3', $group->agentViewTarget, 'it opened the view on the newest running run');
        $this->assertSame(['run-1', 'run-2', 'run-3'], $group->agentComposerTargets());
        $this->assertStringContainsString('message all 3 agents…', self::plain($group));

        [$single] = $group->consumeShellCmd(new GroupInputCmd());
        $this->assertFalse($single->agentBroadcast, 'the second press turns it off');
        $this->assertSame(['run-3'], $single->agentComposerTargets());
        $this->assertStringContainsString('message explore…', self::plain($single));
    }

    public function testGroupInputWithNothingRunningSaysSo(): void
    {
        $app = App::new($this->createMock(ProviderInterface::class), 'm')->withChat(new Chat());

        [$next, $cmd] = $app->consumeShellCmd(new GroupInputCmd());

        $this->assertNull($cmd);
        $this->assertFalse($next->agentBroadcast);
        $this->assertSame('No agent is running to message.', $next->status);
    }

    public function testLeavingTheViewDropsTheBroadcastAndTheLeader(): void
    {
        [$group] = $this->app()->consumeShellCmd(new GroupInputCmd());
        [$armed] = $group->update(self::ctrlX());
        $this->assertTrue($armed->agentViewLeader);

        $closed = $armed->closeAgentView();
        $this->assertFalse($closed->agentBroadcast);
        $this->assertFalse($closed->agentViewLeader);
    }

    public function testTheLeaderSwallowsAnUnboundKeyAndEsc(): void
    {
        $view = $this->app()->openAgentView('run-2');

        [$swallowed] = $view->update(self::ctrlX())[0]->update(new KeyMsg(KeyType::Char, 'z'));
        $this->assertFalse($swallowed->agentViewLeader);
        $this->assertSame('', $swallowed->chat?->inputBuf, 'the z after the leader was not typed');

        [$escaped] = $view->update(self::ctrlX())[0]->update(new KeyMsg(KeyType::Escape));
        $this->assertSame('run-2', $escaped->agentViewTarget, 'Esc after the leader drops the leader, not the view');

        [$background] = $view->update(self::ctrlX())[0]->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame('', $background->chat?->inputBuf, 'b is claimed while it waits for roadmap 4.3');
    }

    public function testOpenAsSessionWaitsForTheRunToFinishAndTheTurnToEnd(): void
    {
        $live = $this->app()->openAgentView('run-2');
        [$refused] = $live->update(self::ctrlX())[0]->update(new KeyMsg(KeyType::Char, 'o'));
        $this->assertNull($refused->agentViewTarget, 'the view gives way to the main transcript, where the answer is');
        $history = $refused->chat?->history ?? [];
        $this->assertStringContainsString('explore opens as a session once it has finished', $history[array_key_last($history)]->content);
        $this->assertSame($this->session, $refused->chat?->currentSessionId(), 'and the session on screen is unchanged');
    }

    public function testEveryControlVerbIsKnownAndOnlyThose(): void
    {
        $this->assertSame(['message', 'cancel', 'stop', 'pause', 'resume', 'open-session'], AgentControlMsg::VERBS);
        $this->expectException(\InvalidArgumentException::class);
        new AgentControlMsg('explode', ['run-1']);
    }

    private function app(bool $finishedThird = false): App
    {
        $manager = new AgentManager(new \SugarCraft\Crush\Tests\Support\ScriptedProvider([]), new SkillRegistry());
        $manager->register(RosterAgent::named('explore'));
        $history = [Message::user('go')];
        foreach ([1, 2, 3] as $n) {
            $history[] = Message::toolRunning(new ToolCall('Task', [], 'call_' . $n));
        }
        $chat = (new Chat(
            history: $history,
            backend: new EchoBackend(),
            inFlight: true,
            generation: 1,
            inFlightCancellation: $this->token,
            agentManager: $manager,
        ))->withCurrentSessionId($this->session)->withSize(110, 24);
        foreach ([1, 2, 3] as $n) {
            $started = new SubAgentActivity(SubAgentActivity::OP_STARTED, 'run-' . $n, 'explore', 'a task', 1, '', parentCallId: 'call_' . $n);
            $chat->agentLive()->apply($started);
            $manager->projectRemoteSubAgent($started);
        }
        if ($finishedThird) {
            $finished = new SubAgentActivity(
                SubAgentActivity::OP_FINISHED,
                'run-3',
                'explore',
                '',
                2,
                'done',
                parentCallId: 'call_3',
                outcome: SubAgentActivity::OUTCOME_COMPLETE,
                resumeId: '0123456789abcdef',
            );
            $chat->agentLive()->apply($finished);
            $manager->projectRemoteSubAgent($finished);
        }

        [$app] = App::new($this->createMock(ProviderInterface::class), 'm')
            ->withChat($chat)
            ->update(new WindowSizeMsg(110, 24));

        return $app;
    }

    private function rowOf(App $app, string $run): int
    {
        foreach (AgentDashboardPane::entries($app) as $index => $entry) {
            if ($entry->key === $run) {
                return $index;
            }
        }
        $this->fail("fixture: no dashboard row for {$run}");
    }

    /**
     * @return list<string> the control verbs waiting for $run, taken
     */
    private function controls(string $run): array
    {
        $inbox = AgentInbox::forSession($this->session);
        $this->assertNotNull($inbox);

        return array_map(static fn ($m): string => $m->text, $inbox->takeControls($run));
    }

    private static function ctrlX(): KeyMsg
    {
        return new KeyMsg(KeyType::Char, 'x', ctrl: true);
    }

    private static function plain(App $app): string
    {
        $view = $app->view();

        return (string) preg_replace('/\x{E000}[^\x{E001}]*\x{E001}|[\x{E000}-\x{F8FF}]/u', '', Ansi::strip(\is_string($view) ? $view : $view->body));
    }
}
