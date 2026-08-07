<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\AgentsCommand;

/**
 * @internal
 *
 * @see AgentsCommand
 */
final class AgentsCommandTest extends TestCase
{
    // =========================================================================
    // Structure Verification Tests
    // =========================================================================

    public function testAgentsCommandExists(): void
    {
        $this->assertTrue(class_exists(AgentsCommand::class));
    }

    public function testAgentsCommandIsFinal(): void
    {
        $reflection = new \ReflectionClass(AgentsCommand::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testAgentsCommandHasExecuteMethod(): void
    {
        $this->assertTrue(method_exists(AgentsCommand::class, 'execute'));
    }

    public function testAgentsCommandExecuteMethodSignature(): void
    {
        $method = new \ReflectionMethod(AgentsCommand::class, 'execute');
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('chat', $params[0]->getName());
        $this->assertSame('args', $params[1]->getName());
    }

    public function testAgentsCommandReturnsIntFromExecute(): void
    {
        $method = new \ReflectionMethod(AgentsCommand::class, 'execute');
        $returnType = $method->getReturnType();
        $this->assertSame('int', $returnType->getName());
    }

    public function testAgentsCommandHasConstructorWithAgentManagerParameter(): void
    {
        $method = new \ReflectionMethod(AgentsCommand::class, '__construct');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('agentManager', $params[0]->getName());
    }

    public function testAgentsCommandHasListAgentsMethod(): void
    {
        $this->assertTrue(method_exists(AgentsCommand::class, 'listAgents'));
    }

    public function testAgentsCommandHasShowAgentMethod(): void
    {
        $this->assertTrue(method_exists(AgentsCommand::class, 'showAgent'));
    }

    public function testConstructorParameterCanOnlyBeSetViaConstructor(): void
    {
        // Verify that the agentManager property exists and is readonly via promoted parameter
        $reflection = new \ReflectionClass(AgentsCommand::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        $this->assertCount(1, $params);

        // Check the property is a promoted parameter (from constructor)
        $param = $params[0];
        $this->assertSame('agentManager', $param->getName());
        $this->assertTrue($param->isPromoted());
    }

    // =========================================================================
    // Behavioral Tests
    // =========================================================================

    public function testExecuteWithEmptyAgentList(): void
    {
        $agentManager = $this->createAgentManagerWithAgents([]);
        $command = new AgentsCommand($agentManager);
        $chat = new Chat([]);

        ob_start();
        $exitCode = $command->execute($chat, []);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No active agents configured', $output);
    }

    public function testExecuteWithAgentsInList(): void
    {
        $agents = [
            new Agent(
                name: 'test-agent',
                description: 'A test agent for unit testing',
                prompt: 'You are a test agent.',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
        ];
        $agentManager = $this->createAgentManagerWithAgents($agents);
        $command = new AgentsCommand($agentManager);
        $chat = new Chat([]);

        ob_start();
        $exitCode = $command->execute($chat, []);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Active Agents', $output);
        $this->assertStringContainsString('test-agent', $output);
    }

    public function testExecuteShowKnownAgent(): void
    {
        $agent = new Agent(
            name: 'coder',
            description: ' Writes and reviews code',
            prompt: 'You are a coder agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['read', 'write', 'edit'],
            skillNames: ['php-pro', 'code-philosophy'],
            hooks: [],
            isActive: true,
        );
        $agentManager = $this->createAgentManagerWithAgents([$agent]);
        $command = new AgentsCommand($agentManager);
        $chat = new Chat([]);

        ob_start();
        $exitCode = $command->execute($chat, ['coder']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Agent: coder', $output);
        $this->assertStringContainsString('Description:', $output);
        $this->assertStringContainsString('Model:', $output);
        $this->assertStringContainsString('Provider:', $output);
        $this->assertStringContainsString('Status:', $output);
        $this->assertStringContainsString('Tools:', $output);
        $this->assertStringContainsString('Skills:', $output);
    }

    public function testExecuteShowUnknownAgent(): void
    {
        $agentManager = $this->createAgentManagerWithAgents([]);
        $command = new AgentsCommand($agentManager);
        $chat = new Chat([]);

        ob_start();
        $exitCode = $command->execute($chat, ['nonexistent']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown agent: nonexistent', $output);
        $this->assertStringContainsString('Use /agents to see available agents', $output);
    }

    public function testExecuteWithNoArgsFallsBackToListAgents(): void
    {
        $agents = [
            new Agent(
                name: 'reviewer',
                description: 'Reviews code for bugs',
                prompt: 'You are a reviewer.',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
        ];
        $agentManager = $this->createAgentManagerWithAgents($agents);
        $command = new AgentsCommand($agentManager);
        $chat = new Chat([]);

        // Call with empty args array (simulates /agent with no sub-command)
        ob_start();
        $exitCode = $command->execute($chat, []);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Active Agents', $output);
        $this->assertStringContainsString('reviewer', $output);
    }

    public function testExecuteWithInactiveAgentNotShownInList(): void
    {
        $agents = [
            new Agent(
                name: 'inactive-agent',
                description: 'An inactive agent',
                prompt: 'You are inactive.',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: false,
            ),
        ];
        $agentManager = $this->createAgentManagerWithAgents($agents);
        $command = new AgentsCommand($agentManager);
        $chat = new Chat([]);

        ob_start();
        $exitCode = $command->execute($chat, []);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No active agents configured', $output);
        $this->assertStringNotContainsString('inactive-agent', $output);
    }

    public function testExecuteShowAgentWithNoOptionalFields(): void
    {
        $agent = new Agent(
            name: 'minimal',
            description: 'Minimal agent',
            prompt: 'Minimal prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
        $agentManager = $this->createAgentManagerWithAgents([$agent]);
        $command = new AgentsCommand($agentManager);
        $chat = new Chat([]);

        ob_start();
        $exitCode = $command->execute($chat, ['minimal']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Agent: minimal', $output);
        // Skills and Tools sections should not appear when empty
        $this->assertStringNotContainsString('Skills:', $output);
        $this->assertStringNotContainsString('Tools:', $output);
        $this->assertStringNotContainsString('Hooks:', $output);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create an AgentManager stub with predefined agents.
     *
     * @param Agent[] $agents
     */
    private function createAgentManagerWithAgents(array $agents): AgentManager
    {
        // Use reflection to create AgentManager without calling its constructor
        $reflection = new \ReflectionClass(AgentManager::class);
        $agentManager = $reflection->newInstanceWithoutConstructor();

        // Use reflection to set the private $agents property
        // Store as associative array keyed by agent name (as register() does)
        $agentsProperty = $reflection->getProperty('agents');
        $agentsProperty->setAccessible(true);
        $indexedAgents = [];
        foreach ($agents as $agent) {
            $indexedAgents[$agent->name] = $agent;
        }
        $agentsProperty->setValue($agentManager, $indexedAgents);

        return $agentManager;
    }
}
