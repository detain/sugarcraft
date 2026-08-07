<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * Tests for AgentManager PermissionGate wiring (P2B.S7).
 */
final class AgentManagerPermissionGateTest extends TestCase
{
    private ProviderInterface $provider;
    private SkillRegistry $skillRegistry;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->skillRegistry = new SkillRegistry();
    }

    /**
     * Test 1: AgentManager::createSubAgent() with no PermissionMode → SubAgent gets Default gate.
     */
    public function testCreateSubAgentWithNoPermissionModeGetsDefaultGate(): void
    {
        $agentManager = new AgentManager($this->provider, $this->skillRegistry);
        $agent = $this->createAgent($agentManager, 'default-mode-agent', 'Default mode test');

        $subAgent = $agentManager->createSubAgent('default-mode-agent', 'Some task');

        $this->assertNotNull($subAgent->permissionGate);
        $this->assertSame(PermissionMode::Default, $subAgent->permissionGate->mode());
    }

    /**
     * Test 2: AgentManager::createSubAgent() with Plan mode → SubAgent gets Plan gate.
     */
    public function testCreateSubAgentWithPlanModeGetsPlanGate(): void
    {
        $agentManager = new AgentManager($this->provider, $this->skillRegistry);
        $agent = $this->createAgent($agentManager, 'plan-mode-agent', 'Plan mode test');

        $subAgent = $agentManager->createSubAgent('plan-mode-agent', 'Plan task', PermissionMode::Plan);

        $this->assertNotNull($subAgent->permissionGate);
        $this->assertSame(PermissionMode::Plan, $subAgent->permissionGate->mode());
    }

    /**
     * Test 3: AgentManager::createSubAgent() with BypassPermissions mode → SubAgent gets BypassPermissions gate.
     */
    public function testCreateSubAgentWithBypassPermissionsModeGetsBypassPermissionsGate(): void
    {
        $agentManager = new AgentManager($this->provider, $this->skillRegistry);
        $agent = $this->createAgent($agentManager, 'bypass-mode-agent', 'Bypass mode test');

        $subAgent = $agentManager->createSubAgent(
            'bypass-mode-agent',
            'Bypass task',
            PermissionMode::BypassPermissions,
        );

        $this->assertNotNull($subAgent->permissionGate);
        $this->assertSame(PermissionMode::BypassPermissions, $subAgent->permissionGate->mode());
    }

    /**
     * Test 4a: Mode is locked after first createSubAgent() call.
     */
    public function testCreateSubAgentLocksModeAfterFirstCall(): void
    {
        $factory = function (PermissionMode $mode): PermissionGate {
            return new PermissionGate($mode);
        };

        $agentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            permissionGateFactory: $factory,
        );

        $agent = $this->createAgent($agentManager, 'lock-test-agent', 'Lock test');

        // First call with Default mode succeeds
        $agentManager->createSubAgent('lock-test-agent', 'Task 1');

        // Second call with a DIFFERENT mode on the SAME instance throws LogicException
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Permission mode cannot be changed mid-session');
        $agentManager->createSubAgent('lock-test-agent', 'Task 2', PermissionMode::Plan);
    }

    /**
     * Test 4b: A new AgentManager instance CAN use a different mode.
     */
    public function testCreateSubAgentWithCustomFactoryIsCalledWithCorrectMode(): void
    {
        // Three separate factory calls, each capturing its mode
        $capturedModes = [];

        // Instance 1: Default mode
        $factory1 = function (PermissionMode $mode) use (&$capturedModes): PermissionGate {
            $capturedModes[] = $mode;
            return new PermissionGate($mode);
        };
        $agentManager1 = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            permissionGateFactory: $factory1,
        );
        $agent1 = $this->createAgent($agentManager1, 'factory-test-agent-1', 'Factory test 1');
        $agentManager1->createSubAgent('factory-test-agent-1', 'Task 1');

        // Instance 2: Plan mode
        $factory2 = function (PermissionMode $mode) use (&$capturedModes): PermissionGate {
            $capturedModes[] = $mode;
            return new PermissionGate($mode);
        };
        $agentManager2 = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            permissionGateFactory: $factory2,
        );
        $agent2 = $this->createAgent($agentManager2, 'factory-test-agent-2', 'Factory test 2');
        $agentManager2->createSubAgent('factory-test-agent-2', 'Task 2', PermissionMode::Plan);

        // Instance 3: BypassPermissions mode
        $factory3 = function (PermissionMode $mode) use (&$capturedModes): PermissionGate {
            $capturedModes[] = $mode;
            return new PermissionGate($mode);
        };
        $agentManager3 = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            permissionGateFactory: $factory3,
        );
        $agent3 = $this->createAgent($agentManager3, 'factory-test-agent-3', 'Factory test 3');
        $agentManager3->createSubAgent('factory-test-agent-3', 'Task 3', PermissionMode::BypassPermissions);

        $this->assertCount(3, $capturedModes);
        $this->assertSame(PermissionMode::Default, $capturedModes[0]);
        $this->assertSame(PermissionMode::Plan, $capturedModes[1]);
        $this->assertSame(PermissionMode::BypassPermissions, $capturedModes[2]);
    }

    /**
     * Test 5: SubAgent has has_permission_gate = true when created via createSubAgent.
     */
    public function testSubAgentCreatedViaCreateSubAgentHasPermissionGate(): void
    {
        $agentManager = new AgentManager($this->provider, $this->skillRegistry);
        $agent = $this->createAgent($agentManager, 'has-gate-agent', 'Has gate test');

        $subAgent = $agentManager->createSubAgent('has-gate-agent', 'Gate presence test');

        $this->assertNotNull($subAgent->permissionGate);

        // Verify via toArray() which exposes has_permission_gate
        $arr = $subAgent->toArray();
        $this->assertArrayHasKey('has_permission_gate', $arr);
        $this->assertTrue($arr['has_permission_gate']);
    }

    /**
     * Test 6: Gate is correctly accessible from SubAgent.
     */
    public function testGateIsCorrectlyAccessibleFromSubAgent(): void
    {
        $agentManager = new AgentManager($this->provider, $this->skillRegistry);
        $agent = $this->createAgent($agentManager, 'accessible-gate-agent', 'Accessible gate test');

        $subAgent = $agentManager->createSubAgent(
            'accessible-gate-agent',
            'Access gate test',
            PermissionMode::AcceptEdits,
        );

        // Gate is directly accessible via the property
        $gate = $subAgent->permissionGate;
        $this->assertInstanceOf(PermissionGate::class, $gate);

        // Gate reports the correct mode
        $this->assertSame(PermissionMode::AcceptEdits, $gate->mode());
    }

    /**
     * Helper to create and register an Agent for testing.
     */
    private function createAgent(AgentManager $agentManager, string $name, string $prompt): Agent
    {
        $agent = new Agent(
            name: $name,
            description: "$name description",
            prompt: $prompt,
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
        $agentManager->register($agent);
        return $agent;
    }
}
