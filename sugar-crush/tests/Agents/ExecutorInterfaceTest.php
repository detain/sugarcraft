<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * Tests for ExecutorInterface - contract for agent execution within worker pool.
 */
final class ExecutorInterfaceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Interface existence and method signatures
    // -------------------------------------------------------------------------

    public function testInterfaceExists(): void
    {
        $this->assertTrue(
            interface_exists(ExecutorInterface::class),
            'ExecutorInterface should exist in SugarCraft\Crush\Agents namespace'
        );
    }

    public function testExecuteMethodSignature(): void
    {
        $reflection = new \ReflectionClass(ExecutorInterface::class);

        $method = $reflection->getMethod('execute');
        $this->assertTrue($method->isPublic(), 'execute() must be public');
        $this->assertSame(
            'SugarCraft\Crush\Agents\AgentResult',
            $method->getReturnType()->getName(),
            'execute() must return AgentResult'
        );

        $params = $method->getParameters();
        $this->assertCount(2, $params, 'execute() must have exactly 2 parameters');

        $this->assertSame('agent', $params[0]->getName());
        $this->assertSame(SubAgent::class, $params[0]->getType()->getName());

        $this->assertSame('request', $params[1]->getName());
        $this->assertSame(CompleteRequest::class, $params[1]->getType()->getName());
    }

    public function testExecuteStreamMethodSignature(): void
    {
        $reflection = new \ReflectionClass(ExecutorInterface::class);

        $method = $reflection->getMethod('executeStream');
        $this->assertTrue($method->isPublic(), 'executeStream() must be public');

        $returnType = $method->getReturnType();
        $typeName = ltrim($returnType->getName(), '\\');
        $this->assertSame('Generator', $typeName, 'executeStream() must return Generator');

        $params = $method->getParameters();
        $this->assertCount(2, $params, 'executeStream() must have exactly 2 parameters');

        $this->assertSame('agent', $params[0]->getName());
        $this->assertSame(SubAgent::class, $params[0]->getType()->getName());

        $this->assertSame('request', $params[1]->getName());
        $this->assertSame(CompleteRequest::class, $params[1]->getType()->getName());
    }

    public function testCancelMethodSignature(): void
    {
        $reflection = new \ReflectionClass(ExecutorInterface::class);

        $method = $reflection->getMethod('cancel');
        $this->assertTrue($method->isPublic(), 'cancel() must be public');
        $this->assertTrue($method->hasReturnType() && $method->getReturnType()->getName() === 'void', 'cancel() must return void');

        $params = $method->getParameters();
        $this->assertCount(1, $params, 'cancel() must have exactly 1 parameter');

        $this->assertSame('agentId', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
    }

    public function testCancelAllMethodSignature(): void
    {
        $reflection = new \ReflectionClass(ExecutorInterface::class);

        $method = $reflection->getMethod('cancelAll');
        $this->assertTrue($method->isPublic(), 'cancelAll() must be public');
        $this->assertTrue($method->hasReturnType() && $method->getReturnType()->getName() === 'void', 'cancelAll() must return void');

        $params = $method->getParameters();
        $this->assertCount(0, $params, 'cancelAll() must have no parameters');
    }

    // -------------------------------------------------------------------------
    // Stub implementation to verify interface is implementable
    // -------------------------------------------------------------------------

    public function testCanBeImplemented(): void
    {
        $stub = new class implements ExecutorInterface {
            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return new AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Crush\Agents\AgentStatus::Completed,
                    output: 'stub',
                );
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield new AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Crush\Agents\AgentStatus::Streaming,
                    output: 'streaming',
                );
            }

            public function cancel(string $agentId): void
            {
                // no-op
            }

            public function cancelAll(): void
            {
                // no-op
            }
        };

        $this->assertInstanceOf(ExecutorInterface::class, $stub);
    }

    public function testStubExecuteReturnsAgentResult(): void
    {
        $stub = new class implements ExecutorInterface {
            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'done',
                );
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Streaming,
                    output: 'streaming',
                );
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };

        $agent = new SubAgent(
            id: 'test-agent-123',
            agent: Agent::fromArray(['name' => 'test']),
            task: 'test task',
        );

        $request = new CompleteRequest(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'hello']],
        );

        $result = $stub->execute($agent, $request);

        $this->assertInstanceOf(AgentResult::class, $result);
        $this->assertSame('test-agent-123', $result->agentId);
        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertSame('done', $result->output);
    }

    public function testStubExecuteStreamYieldsGenerator(): void
    {
        $stub = new class implements ExecutorInterface {
            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed);
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Streaming,
                    output: 'chunk 1',
                );
                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'chunk 2',
                );
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };

        $agent = new SubAgent(
            id: 'stream-agent',
            agent: Agent::fromArray(['name' => 'test']),
            task: 'test task',
        );

        $request = new CompleteRequest(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'hello']],
        );

        $generator = $stub->executeStream($agent, $request);

        $this->assertInstanceOf(\Generator::class, $generator);

        $firstChunk = $generator->current();
        $this->assertSame('stream-agent', $firstChunk->agentId);
        $this->assertSame(AgentStatus::Streaming, $firstChunk->status);
        $this->assertSame('chunk 1', $firstChunk->output);
    }
}
