<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Live\AgentRunCards;
use SugarCraft\Crush\Agents\Live\AgentInbox;
use SugarCraft\Crush\Agents\Live\AgentMessage;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\TurnInbox;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\InterruptAgentTool;
use SugarCraft\Crush\Tools\BuiltIn\SendMessageTool;
use SugarCraft\Crush\Tools\BuiltIn\SubagentsTool;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 4.4: `Subagents` (`list`, `wait`, `cancel`) and `InterruptAgent`
 * over the run cards every delegated run keeps — what the session's agent
 * launched, how each ended and the id that continues it, a wait that wakes
 * on a finish or a reply, a soft cancel that leaves the run resumable, and an
 * interrupt that skips the rest of the run's step.
 */
final class SubagentsToolTest extends TestCase
{
    private string $storeDir;

    private string $session;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_subagents_' . bin2hex(random_bytes(6));
        $this->session = 'sa-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testBothToolsAreNoAskCatalogTools(): void
    {
        self::assertSame(ToolPermissionClass::NoAsk, ToolCatalog::permissionOf(SubagentsTool::NAME));
        self::assertSame(ToolPermissionClass::NoAsk, ToolCatalog::permissionOf(InterruptAgentTool::NAME));
        self::assertTrue(SubagentsTool::new()->execute(['action' => 'list'])->isError(), 'unbound, it refuses');
        self::assertTrue(InterruptAgentTool::new()->execute(['to' => 'x', 'text' => 'y'])->isError());
    }

    public function testListNamesEachRunHowItEndedAndTheIdThatContinuesIt(): void
    {
        $engine = $this->engine(new ScriptedProvider([new CompleteResponse(content: 'all clear')]), []);
        $this->task($engine)->execute(['id' => 'c', 'agent' => 'coder', 'prompt' => 'audit auth', 'description' => 'Audit the auth layer']);
        $card = AgentRunCards::runs($this->session)[0];

        $listed = SubagentsTool::new()->withEngine($engine)->execute(['action' => 'list']);

        self::assertFalse($listed->isError(), $listed->content());
        self::assertStringContainsString(
            sprintf('- %s · agent "coder" · complete · "Audit the auth layer" · resume id %s', $card['runId'], $card['resumeId']),
            $listed->content(),
        );
    }

    public function testARunMarkedRunningWhoseProcessIsGoneIsListedLost(): void
    {
        $this->card('gone_1', ['pid' => 2_147_483_000]);
        $engine = $this->engine(new ScriptedProvider([]), []);

        $listed = SubagentsTool::new()->withEngine($engine)->execute(['action' => 'list'])->content();

        self::assertStringContainsString('- gone_1 · agent "coder" · lost', $listed);
        $waited = SubagentsTool::new()->withEngine($engine)->execute(['action' => 'wait'])->content();
        self::assertStringStartsWith('Nothing to wait for', $waited, 'a lost run is not waited on');
    }

    public function testACardThatDoesNotNameItsOwnDirectoryIsNotARun(): void
    {
        $this->card('real_1');
        $root = AgentInbox::defaultRoot();
        self::assertNotNull($root);
        $forged = $root . '/' . $this->session . '/forged_1';
        @mkdir($forged, 0o700, true);
        file_put_contents($forged . '/' . AgentRunCards::CARD_FILE, json_encode([
            'runId' => 'someone_else', 'parent' => 'main', 'inboxSession' => 'other-session',
        ]));

        self::assertSame(['real_1'], array_column(AgentRunCards::runs($this->session), 'runId'));
    }

    public function testWaitReturnsWhenAWaitedRunFinishes(): void
    {
        $this->card('busy_1');
        $engine = $this->engine(new ScriptedProvider([]), []);
        $now = 0.0;
        $looks = 0;
        $session = $this->session;
        $tool = SubagentsTool::new()->withEngine($engine)->withWaitClock(
            static function (float $seconds) use (&$now, &$looks, $session): void {
                $now += $seconds;
                if (++$looks === 3) {
                    AgentRunCards::updateRun($session, 'busy_1', static fn (array $card): array => ['status' => 'complete', 'resumeId' => '0123456789abcdef'] + $card);
                }
            },
            static function () use (&$now): float {
                return $now;
            },
        );

        $result = $tool->execute(['action' => 'wait', 'ids' => ['busy_1'], 'timeout_seconds' => 60]);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringStartsWith('1 of the sub-agents you waited on finished.', $result->content());
        self::assertStringContainsString('busy_1 · agent "coder" · complete', $result->content());
        self::assertSame(3, $looks);
    }

    public function testWaitWakesOnAReplyAndOtherwiseStopsAtItsTimeout(): void
    {
        $this->card('busy_1');
        $engine = $this->engine(new ScriptedProvider([]), []);
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $idle = SubagentsTool::new()->withEngine($engine)->withWaitClock(static function (float $s) use (&$now): void {
            $now += $s;
        }, $clock);

        $timedOut = $idle->execute(['action' => 'wait', 'timeout_seconds' => 2]);

        self::assertStringStartsWith('Waited 2 s; 1 sub-agent is still running.', $timedOut->content());
        self::assertSame(2.0, $now, 'it waited exactly the timeout, never past it');

        $inbox = AgentInbox::forSession($this->session);
        self::assertNotNull($inbox);
        $inbox->send(SendMessageTool::MAIN, AgentMessage::new('agent:busy_1', 'need the API key name', \SugarCraft\Crush\Agents\Live\MessageMode::Note));
        $woken = $idle->execute(['action' => 'wait', 'timeout_seconds' => 60]);

        self::assertStringStartsWith('A sub-agent sent you a message.', $woken->content());
        self::assertStringContainsString("<subagent-message from=\"busy_1\">\nneed the API key name\n</subagent-message>", $woken->content());
    }

