<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Live\AgentRunCards;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\SendMessageTool;
use SugarCraft\Crush\Tools\BuiltIn\SubagentsTool;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 4.4: `SendMessage` — the model steers a running sub-agent through
 * its mailbox, continues a finished one (only where the session's gate would
 * let the equivalent `Task` call through), keeps a followup for the next run,
 * and a sub-agent replies to the agent that launched it; nobody messages a
 * sibling.
 */
final class SendMessageToolTest extends TestCase
{
    private string $storeDir;

    private SuspendedDelegations $store;

    private string $session;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . '/sc_send_message_' . bin2hex(random_bytes(6));
        $this->store = new SuspendedDelegations($this->storeDir);
        $this->session = 'sm-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testItIsANoAskCatalogToolThatRefusesWhenUnbound(): void
    {
        self::assertSame(ToolPermissionClass::NoAsk, ToolCatalog::permissionOf(SendMessageTool::NAME));

        $result = SendMessageTool::new()->execute(['to' => 'x', 'text' => 'hi']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('not bound', $result->content());
    }

    public function testASteerReachesTheRunningSubAgentAtItsNextStep(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'probe', [])]),
            new CompleteResponse(content: 'checked both'),
        ]);
        $engine = null;
        $sent = null;
        $probe = self::probe(function () use (&$engine, &$sent): void {
            $runs = AgentRunCards::runs($this->session);
            self::assertCount(1, $runs, 'the running sub-agent has a card');
            self::assertSame(AgentRunCards::STATUS_RUNNING, AgentRunCards::statusOf($runs[0]));
            $sent = $this->main($engine)->execute(['id' => 'm1', 'to' => $runs[0]['runId'], 'text' => 'also check the cookie']);
        });
        $engine = $this->engine($provider, [$probe]);

        $result = $this->task($engine)->execute(self::call());

        self::assertInstanceOf(ToolResult::class, $sent);
        self::assertFalse($sent->isError(), $sent->content());
        self::assertStringContainsString('next step boundary', $sent->content());
        self::assertFalse($result->isError(), $result->content());
        $delivered = self::framed($provider->requests[1]);
        self::assertCount(1, $delivered, 'delivered once, at the step after the probe');
        self::assertStringStartsWith("<parent-message from=\"main\">\nalso check the cookie\n</parent-message>", $delivered[0]);
    }

    public function testASubAgentKeepsOnlyTheReplyToolAndItsReplyReachesTheSessionsAgent(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', SendMessageTool::NAME, ['to' => 'parent', 'text' => 'halfway: the bug is in auth.php'])]),
            new CompleteResponse(content: 'report'),
        ]);
        $engine = $this->engine($provider, [SubagentsTool::new(), \SugarCraft\Crush\Tools\BuiltIn\InterruptAgentTool::new()]);

        $result = $this->task($engine)->execute(self::call());

        self::assertFalse($result->isError(), $result->content());
        $offered = self::toolNames($provider->requests[0]);
        self::assertContains(SendMessageTool::NAME, $offered, 'the reply tool is kept');
        self::assertNotContains(SubagentsTool::NAME, $offered);
        self::assertNotContains(\SugarCraft\Crush\Tools\BuiltIn\InterruptAgentTool::NAME, $offered);

        $runId = AgentRunCards::runs($this->session)[0]['runId'];
        $listed = SubagentsTool::new()->withEngine($engine)->execute(['action' => 'list'])->content();
        self::assertStringContainsString("<subagent-message from=\"{$runId}\">\nhalfway: the bug is in auth.php\n</subagent-message>", $listed);
        self::assertStringContainsString('not the user\'s word', $listed);
        self::assertStringNotContainsString('halfway', SubagentsTool::new()->withEngine($engine)->execute(['action' => 'list'])->content(), 'each message is handed out once');
    }

    public function testAFinishedSubAgentIsContinuedWithTheMessageWhenTheGateAllowsTask(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'found two bugs'),
            new CompleteResponse(content: 'fixed both'),
        ]);
        $engine = $this->engine($provider, [], new PermissionGate(PermissionMode::BypassPermissions));
        $this->task($engine)->execute(self::call());
        $card = AgentRunCards::runs($this->session)[0];
        self::assertSame('complete', $card['status']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $card['resumeId']);

        $result = $this->main($engine)->execute(['id' => 'm1', 'to' => $card['runId'], 'text' => 'now fix them']);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringStartsWith('[continued finished sub-agent "coder" (resume id ' . $card['resumeId'] . ')', $result->content());
        self::assertStringContainsString('fixed both', $result->content());
        $turns = array_map(static fn (TypedMessage $m): string => $m->content(), $provider->requests[1]->messages);
        self::assertContains('found two bugs', $turns, 'the same conversation, not a stranger');
        self::assertContains('now fix them', $turns);
    }

    public function testContinuingAFinishedSubAgentIsRefusedWhereTaskWouldAsk(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'found two bugs')]);
        $engine = $this->engine($provider, [], new PermissionGate(PermissionMode::Default));
        $this->task($engine)->execute(self::call());
        $card = AgentRunCards::runs($this->session)[0];

        $result = $this->main($engine)->execute(['id' => 'm1', 'to' => $card['resumeId'], 'text' => 'now fix them']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('would ask the user about it', $result->content());
        self::assertStringContainsString(sprintf('call Task with agent "coder", resume "%s"', $card['resumeId']), $result->content());
        self::assertCount(1, $provider->requests, 'nothing ran');
    }

    public function testAFollowupIsKeptForTheRunThatContinuesTheConversation(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'found two bugs'),
            new CompleteResponse(content: 'fixed'),
            new CompleteResponse(content: 'fixed again'),
        ]);
        $engine = $this->engine($provider, []);
        $task = $this->task($engine);
        $task->execute(self::call());
        $card = AgentRunCards::runs($this->session)[0];

        $kept = $this->main($engine)->execute(['id' => 'm1', 'to' => $card['runId'], 'text' => 'use the new API', 'mode' => 'followup']);

        self::assertFalse($kept->isError(), $kept->content());
        self::assertStringContainsString('Kept for sub-agent', $kept->content());
        self::assertCount(1, $provider->requests, 'a followup wakes nothing');

        $task->execute(self::call(['resume' => $card['resumeId'], 'prompt' => 'continue']));
        $last = $provider->requests[1]->messages[\count($provider->requests[1]->messages) - 1];
        self::assertInstanceOf(UserMessage::class, $last);
        self::assertStringStartsWith("<parent-message from=\"main\" mode=\"followup\">\nuse the new API\n</parent-message>", $last->content());
        self::assertStringEndsWith("\n\ncontinue", $last->content());

        $task->execute(self::call(['resume' => $card['resumeId'], 'prompt' => 'again']));
        $again = $provider->requests[2]->messages[\count($provider->requests[2]->messages) - 1];
        self::assertSame('again', $again->content(), 'a followup is delivered once');
    }

    public function testNobodyMessagesASibling(): void
    {
        $engine = $this->engine(new ScriptedProvider([]), []);
        foreach (['sibling_a', 'sibling_b'] as $id) {
            AgentRunCards::recordRun($this->session, [
                'runId' => $id,
                'agent' => 'coder',
                'parent' => SendMessageTool::MAIN,
                'inboxSession' => $this->session,
                'pid' => (int) getmypid(),
            ]);
        }
        $asChild = SendMessageTool::new()->withEngine($engine)->asChildOf('sibling_a', SendMessageTool::MAIN, $this->session);

        $result = $asChild->execute(['id' => 'm1', 'to' => 'sibling_b', 'text' => 'hello']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('"sibling_b" is not a sub-agent you launched', $result->content());
        self::assertStringContainsString('never a sibling', $result->content());
    }

    public function testTheSessionsOwnAgentHasNoParent(): void
    {
        $result = $this->main($this->engine(new ScriptedProvider([]), []))->execute(['id' => 'm1', 'to' => 'parent', 'text' => 'hi']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('no parent to message', $result->content());
    }

    private function main(?EngineBackend $engine): SendMessageTool
    {
        self::assertNotNull($engine);

        return SendMessageTool::new()->withSuspendedDelegations($this->store)->withEngine($engine);
    }

    /**
     * The session's engine: $extra plus SendMessage and the Task tool, as a
     * launch has them.
     *
     * @param list<Tool> $extra
     */
    private function engine(ScriptedProvider $provider, array $extra, ?PermissionGate $gate = null): EngineBackend
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register(RosterAgent::named('coder', maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')
            ->withTools([...$extra, SendMessageTool::new(), new TaskTool($manager, suspended: $this->store)])
            ->withSessionId($this->session);

        return $gate === null ? $engine : $engine->withPermissionGate($gate);
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

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function call(array $overrides = []): array
    {
        return $overrides + ['id' => 'call_1', 'agent' => 'coder', 'prompt' => 'look around', 'description' => 'look'];
    }

    /**
     * The framed mailbox messages a request carries.
     *
     * @return list<string>
     */
    private static function framed(CompleteRequest $request): array
    {
        $out = [];
        foreach ($request->messages as $m) {
            if ($m instanceof UserMessage && preg_match('/^<(?:user|parent)-message/', $m->content()) === 1) {
                $out[] = $m->content();
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function toolNames(CompleteRequest $request): array
    {
        $names = [];
        foreach ($request->tools ?? [] as $tool) {
            $names[] = match (true) {
                $tool instanceof Tool => $tool->name(),
                \is_array($tool) => (string) ($tool['name'] ?? $tool['function']['name'] ?? ''),
                default => '',
            };
        }

        return $names;
    }

    private static function probe(\Closure $during): Tool
    {
        return new class ($during) implements Tool {
            public function __construct(private \Closure $during)
            {
            }

            public function name(): string
            {
                return 'probe';
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
