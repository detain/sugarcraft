<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The P8.13 Task tool: one model-callable delegation, answered honestly.
 *
 * Four shapes are pinned here, in the order the refusal ladder climbs:
 * malformed call, unbound launch, unknown roster name, and the governed
 * success and failure paths through a REAL {@see AgentManager::executeAll()}
 * onto a recording executor. The executor stands in for the forked worker and
 * RECORDS what the batch actually carried — the task text, the model, and
 * above all the per-agent TOOL GRANTS — because the point of dispatching
 * through the manager rather than straight at a pool is that E644's ceiling
 * comes along for free. An assertion that only checks the returned string
 * would pass with the grants silently bypassed.
 */
final class TaskToolTest extends TestCase
{
    /**
     * @return array{0: TaskTool, 1: AgentManager, 2: object}
     */
    private function boundTool(array $registryTools = [], array $universeTools = []): array
    {
        $executor = new class implements ExecutorInterface {
            /** @var list<array{task: string, agent: string, model: string, tools: ?list<string>}> */
            public array $seen = [];

            public ?string $output = 'the report';

            public bool $fail = false;

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                $this->seen[] = [
                    'task' => $agent->task,
                    'agent' => $agent->agent->name,
                    'model' => $request->model,
                    'tools' => $request->tools === null
                        ? null
                        : array_map(static fn (object $tool): string => $tool->name(), $request->tools),
                ];

                if ($this->fail) {
                    return new AgentResult(
                        agentId: $agent->id,
                        status: AgentStatus::Failed,
                        error: new \RuntimeException('worker refuses: no provider spec on this launch'),
                    );
                }

                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: $this->output,
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
            toolRegistry: $registryTools === [] ? null : $registryTools,
            toolUniverse: $universeTools === [] ? null : $universeTools,
        );
        $manager->register(self::agent('coder', tools: ['Read', 'Grep']));
        $manager->register(self::agent('reviewer'));

        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);

        return [new TaskTool($manager, $pool), $manager, $executor];
    }

    private static function agent(string $name, array $tools = []): Agent
    {
        return new Agent(
            name: $name,
            description: "test agent {$name}",
            prompt: "You are {$name}.",
            model: 'test-model',
            provider: 'test',
            tools: $tools,
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function call(array $overrides = []): array
    {
        return $overrides + [
            'description' => 'Delegate a bounded audit',
            'prompt' => 'Audit the auth middleware and report the findings',
            'agent' => 'coder',
        ];
    }

    public function testTheWireNameIsTaskAndTheSchemaParsesTheThreeModelFacingFields(): void
    {
        $tool = new TaskTool();

        $this->assertSame('Task', $tool->name());

        $schema = $tool->inputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['description', 'prompt', 'agent'], $schema['required']);
        foreach (['description', 'prompt', 'agent'] as $field) {
            $this->assertSame('string', $schema['properties'][$field]['type']);
            $this->assertNotSame('', $schema['properties'][$field]['description']);
        }
        // The E2 rubric: the label the transcript shows must be demanded.
        $this->assertStringContainsString('active voice', $schema['properties']['description']['description']);
    }

    public function testAnUnboundTaskRefusesLoudlyAndNeverThrows(): void
    {
        // The corpus builds this exact standalone shape and calls execute([])
        // on every tool; both halves of that contract are this assertion.
        $empty = (new TaskTool())->execute([]);
        $this->assertTrue($empty->isError());
        $this->assertStringContainsString('prompt', $empty->content());

        $named = (new TaskTool())->execute(self::call());
        $this->assertTrue($named->isError());
        $this->assertStringContainsString('AgentManager', $named->content());
        $this->assertMatchesRegularExpression('/Error: Task refused/', $named->content());
    }

    public function testAMissingPromptOrAgentRefusesBeforeTheManagerIsConsulted(): void
    {
        [$tool, , $executor] = $this->boundTool();

        $noPrompt = $tool->execute(self::call(['prompt' => '   ']));
        $this->assertTrue($noPrompt->isError());
        $this->assertStringContainsString('prompt', $noPrompt->content());

        $noAgent = $tool->execute(self::call(['agent' => '']));
        $this->assertTrue($noAgent->isError());
        $this->assertStringContainsString('agent', $noAgent->content());

        $this->assertSame([], $executor->seen, 'a malformed call must never reach dispatch');
    }

    public function testAnUnknownAgentRefusesLoudlyNamingTheLiveRoster(): void
    {
        [$tool, , $executor] = $this->boundTool();

        $result = $tool->execute(self::call(['agent' => 'ghost']));

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('ghost', $result->content());
        $this->assertStringContainsString('coder', $result->content());
        $this->assertStringContainsString('reviewer', $result->content());
        $this->assertSame([], $executor->seen, 'a refused roster name must never fork a worker');
    }

    public function testAConfiguredDispatchReturnsTheSubAgentsFinalText(): void
    {
        [$tool, , $executor] = $this->boundTool();

        $result = $tool->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame('the report', $result->content());

        $this->assertCount(1, $executor->seen);
        $this->assertSame('coder', $executor->seen[0]['agent']);
        $this->assertSame(
            'Audit the auth middleware and report the findings',
            $executor->seen[0]['task'],
            'the prompt must arrive verbatim as the sub-agent\'s task',
        );
        $this->assertSame('test-model', $executor->seen[0]['model']);
    }

    public function testTheDelegatedBatchCarriesTheAgentsOwnGrantsAndNotTheSessionsTools(): void
    {
        // E644 THROUGHPUT: the tool dispatches via executeAll precisely so the
        // declared ceiling is resolved per agent. The 'coder' declares
        // Read+Grep; the session registry also holds Bash. The worker must see
        // exactly the declaration, never the registry.
        [$tool, , $executor] = $this->boundTool(
            registryTools: [new Read(), new Grep(), new Bash()],
            universeTools: [new Read(), new Grep(), new Bash()],
        );

        $result = $tool->execute(self::call());

        $this->assertFalse($result->isError(), $result->content());
        // The worker must be handed the agent's OWN grants — the resolver
        // intersects the registry by the declaration, so the two declared names
        // arrive and `Bash` does not. Set membership is the verdict; order is
        // the resolver's business.
        $tools = $executor->seen[0]['tools'];
        sort($tools);
        $this->assertSame(['Grep', 'Read'], $tools);
    }

    public function testAFailedWorkerSurfacesItsStatusAndOwnMessage(): void
    {
        [$tool, , $executor] = $this->boundTool();
        $executor->fail = true;

        $result = $tool->execute(self::call());

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('failed', $result->content());
        $this->assertStringContainsString('no provider spec', $result->content());
    }

    public function testACompletedRunThatSaidNothingIsNotPassedOffAsASuccess(): void
    {
        [$tool, , $executor] = $this->boundTool();
        $executor->output = null;

        $result = $tool->execute(self::call());

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('without any output', $result->content());
    }

    public function testATaskResultCarriesTheCallIdThroughWhenTheRuntimeSuppliesOne(): void
    {
        [$tool] = $this->boundTool();

        $result = $tool->execute(self::call(['id' => 'call_7']));

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame('call_7', $result->toolCallId());
    }
}
