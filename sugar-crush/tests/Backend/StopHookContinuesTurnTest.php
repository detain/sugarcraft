<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Step 3.D-2: the turn loop dispatches `Stop` when the model answers without
 * calling a tool (`SubagentStop` on a delegated run). A refusing verdict keeps
 * the turn going — its reason is the next prompt — at most
 * {@see HookManager::MAX_STOP_CONTINUATIONS} times and never past the step
 * ceiling; a `"continue": false` verdict ends the turn and its stop reason
 * closes the reply. The same `"continue": false` on a TOOL call (3.D-1's
 * {@see HookResult::haltsTurn()}) ends the turn at the step boundary.
 */
final class StopHookContinuesTurnTest extends TestCase
{
    public function testARefusingStopHookContinuesTheTurnWithItsReason(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'first answer'),
            new CompleteResponse(content: 'second answer'),
        ]);
        $seen = [];
        $hooks = self::hooks(self::hook(HookEvent::Stop, 'tests-pass', static function (HookContext $context) use (&$seen): HookResult {
            $seen[] = json_decode($context->toolInput, true);

            return \count($seen) === 1 ? HookResult::deny('the tests are still red') : HookResult::allow();
        }));

        $reply = EngineBackend::new($provider, 'm')->withHooks($hooks)->complete([Message::user('fix it')]);

        self::assertSame('second answer', $reply->content);
        self::assertCount(2, $provider->requests, 'one refusal, one more step');
        $rows = TurnContextBlock::strip($provider->requests[1]->messages);
        $last = $rows[array_key_last($rows)];
        self::assertInstanceOf(UserMessage::class, $last, 'the hook\'s reason is the newest conversation row of the next request');
        self::assertSame('Stop hook "tests-pass" did not let you finish yet: the tests are still red', $last->content());
        self::assertSame('first answer', $rows[array_key_last($rows) - 1]->content(), 'the refused answer stays in the history ahead of it');
        self::assertSame(
            [
                ['stop_hook_active' => false, 'last_assistant_message' => 'first answer'],
                ['stop_hook_active' => true, 'last_assistant_message' => 'second answer'],
            ],
            $seen,
        );
        self::assertFalse($reply->stepsTruncated);
    }

    public function testContinuationsAreCappedPerTurn(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'again')]);
        $calls = 0;
        $hooks = self::hooks(self::hook(HookEvent::Stop, 'never-done', static function () use (&$calls): HookResult {
            $calls++;

            return HookResult::deny('not yet');
        }));

        $reply = EngineBackend::new($provider, 'm')->withHooks($hooks)->complete([Message::user('go')]);

        self::assertSame('again', $reply->content, 'the turn ends on its last answer once the cap is spent');
        self::assertCount(HookManager::MAX_STOP_CONTINUATIONS + 1, $provider->requests);
        self::assertSame(HookManager::MAX_STOP_CONTINUATIONS + 1, $calls, 'the chain still runs on the answer that ends the turn');
        self::assertFalse($reply->stepsTruncated, 'the model answered; the cap is not the step ceiling');
    }

    public function testContinuationsShareTheStepCeiling(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'again')]);
        $hooks = self::hooks(self::hook(HookEvent::Stop, 'never-done', static fn (): HookResult => HookResult::deny('not yet')));

        EngineBackend::new($provider, 'm')->withHooks($hooks)->withMaxSteps(3)->complete([Message::user('go')]);

        self::assertCount(3, $provider->requests, 'a continuation is a step');
    }

    public function testContinueFalseOnStopEndsTheTurnWithTheStopReason(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'done'),
            new CompleteResponse(content: 'never sent'),
        ]);
        $hooks = self::hooks(self::hook(HookEvent::Stop, 'freeze', static fn (): HookResult => HookResult::stop('stop now', 'release freeze until Monday')));

        $reply = EngineBackend::new($provider, 'm')->withHooks($hooks)->complete([Message::user('ship it')]);

        self::assertCount(1, $provider->requests);
        self::assertSame("done\n\n[turn stopped by hook \"freeze\": release freeze until Monday]", $reply->content);
        self::assertSame(
            [],
            array_values(array_filter($reply->turnTranscript, static fn (Message $row): bool => $row->role === \SugarCraft\Crush\Role::Assistant)),
            'the reply is the step\'s own row, so it is not replayed twice',
        );
        self::assertFalse($reply->stepsTruncated);
    }

    public function testAToolHookAskingTheRunToStopEndsTheTurnAtTheStepBoundary(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'deploying', toolCalls: [new ToolCall('c1', 'deploy', [])]),
            new CompleteResponse(content: 'never sent'),
        ]);
        $hooks = self::hooks(self::hook(HookEvent::PreToolUse, 'freeze', static fn (): HookResult => HookResult::stop('deploys are frozen', 'release freeze')));

        $reply = EngineBackend::new($provider, 'm')
            ->withTools([self::tool()])
            ->withHooks($hooks)
            ->complete([Message::user('deploy')]);

        self::assertCount(1, $provider->requests, 'no provider call after the halting step');
        self::assertSame("deploying\n\n[turn stopped by hook \"freeze\": release freeze]", $reply->content);
        self::assertFalse($reply->stepsTruncated, 'a hook-requested stop is deliberate, not the step ceiling');
        $results = array_values(array_filter($reply->turnTranscript, static fn (Message $row): bool => $row->toolResults !== []));
        self::assertCount(1, $results);
        self::assertStringContainsString('deploys are frozen', $results[0]->toolResults[0]->error ?? '', 'the call itself was refused');
    }

    public function testADelegatedRunEndsOnSubagentStopNotStop(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'report')]);
        $fired = [];
        $record = static function (HookContext $context) use (&$fired): HookResult {
            $fired[] = [$context->toolName, json_decode($context->toolInput, true)];

            return HookResult::allow();
        };
        $hooks = self::hooks(
            self::hook(HookEvent::Stop, 'main', $record),
            self::hook(HookEvent::SubagentStop, 'sub', $record),
        );
        $engine = EngineBackend::new($provider, 'm')->withHooks($hooks);

        $turn = HookManager::runAsSubagent('agent-1', 'reviewer', static fn () => $engine->completeTranscript([new UserMessage('review')]));

        self::assertSame('report', $turn->reply->content);
        self::assertSame(
            [['SubagentStop', ['stop_hook_active' => false, 'last_assistant_message' => 'report', 'agent_id' => 'agent-1', 'agent_type' => 'reviewer']]],
            $fired,
        );
        self::assertNull(HookManager::currentSubagent(), 'the scope ends with the run');

        $engine->complete([Message::user('and now the session itself')]);
        self::assertSame('Stop', $fired[1][0] ?? null, 'outside the scope the session\'s own turn ends on Stop');
    }

    public function testATaskSubAgentEndsOnSubagentStopAndItsCallerOnStop(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_task', 'Task', ['description' => 'Audit', 'prompt' => 'Audit it', 'agent' => 'coder'])]),
            new CompleteResponse(content: 'the report'),
            new CompleteResponse(content: 'all done'),
        ]);
        $fired = [];
        $record = static function (HookContext $context) use (&$fired): HookResult {
            $input = json_decode($context->toolInput, true);
            $fired[] = [$context->toolName, $input['agent_type'] ?? null, $input['last_assistant_message']];

            return HookResult::allow();
        };
        $manager = new \SugarCraft\Crush\Agents\AgentManager(new ScriptedProvider([]), new \SugarCraft\Crush\Skills\SkillRegistry(), toolRegistry: [], toolUniverse: []);
        $manager->register(\SugarCraft\Crush\Tests\Support\RosterAgent::named('coder', maxTurns: 5));
        $engine = EngineBackend::new($provider, 'm')
            ->withTools([new \SugarCraft\Crush\Tools\BuiltIn\TaskTool($manager)])
            ->withHooks(self::hooks(self::hook(HookEvent::Stop, 'main', $record), self::hook(HookEvent::SubagentStop, 'sub', $record)));

        $reply = $engine->complete([Message::user('delegate it')]);

        self::assertSame('all done', $reply->content);
        self::assertSame([['SubagentStop', 'coder', 'the report'], ['Stop', null, 'all done']], $fired);
    }

    public function testAnUnhookedTurnEndsExactlyAsBefore(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'hi'), new CompleteResponse(content: 'never sent')]);

        $reply = EngineBackend::new($provider, 'm')->withHooks(self::hooks())->complete([Message::user('hello')]);

        self::assertSame('hi', $reply->content);
        self::assertCount(1, $provider->requests);
    }

    private static function hooks(HookInterface ...$hooks): HookManager
    {
        $manager = new HookManager(new HookRegistry());
        foreach ($hooks as $hook) {
            $manager->register($hook);
        }

        return $manager;
    }

    /**
     * @param \Closure(HookContext): HookResult $verdict
     */
    private static function hook(HookEvent $event, string $name, \Closure $verdict): HookInterface
    {
        return new class ($event, $name, $verdict) implements HookInterface {
            public function __construct(private HookEvent $event, private string $name, private \Closure $verdict) {}

            public function name(): string { return $this->name; }

            public function event(): HookEvent { return $this->event; }

            public function matcher(): string { return '.*'; }

            public function execute(HookContext $context): HookResult
            {
                return ($this->verdict)($context);
            }
        };
    }

    private static function tool(): Tool
    {
        return new class () implements Tool {
            public function name(): string { return 'deploy'; }

            public function description(): string { return 'ships it'; }

            public function inputSchema(): array { return ['type' => 'object', 'properties' => []]; }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: 'shipped');
            }
        };
    }
}
