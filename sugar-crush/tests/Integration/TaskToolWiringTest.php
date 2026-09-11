<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * THE REACHABILITY EVIDENCE for `TaskTool`: a real model tool-call for `Task`,
 * driven through a real {@see Runtime}, reaches a real
 * {@see AgentManager::executeAll()} and the sub-agent's answer comes back as
 * the tool result the model reads — and the permission gate refuses the
 * IDENTICAL call under Plan mode without the executor ever running. Same shape
 * as {@see McpToolWiringTest} for the bridge.
 *
 * IT USED TO BE THE EXEMPTION EVIDENCE, and the exemption is gone (E675): the
 * tool was built and registered but never FED a manager in production, so
 * `Bootstrap::tools()` could not name it as a literal and the corpus exempted
 * it. The launch feed now builds the bound instance, which
 * {@see testTheProductionLaunchFeedsTaskBoundToTheChatOwnManager()} pins as
 * LIVE wiring — the registry shape alone was never the claim.
 *
 * WHY THE EXECUTOR IS INJECTED (a recording fake, not a forked worker): an
 * injected executor puts the pool on its synchronous in-parent dispatch, which
 * is what lets these tests assert WHAT THE BATCH CARRIED — the prompt, the
 * granted tool roster — rather than only that bytes came back. The fork path's
 * provider-spec plumbing is E652's own evidence and is not re-litigated here;
 * what P8.13 claims is that the TOOL reaches the MANAGER, and this file is
 * measured against that claim alone.
 */
final class TaskToolWiringTest extends TestCase
{
    use HomeSandboxTrait;

    /** @var string */
    private string $tempDir;

    /** @var string */
    private string $root;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/task-tool-wiring-' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
        $this->root = $this->tempDir . '/repo';
        mkdir($this->root, 0o755, true);
        $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->root);
        @rmdir($this->tempDir . '/home');
        @rmdir($this->tempDir);
    }

    /**
     * E675's LIVE-FEED pin, not a registry shape: drive the real launch path
     * (`Bootstrap::chat()`) in a sandboxed home and assert the backend that
     * comes back actually carries a `Task` tool, bound to THE SAME
     * `AgentManager` instance the Chat itself runs on. Break either half of
     * the seam — drop the `taskManager:` from chat()'s backend feed, or build
     * a second manager inside tools() — and this test reddens, which is the
     * mutation the exemption-era tests structurally could not see: they built
     * the bound tool BY HAND and so proved only that a hand-bound tool works.
     */
    public function testTheProductionLaunchFeedsTaskBoundToTheChatOwnManager(): void
    {
        $chat = Bootstrap::chat($this->root);
        $backend = $chat->backend();

        $feed = (new \ReflectionProperty($backend, 'tools'))->getValue($backend);

        $taskTools = array_values(array_filter(
            $feed,
            static fn (object $tool): bool => $tool instanceof TaskTool,
        ));
        $this->assertCount(
            1,
            $taskTools,
            'a production launch must ship exactly one bound Task tool — zero is the E675 bug, two is a split feed',
        );

        $boundManager = (new \ReflectionProperty(TaskTool::class, 'agentManager'))->getValue($taskTools[0]);
        $this->assertSame(
            $chat->agentManager(),
            $boundManager,
            'Task must delegate to the SAME AgentManager the Chat runs on; a second instance means the model turn '
            . 'and the /agents panel answer from different rosters.',
        );
        self::assertNotNull($boundManager, 'the fed Task must not carry a null manager — that is the inert shape E675 removed');
    }

    public function testAModelTaskCallReachesTheAgentManagerAndTheSubAgentAnswerComesBack(): void
    {
        [$tool, $executor] = $this->wiredTaskTool();

        $messages = $this->drive(
            new ToolCall('call_1', 'Task', [
                'description' => 'Delegate the audit',
                'prompt' => 'Audit the auth middleware',
                'agent' => 'coder',
            ]),
            $tool,
            permissionMode: null,
        );

        $results = $this->toolResults($messages);
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->isError(), $results[0]->content());
        $this->assertSame('sub-agent report: Audit the auth middleware', $results[0]->content());

        $this->assertCount(1, $executor->seen, 'the batch must have reached the pool through the manager');
        $this->assertSame('Audit the auth middleware', $executor->seen[0]['task']);
        $this->assertSame(
            ['Read'],
            $executor->seen[0]['tools'],
            'the delegated agent carries its own grants, resolved by executeAll — not the session registry',
        );
    }

    public function testThePermissionGateRefusesTheSameTaskCallUnderPlanAndNothingDispatches(): void
    {
        [$tool, $executor] = $this->wiredTaskTool();

        $messages = $this->drive(
            new ToolCall('call_2', 'Task', [
                'description' => 'Delegate the audit',
                'prompt' => 'Audit the auth middleware',
                'agent' => 'coder',
            ]),
            $tool,
            permissionMode: PermissionMode::Plan,
        );

        $results = $this->toolResults($messages);
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->isError());
        $this->assertStringContainsString('plan', $results[0]->content());
        $this->assertStringContainsString('Task', $results[0]->content());

        $this->assertSame(
            [],
            $executor->seen,
            'a gated refusal must not have dispatched a sub-agent — only an executing call could have logged',
        );
    }

    /**
     * A manager-backed tool whose pool records every batch it is handed.
     *
     * @return array{0: TaskTool, 1: ExecutorInterface&object{seen: list<array{task: string, tools: ?list<string>}>}}
     */
    private function wiredTaskTool(): array
    {
        $executor = new class implements ExecutorInterface {
            /** @var list<array{task: string, tools: ?list<string>}> */
            public array $seen = [];

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                $this->seen[] = [
                    'task' => $agent->task,
                    'tools' => $request->tools === null
                        ? null
                        : array_map(static fn (object $tool): string => $tool->name(), $request->tools),
                ];

                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'sub-agent report: ' . $agent->task,
                );
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void
            {
            }

            public function cancelAll(): void
            {
            }
        };

        $manager = new AgentManager(
            $this->createMock(ProviderInterface::class),
            new SkillRegistry(),
            toolRegistry: [new Read()],
            toolUniverse: [new Read()],
        );
        $manager->register(new Agent(
            name: 'coder',
            description: 'wiring fixture agent',
            prompt: 'You are the wiring fixture coder.',
            model: 'test-model',
            provider: 'test',
            tools: ['Read'],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));

        return [new TaskTool($manager, new AgentWorkerPool(maxConcurrent: 1, executor: $executor)), $executor];
    }

    /**
     * @return list<object> every message the run produced
     */
    private function drive(ToolCall $call, TaskTool $tool, ?PermissionMode $permissionMode): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');
        $provider->method('supportsStreaming')->willReturn(false);
        $provider->method('complete')->willReturnOnConsecutiveCalls(
            new CompleteResponse(content: 'delegating', toolCalls: [$call]),
            new CompleteResponse(content: 'done'),
        );

        $registry = new HookRegistry();
        if ($permissionMode !== null) {
            $registry->register(new PermissionGateHook(new PermissionGate($permissionMode)));
        }

        $runtime = new Runtime($provider, new HookManager($registry), null, false);
        $app = App::new($provider, 'test-model')
            ->withTools([$tool])
            ->withRoot($this->root);

        return iterator_to_array($runtime->run($app));
    }

    /**
     * @param list<object> $messages
     *
     * @return list<ToolResultMessage>
     */
    private function toolResults(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            static fn (object $message): bool => $message instanceof ToolResultMessage,
        ));
    }
}
