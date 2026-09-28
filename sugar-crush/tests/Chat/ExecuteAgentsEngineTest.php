<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPoolConfig;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\EngineExecutor;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * {@see Chat::executeAgents()} on a chat that runs a REAL engine: the pool it
 * builds from its AgentPoolConfig runs each agent through that engine's tool
 * loop ({@see EngineExecutor}) in a forked child — the same executor
 * `/workflow` stages use — instead of the one-call worker that advertises
 * tools and executes none. Every scripted agent here calls a tool first.
 */
final class ExecuteAgentsEngineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('the engine-backed pool forks one child per agent');
        }
    }

    public function testAgentsRunTheChatsEngineToolLoopInForkedChildren(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            new CompleteResponse(content: 'report from the loop'),
        ]);
        $chat = (new Chat(backend: EngineBackend::new($provider, 'm')->withTools([self::probe()])))
            ->withAgentPoolConfig(new AgentPoolConfig(maxConcurrent: 2));

        $results = self::drain($chat->executeAgents([self::subAgent('one'), self::subAgent('two')], self::request()));

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
            $this->assertSame('report from the loop', $result->output, 'only reachable after the tool call ran');
        }
        $this->assertSame([], $provider->requests, 'every provider call happened in a forked child');
    }

    public function testAnEchoEngineKeepsTheWorkerThatFailsClosed(): void
    {
        $chat = (new Chat(backend: EngineBackend::new(new EchoProvider(), 'echo')))
            ->withAgentPoolConfig(new AgentPoolConfig(maxConcurrent: 1));

        $results = self::drain($chat->executeAgents([self::subAgent('echo')], self::request()));

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->isSuccess(), 'echoed text must never come back as an agent\'s work');
        $this->assertStringContainsString('Refusing to fabricate', (string) $results[0]->error?->getMessage());
    }

    public function testAnExplicitPoolIsUsedAsGiven(): void
    {
        $executor = new class implements ExecutorInterface {
            public int $calls = 0;

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                $this->calls++;

                return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed, output: 'from the injected pool');
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
        $provider = new ScriptedProvider([new CompleteResponse(content: 'unreachable')]);
        $chat = (new Chat(backend: EngineBackend::new($provider, 'm')))
            ->withAgentPoolConfig(new AgentPoolConfig(maxConcurrent: 1))
            ->withWorkerPool(new AgentWorkerPool(maxConcurrent: 1, executor: $executor));

        $results = self::drain($chat->executeAgents([self::subAgent('injected')], self::request()));

        $this->assertSame('from the injected pool', $results[0]->output);
        $this->assertSame(1, $executor->calls);
        $this->assertSame([], $provider->requests, 'the chat engine was not consulted');
    }

    /**
     * @return list<AgentResult>
     */
    private static function drain(\Generator $results): array
    {
        return array_values(iterator_to_array($results, false));
    }

    private static function subAgent(string $name): SubAgent
    {
        return new SubAgent(id: $name . '-' . bin2hex(random_bytes(4)), agent: RosterAgent::named($name), task: 'inspect it');
    }

    private static function request(): CompleteRequest
    {
        return new CompleteRequest(model: 'm', messages: []);
    }

    private static function probe(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'answers probed';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'probed');
            }
        };
    }
}