    public function testCancelStopsARunningSubAgentResumably(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'never reached'),
        ]);
        $engine = null;
        $cancelled = null;
        $probe = self::probe(function () use (&$engine, &$cancelled): void {
            $cancelled = SubagentsTool::new()->withEngine($engine)->execute(['action' => 'cancel', 'ids' => [AgentRunCards::runs($this->session)[0]['runId']]]);
        });
        $engine = $this->engine($provider, [$probe]);

        $result = $this->task($engine)->execute(['id' => 'c', 'agent' => 'coder', 'prompt' => 'look', 'description' => 'look']);

        self::assertInstanceOf(ToolResult::class, $cancelled);
        self::assertStringContainsString('cancel sent', $cancelled->content());
        self::assertTrue($result->isError());
        self::assertStringContainsString('was cancelled', $result->content());
        self::assertMatchesRegularExpression('/"resume": "[0-9a-f]{16}"/', $result->content(), 'it stays resumable');
        self::assertCount(1, $provider->requests, 'the next step never went out');
        self::assertSame('cancelled', AgentRunCards::runs($this->session)[0]['status']);
    }

    public function testCancelRefusesAnIdThatIsNotTheCallersSubAgent(): void
    {
        $engine = $this->engine(new ScriptedProvider([]), []);

        $result = SubagentsTool::new()->withEngine($engine)->execute(['action' => 'cancel', 'ids' => ['nobody']]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('"nobody" is not one of the sub-agents you launched', $result->content());
    }

    public function testAnInterruptSkipsTheRestOfTheStepAndIsReadFirst(): void
    {
        $engine = null;
        $interrupted = null;
        $first = self::probe(function () use (&$engine, &$interrupted): void {
            $interrupted = InterruptAgentTool::new()->withEngine($engine)->execute([
                'id' => 'i1',
                'to' => AgentRunCards::runs($this->session)[0]['runId'],
                'text' => 'stop: the spec changed',
            ]);
        });
        $second = self::probe(static function (): void {
            self::fail('an interrupted step must not start its next call');
        }, 'probe2');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', []), new ToolCall('c2', 'probe2', [])]),
            new CompleteResponse(content: 'replanned'),
        ]);
        $engine = $this->engine($provider, [$first, $second]);

        $result = $this->task($engine)->execute(['id' => 'c', 'agent' => 'coder', 'prompt' => 'look', 'description' => 'look']);

        self::assertInstanceOf(ToolResult::class, $interrupted);
        self::assertFalse($interrupted->isError(), $interrupted->content());
        self::assertFalse($result->isError(), $result->content());
        $messages = $provider->requests[1]->messages;
        $skipped = array_values(array_filter($messages, static fn ($m): bool => $m instanceof ToolResultMessage && $m->content() === TurnInbox::SKIPPED));
        self::assertCount(1, $skipped, 'the unstarted call was skipped');
        $framed = array_values(array_filter($messages, static fn ($m): bool => $m instanceof UserMessage && str_starts_with($m->content(), '<parent-message from="main" mode="interrupt">')));
        self::assertCount(1, $framed);
        self::assertStringContainsString('stop: the spec changed', $framed[0]->content());
    }

    public function testAFinishedSubAgentCannotBeInterrupted(): void
    {
        $this->card('done_1', ['status' => 'complete']);
        $engine = $this->engine(new ScriptedProvider([]), []);

        $result = InterruptAgentTool::new()->withEngine($engine)->execute(['to' => 'done_1', 'text' => 'stop']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('has complete, so there is no step to interrupt; SendMessage', $result->content());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function card(string $runId, array $fields = []): void
    {
        self::assertTrue(AgentRunCards::recordRun($this->session, $fields + [
            'runId' => $runId,
            'agent' => 'coder',
            'parent' => SendMessageTool::MAIN,
            'inboxSession' => $this->session,
            'status' => AgentRunCards::STATUS_RUNNING,
            'pid' => (int) getmypid(),
            'startedAt' => (int) floor(microtime(true) * 1000),
        ]));
    }

    /**
     * @param list<Tool> $extra
     */
    private function engine(ScriptedProvider $provider, array $extra): EngineBackend
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register(RosterAgent::named('coder', maxTurns: 5));

        return EngineBackend::new($provider, 'm')
            ->withTools([...$extra, new TaskTool($manager, suspended: new SuspendedDelegations($this->storeDir))])
            ->withSessionId($this->session);
    }

    private function task(EngineBackend $engine): TaskTool
    {
        foreach ($engine->tools() as $tool) {
            if ($tool instanceof TaskTool) {
                return $tool->withEngine($engine);
            }
        }
        self::fail('no Task tool');
    }

    private static function probe(\Closure $during, string $name = 'probe'): Tool
    {
        return new class ($during, $name) implements Tool {
            public function __construct(private \Closure $during, private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'acts while it runs';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                ($this->during)();

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'probed');
            }
        };
    }
}
