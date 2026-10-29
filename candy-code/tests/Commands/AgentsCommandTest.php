<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\AgentsCommand;
use SugarCraft\Code\Host\Commands\AgentsHostCommand;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Role;

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
    // =========================================================================
    // C-AGT-4.b: the `use` verb — this session's primary agent
    // =========================================================================

    public function testTheUseVerbHandsWithTheSwitchedRowAndTheEffect(): void
    {
        $result = $this->runAgents('use worker');

        $this->assertFalse($result->isRefused());
        $this->assertCount(1, $result->effects, 'the switch travels as exactly one effect');
        $this->assertSame(CommandEffectKind::SetPrimaryAgent, $result->effects[0]->kind);
        $this->assertSame('worker', $result->effects[0]->primaryAgentName());
        $this->assertSame(
            Lang::t('host.agents.use_switched', ['name' => 'worker']),
            $result->rows[\count($result->rows) - 1]->content,
            'the row promises the session-scoped narrowing; the applying driver says the same words',
        );
    }

    public function testUseDefaultHandsWithANullNameAndTheDefaultRow(): void
    {
        $result = $this->runAgents('use default');

        $this->assertFalse($result->isRefused());
        $this->assertSame(CommandEffectKind::SetPrimaryAgent, $result->effects[0]->kind);
        $this->assertNull($result->effects[0]->primaryAgentName(), 'the null name IS the revert');
        $this->assertSame(
            Lang::t('host.agents.use_default'),
            $result->rows[\count($result->rows) - 1]->content,
        );
    }

    public function testUseOfAnUnknownNameRefusesWithNoEffect(): void
    {
        $result = $this->runAgents('use ghost');

        // `CommandResult::failure`: the echo, then the reason as a
        // `Role::System` notice — the `isRefused()` shape belongs to a
        // command that never ran (a busy session, an unknown name).
        $last = $result->rows[\count($result->rows) - 1];
        $this->assertSame(Role::System, $last->role);
        $this->assertSame([], $result->effects, 'nothing reaches a driver for a name the roster does not carry');
        $this->assertStringContainsString('ghost', $last->content);
    }

    public function testTheUseVerbReadsTheRosterCaseSensitivelyLikeEveryOtherName(): void
    {
        // `AgentManager::get()` is exact-case; a fuzzy second door beside it
        // would resolve names the operator never typed into the roster.
        $result = $this->runAgents('use WORKER');

        $this->assertSame([], $result->effects, 'the near-miss name lands the same failure, with no effect');
        $this->assertStringContainsString('WORKER', $result->rows[\count($result->rows) - 1]->content);
    }

    public function testBareUseAndOverloadedUsePrintTheUsageLine(): void
    {
        foreach (['use', 'use alpha beta'] as $text) {
            $result = $this->runAgents($text);
            $this->assertSame([], $result->effects, "`/{$text}` names no single preset");
            $this->assertSame(Role::System, $result->rows[\count($result->rows) - 1]->role);
            $this->assertStringContainsString(
                'use <preset|default>',
                $result->rows[\count($result->rows) - 1]->content,
                'the usage line now teaches the verb it refuses',
            );
        }
    }

    public function testTheUseVerbWaitsForTheManagerGateLikeTheRosterItReads(): void
    {
        // No manager, no roster: the `use` arm is unreachable and the command
        // falls to the same not-configured refusal every other arm answers.
        $result = (new AgentsHostCommand())->run(CommandContext::new(), '/agents use worker');

        $this->assertStringContainsString(
            Lang::t('host.agents.not_configured'),
            $result->rows[\count($result->rows) - 1]->content,
        );
    }

    private function runAgents(string $text): \SugarCraft\Code\Host\Commands\CommandResult
    {
        $agentManager = $this->createAgentManagerWithAgents([
            new Agent(
                name: 'worker',
                description: 'Runs as the primary for one session',
                prompt: 'You are a worker.',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
        ]);

        return (new AgentsHostCommand())->run(
            CommandContext::new(agentManager: $agentManager),
            '/agents ' . $text,
        );
    }

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
