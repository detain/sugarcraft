<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\AgentsCommand;

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
                model: 'opus-4.1',
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
        $this->assertStringContainsString('Agents:', $output);
        $this->assertStringContainsString('test-agent', $output);
        // ISS-034: the roster table names the model each agent runs.
        $this->assertStringContainsString('opus-4.1', $output);
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
        // ISS-034: the hint is no longer circular — it names the roster
        // command AND the real directories a preset file can arrive from.
        $this->assertStringContainsString('Use /agents to list the roster', $output);
        $this->assertStringContainsString('.candy-code/agents', $output);
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
        $this->assertStringContainsString('Agents:', $output);
        $this->assertStringContainsString('reviewer', $output);
    }

    public function testTheIdleRosterIsRenderedNotCounted(): void
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
        // ISS-034 reversed the old contract: `/agents` used to hide agents
        // that are not currently working and print only a count. The roster
        // IS the table now, with an honest status column doing the work the
        // hidden rows used to (mis)do.
        $this->assertStringContainsString('inactive-agent', $output);
        $this->assertStringContainsString('No agents are working right now', $output);
        $this->assertStringContainsString('Agents:', $output);
        // The heading dropped its "Active" qualifier with ISS-034: the table
        // is the roster, not only the working subset.
        $this->assertStringNotContainsString('Active Agents', $output);
        $this->assertStringNotContainsString('No active agents configured', $output);
        $this->assertStringNotContainsString('registered and idle', $output);
    }

    public function testTheRosterTablePutsWorkingAgentsFirstAndNamesTheirTier(): void
    {
        $working = new Agent(
            name: 'z-working',
            description: 'Busy now',
            prompt: 'x',
            model: 'gpt-5.2',
            provider: 'openai',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
        $idleClaude = new Agent(
            name: 'a-idle',
            description: 'Idle foreign',
            prompt: 'x',
            model: '',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: false,
            source: \SugarCraft\Code\Skills\SkillSource::Claude,
        );
        $idleNative = new Agent(
            name: 'm-idle',
            description: 'Idle native',
            prompt: 'x',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: false,
        );
        $agentManager = $this->createAgentManagerWithAgents([$idleClaude, $working, $idleNative]);
        $command = new AgentsCommand($agentManager);

        ob_start();
        $agentManager2 = $agentManager;
        unset($agentManager2);
        $exitCode = $command->execute(new Chat([]), []);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $posWorking = strpos($output, 'z-working');
        $posIdleA = strpos($output, 'a-idle');
        $posIdleM = strpos($output, 'm-idle');
        $this->assertLessThan($posIdleA, $posWorking, 'working agents ride above the idle ones');
        $this->assertLessThan($posIdleM, $posIdleA, 'idle agents sort by name below them');
        $this->assertStringContainsString('claude', $output, 'the foreign tier is named per row');
        $this->assertStringContainsString('native', $output);
        $this->assertStringContainsString('session', $output, "a model-less preset reads as the session's model, not blank");
    }

    public function testExecuteWithAnEmptyManagerStillPointsAtTheConfigurationFile(): void
    {
        // The genuinely-empty case keeps the original advice: there is no
        // roster to inspect with /agent <name>, so telling the user to define
        // one is the only useful thing to say.
        $command = new AgentsCommand($this->createAgentManagerWithAgents([]));

        ob_start();
        $exitCode = $command->execute(new Chat([]), []);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No active agents configured', $output);
        $this->assertStringContainsString('~/.candy-code/agents', $output);
        $this->assertStringNotContainsString('registered and idle', $output);
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
