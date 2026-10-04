<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Crush\AgentMessageSentMsg;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Live\AgentLiveRegistry;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Host\AgentResume;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap P-D2 "cold resume" (Appendix P §5.3): a message to a FINISHED run
 * continues it — a follow-up run of the same conversation, detached from any
 * parent turn — instead of going to a mailbox nobody reads any more.
 */
final class ColdResumeFinishedAgentTest extends TestCase
{
    private string $storeDir;

    private SuspendedDelegations $store;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_cold_resume_' . bin2hex(random_bytes(6));
        $this->store = new SuspendedDelegations($this->storeDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testAResumerContinuesTheFinishedRunsConversation(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'found two bugs'),
            new CompleteResponse(content: 'fixed both'),
        ]);
        [$engine, $registry] = $this->finishedRun($provider, 'resume-unit');
        $state = $registry->all()[0];
        $this->assertNotNull($state->resumeId, 'fixture: the finished run named a resume id');

        $frames = [];
        $result = null;
        AgentResume::new($engine)->withoutFork()
            ->run('coder', (string) $state->resumeId, 'now fix what you found', 'audit', static function (SubAgentActivity $a) use (&$frames): void {
                $frames[] = $a;
            })
            ->then(static function (ToolResult $r) use (&$result): void {
                $result = $r;
            });

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('fixed both', $result->content());
        $this->assertStringContainsString('resumed 1 time)', $result->content(), 'the same delegation, continued');

        $turns = array_map(static fn ($m): array => [$m->role(), $m->content()], \SugarCraft\Crush\Context\TurnContextBlock::strip($provider->requests[1]->messages));
        $this->assertSame(['assistant', 'found two bugs'], $turns[\count($turns) - 2], 'the follow-up sees its own report');
        $this->assertSame(['user', 'now fix what you found'], $turns[\count($turns) - 1]);

        $ops = array_map(static fn (SubAgentActivity $a): string => $a->op, $frames);
        $this->assertSame(SubAgentActivity::OP_STARTED, $ops[0], 'its beats reach the caller');
        $this->assertSame(SubAgentActivity::OP_FINISHED, $ops[\count($ops) - 1]);
        $this->assertStringStartsWith(AgentResume::CALL_PREFIX, $frames[0]->parentCallId, 'under a follow-up call id, not a parent turn\'s');
    }

    public function testNothingToSayMeansContinue(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'part one'),
            new CompleteResponse(content: 'part two'),
        ]);
        [$engine, $registry] = $this->finishedRun($provider, 'resume-continue');

        AgentResume::new($engine)->withoutFork()->run('coder', (string) $registry->all()[0]->resumeId, '  ');

        $last = \SugarCraft\Crush\Context\TurnContextBlock::strip($provider->requests[1]->messages);
        $this->assertSame(AgentResume::CONTINUE_TEXT, end($last)->content());
    }

    public function testAnEngineWithoutATaskToolCannotResume(): void
    {
        $resume = AgentResume::new(EngineBackend::new(new ScriptedProvider([]), 'm'))->withoutFork();
        $this->assertFalse($resume->available());

        $result = null;
        $resume->run('coder', 'abc', 'go')->then(static function (ToolResult $r) use (&$result): void {
            $result = $r;
        });
        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertTrue($result->isError());
    }

    /**
     * The whole path, through the Agent View's composer: Enter on a finished
     * run starts a follow-up in a forked child, the view's row walks from
     * "follow-up running" to "replied", and the main transcript keeps one
     * ui-only row.
     */
    public function testTheComposerContinuesAFinishedRunInTheBackground(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('the detached follow-up forks; without ext-pcntl it runs in-process (covered above)');
        }

        $session = 'cold-' . bin2hex(random_bytes(4));
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'found two bugs'),
            new CompleteResponse(content: 'fixed both'),
        ]);
        [$engine, $registry, $manager] = $this->finishedRun($provider, $session);
        $runId = $registry->all()[0]->id;

        $chat = (new Chat(history: [Message::user('audit')], backend: $engine, agentManager: $manager))
            ->withCurrentSessionId($session)
            ->withSize(100, 24);
        foreach ($registry->all() as $state) {
            // The chat's own registry gets the same beats the fixture saw.
            $chat->agentLive()->apply(new SubAgentActivity(
                SubAgentActivity::OP_FINISHED,
                $state->id,
                $state->name,
                '',
                99,
                'found two bugs',
                parentCallId: $state->parentCallId,
                outcome: SubAgentActivity::OUTCOME_COMPLETE,
                resumeId: $state->resumeId,
            ));
        }
        foreach (mb_str_split('now fix them') as $rune) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$app] = App::new($this->createMock(ProviderInterface::class), 'm')
            ->withChat($chat)
            ->update(new WindowSizeMsg(100, 24));
        $app = $app->openAgentView($runId);

        [$app, $cmd] = $app->update(new KeyMsg(KeyType::Enter));
        $this->assertSame('', $app->chat?->inputBuf, 'the draft was taken');
        $history = $app->chat->history;
        $row = $history[array_key_last($history)];
        $this->assertStringContainsString('you → coder · now fix them', $row->content, 'the main transcript keeps a row');
        $this->assertTrue($row->uiOnly, 'that the main model is never shown');

        $this->assertInstanceOf(\Closure::class, $cmd);
        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch);
        [$starting, $async] = array_map(static fn (\Closure $c): mixed => $c(), $batch->cmds);
        $this->assertInstanceOf(AgentMessageSentMsg::class, $starting);
        $this->assertSame(AgentMessageSentMsg::RESUMING, $starting->status);
        [$app] = $app->update($starting);
        $this->assertStringContainsString('↻ follow-up running', self::plain($app));

        $this->assertInstanceOf(AsyncCmd::class, $async);
        $settled = null;
        $guard = Loop::addTimer(30.0, static fn () => Loop::stop());
        $async->promise->then(static function ($msg) use (&$settled): void {
            $settled = $msg;
            Loop::stop();
        });
        Loop::run();
        Loop::cancelTimer($guard);

        $this->assertInstanceOf(AgentMessageSentMsg::class, $settled, 'the follow-up settled');
        $this->assertSame(AgentMessageSentMsg::REPLIED, $settled->status, (string) $settled->detail);
        [$app] = $app->update($settled);
        $frame = self::plain($app);
        $this->assertStringContainsString('↩ replied', $frame);
        $this->assertStringNotContainsString('↻ follow-up running', $frame, 'one row per message');

        $followUps = array_filter(
            $app->chat->agentLive()->all(),
            static fn ($s): bool => str_starts_with($s->parentCallId, AgentResume::CALL_PREFIX),
        );
        $this->assertCount(1, $followUps, 'the follow-up\'s beats came back from the child');
        $this->assertTrue(array_values($followUps)[0]->isFinished());
    }

    /**
     * A run of agent `coder` that finished with "found two bugs", through a
     * bound Task tool on session $session: the engine (carrying the unbound
     * tool, as a session's does), the registry its beats folded into, and
     * the manager.
     *
     * @return array{0: EngineBackend, 1: AgentLiveRegistry, 2: AgentManager}
     */
    private function finishedRun(ScriptedProvider $provider, string $session): array
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry(), toolRegistry: [], toolUniverse: []);
        $manager->register(RosterAgent::named('coder', [], maxTurns: 5));
        $task = new TaskTool($manager, suspended: $this->store);
        $engine = EngineBackend::new($provider, 'm')->withTools([$task])->withSessionId($session);

        $registry = AgentLiveRegistry::new();
        $first = $task->withEngine($engine, null, static function (SubAgentActivity $a) use ($registry): void {
            $registry->apply($a);
        })->execute(['id' => 'call_1', 'agent' => 'coder', 'prompt' => 'audit candy-core', 'description' => 'audit']);
        $this->assertFalse($first->isError(), $first->content());

        return [$engine, $registry, $manager];
    }

    private static function plain(App $app): string
    {
        $view = $app->view();

        return (string) preg_replace('/\x{E000}[^\x{E001}]*\x{E001}|[\x{E000}-\x{F8FF}]/u', '', \SugarCraft\Core\Util\Ansi::strip(\is_string($view) ? $view : $view->body));
    }
}
