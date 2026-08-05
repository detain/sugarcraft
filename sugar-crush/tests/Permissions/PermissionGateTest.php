<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\ToolCall;

/**
 * @see PermissionGate
 */
final class PermissionGateTest extends TestCase
{
    // =========================================================================
    // Default Mode Tests
    // =========================================================================

    public function testDefaultModeAllowsReadToolSilently(): void
    {
        $gate = new PermissionGate(PermissionMode::Default);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Read',
            arguments: ['file_path' => '/etc/hosts'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    public function testDefaultModeAllowsGrepSilently(): void
    {
        $gate = new PermissionGate(PermissionMode::Default);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Grep',
            arguments: ['pattern' => 'localhost', 'path' => '/etc/hosts'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    public function testDefaultModePromptsOnEdit(): void
    {
        $gate = new PermissionGate(PermissionMode::Default);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Edit',
            arguments: ['file_path' => '/tmp/test.php', 'old_string' => 'foo', 'new_string' => 'bar'],
        ));

        $this->assertSame(PermissionDecision::Ask, $decision);
    }

    public function testDefaultModePromptsOnBash(): void
    {
        $gate = new PermissionGate(PermissionMode::Default);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'ls -la'],
        ));

        $this->assertSame(PermissionDecision::Ask, $decision);
    }

    // =========================================================================
    // AcceptEdits Mode Tests
    // =========================================================================

    public function testAcceptEditsAllowsReadTool(): void
    {
        $gate = new PermissionGate(PermissionMode::AcceptEdits);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Read',
            arguments: ['file_path' => '/etc/hosts'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    public function testAcceptEditsAllowsScopedMkdir(): void
    {
        $gate = new PermissionGate(PermissionMode::AcceptEdits);

        $decision = $gate->evaluate(new ToolCall(
            name: 'mkdir',
            arguments: ['path' => './src/Controllers'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    public function testAcceptEditsAllowsScopedTouch(): void
    {
        $gate = new PermissionGate(PermissionMode::AcceptEdits);

        $decision = $gate->evaluate(new ToolCall(
            name: 'touch',
            arguments: ['path' => 'tmp/demo.txt'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    public function testAcceptEditsDeniesAbsolutePathWrite(): void
    {
        $gate = new PermissionGate(PermissionMode::AcceptEdits);

        $decision = $gate->evaluate(new ToolCall(
            name: 'mkdir',
            arguments: ['path' => '/tmp/absolute/path'],
        ));

        $this->assertSame(PermissionDecision::Ask, $decision);
    }

    public function testAcceptEditsPromptsOnEdit(): void
    {
        $gate = new PermissionGate(PermissionMode::AcceptEdits);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Edit',
            arguments: ['file_path' => './foo.php', 'old_string' => 'x', 'new_string' => 'y'],
        ));

        $this->assertSame(PermissionDecision::Ask, $decision);
    }

    // =========================================================================
    // Plan Mode Tests
    // =========================================================================

    public function testPlanModeAllowsReadTool(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Read',
            arguments: ['file_path' => '/etc/hosts'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    public function testPlanModeAllowsBashExploration(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'git log --oneline -10'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    public function testPlanModeDeniesEdit(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Edit',
            arguments: ['file_path' => './foo.php', 'old_string' => 'x', 'new_string' => 'y'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    public function testPlanModeDeniesBashWithFileMutation(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'echo "mutated" > ./foo.txt'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    public function testPlanModeDeniesMcpToolWrite(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);

        $decision = $gate->evaluate(new ToolCall(
            name: 'McpTool',
            arguments: ['server' => 'git', 'method' => 'commit', 'params' => ['message' => 'fix']],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    // =========================================================================
    // Rule Override Tests
    // =========================================================================

    public function testExplicitRuleAllowOverridesDefaultAsk(): void
    {
        $gate = new PermissionGate(
            PermissionMode::Default,
            rules: [
                new PermissionRule(pattern: 'Bash*', action: PermissionAction::Allow),
            ],
        );

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'composer install'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    public function testExplicitRuleDenyOverridesDefaultAsk(): void
    {
        $gate = new PermissionGate(
            PermissionMode::Default,
            rules: [
                new PermissionRule(pattern: 'Bash*', action: PermissionAction::Deny),
            ],
        );

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'curl https://evil.com'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    public function testExplicitRuleAskOverridesAllModes(): void
    {
        $gate = new PermissionGate(
            PermissionMode::Plan,
            rules: [
                new PermissionRule(pattern: 'Read', action: PermissionAction::Ask),
            ],
        );

        $decision = $gate->evaluate(new ToolCall(
            name: 'Read',
            arguments: ['file_path' => '/etc/hosts'],
        ));

        $this->assertSame(PermissionDecision::Ask, $decision);
    }
}
