<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Permissions\ChannelToolScope;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\ToolCall;

/**
 * D6 at the sub-agent boundary (cc-review-1111 M-2): the session's channel
 * tool scope must RIDE the delegation, so a TaskTool-spawned sub-agent of a
 * channel-scoped session enforces the same per-group allow-deny policy as its
 * parent — and an unrestricted session is byte-identical to before.
 *
 * The pins sit at gate level only: build the manager the way production
 * builds it (a mode-only {@see PermissionGate} factory), mint a sub-agent
 * with and without a scope, and ask the sub-agent's OWN gate. No provider is
 * ever dialled, no wall-clock budget is armed — a {@see ProviderInterface}
 * mock exists only because the manager constructor asks for one.
 */
final class SubAgentChannelScopeTest extends TestCase
{
    public function testARefusingAllChannelScopeRidesTheSubAgentGate(): void
    {
        $manager = new AgentManager(
            $this->createMock(ProviderInterface::class),
            new SkillRegistry(),
            permissionGateFactory: static fn (PermissionMode $mode): PermissionGate => new PermissionGate($mode),
        );
        $manager->register(new Agent(
            name: 'scope-riding-agent',
            description: 'a preset for the D6 riding pin',
            prompt: 'Test prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));

        // The admission a corrupt policy map produces: fail closed, refuse
        // everything. Pre-fix this scope evaporated at createSubAgent().
        $scope = ChannelToolScope::refusingAll('discord group guild-9', 'group', 'tools.deny was not a list');

        $subAgent = $manager->createSubAgent('scope-riding-agent', 'do the thing', null, null, $scope);

        self::assertSame($scope, $subAgent->permissionGate->channelScope());
        self::assertSame(
            PermissionDecision::Deny,
            $subAgent->permissionGate->evaluate(new ToolCall('Bash', ['command' => 'echo hi'])),
            'a refusing-all session scope must refuse the delegated call too',
        );
        self::assertStringContainsString('could not be honoured', (string) $subAgent->permissionGate->lastChannelReason());
    }

    public function testADenyListChannelScopeRefusesTheDeniedToolOnly(): void
    {
        $manager = new AgentManager(
            $this->createMock(ProviderInterface::class),
            new SkillRegistry(),
            permissionGateFactory: static fn (PermissionMode $mode): PermissionGate => new PermissionGate($mode),
        );
        $manager->register(new Agent(
            name: 'deny-riding-agent',
            description: 'a preset for the D6 deny-list pin',
            prompt: 'Test prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));

        $scope = ChannelToolScope::of('telegram group chat-3', 'group', [], [], ['WebFetch']);
        $subAgent = $manager->createSubAgent('deny-riding-agent', 'fetch it', null, null, $scope);

        self::assertSame($scope, $subAgent->permissionGate->channelScope());
        self::assertSame(PermissionDecision::Deny, $subAgent->permissionGate->evaluate(new ToolCall('WebFetch', ['url' => 'https://example.com'])));
        self::assertNotSame(PermissionDecision::Deny, $subAgent->permissionGate->evaluate(new ToolCall('Read', ['path' => 'a.php'])), 'a deny-only scope has no opinion on tools it never named');
    }

    public function testAnUnrestrictedSessionSubAgentCarriesNoChannelOpinion(): void
    {
        $manager = new AgentManager(
            $this->createMock(ProviderInterface::class),
            new SkillRegistry(),
            permissionGateFactory: static fn (PermissionMode $mode): PermissionGate => new PermissionGate($mode),
        );
        $manager->register(new Agent(
            name: 'unscoped-riding-agent',
            description: 'a preset for the positive control',
            prompt: 'Test prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));

        // Null scope = the session came through the CLI, not an admission
        // gate. The child's gate must be exactly what it was before D6
        // threading existed: no channel field, no channel reason.
        $subAgent = $manager->createSubAgent('unscoped-riding-agent', 'do the thing');

        self::assertNull($subAgent->permissionGate->channelScope());
        $subAgent->permissionGate->evaluate(new ToolCall('Bash', ['command' => 'echo hi']));
        self::assertNull($subAgent->permissionGate->lastChannelReason());
    }

    /**
     * The wiring seam itself: both TaskTool delegations (the execute path and
     * the forked-skill path) must hand the session gate's channel scope down
     * as the fifth createSubAgent() argument. Source-shape pin — house idiom
     * for a call-site that no behavioural test here can reach without a live
     * engine.
     */
    public function testBothTaskToolDelegationsPassTheSessionChannelScope(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Tools/BuiltIn/TaskTool.php'
        );

        self::assertSame(
            1,
            substr_count($source, '$this->engine?->permissionGate()?->channelScope(),'),
            'the execute-path delegation must thread the nullable-engine channel scope',
        );
        self::assertSame(
            1,
            substr_count($source, '$this->engine->permissionGate()?->channelScope(),'),
            'the forked-skill delegation must thread the channel scope',
        );
    }
}
