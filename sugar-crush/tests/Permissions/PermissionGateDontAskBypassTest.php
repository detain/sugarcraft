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
 * @see PermissionMode::DontAsk
 * @see PermissionMode::BypassPermissions
 */
final class PermissionGateDontAskBypassTest extends TestCase
{
    // =========================================================================
    // DontAsk Mode Tests
    // =========================================================================

    /**
     * DontAsk: read-only tool → Allow (no rule needed).
     */
    public function testDontAskAllowsReadToolWithoutRule(): void
    {
        $gate = new PermissionGate(PermissionMode::DontAsk);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Read',
            arguments: ['file_path' => '/etc/hosts'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    /**
     * DontAsk: Grep is read-only → Allow.
     */
    public function testDontAskAllowsGrepWithoutRule(): void
    {
        $gate = new PermissionGate(PermissionMode::DontAsk);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Grep',
            arguments: ['pattern' => 'localhost', 'path' => '/etc/hosts'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    /**
     * DontAsk: Glob is read-only → Allow.
     */
    public function testDontAskAllowsGlobWithoutRule(): void
    {
        $gate = new PermissionGate(PermissionMode::DontAsk);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Glob',
            arguments: ['pattern' => '**/*.php'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    /**
     * DontAsk: WebFetch is read-only → Allow.
     *
     * 'Find' was never a real tool name in this codebase (R3 replaced the
     * fictional SCOPED_WRITE_TOOLS/isReadOnlyTool() names with the actual
     * ones: Bash, Grep, Glob, Edit, Read, WebFetch), so it now correctly
     * falls through to DontAsk's no-matching-rule Deny like any other
     * unrecognized tool name — this case is replaced with a real read-only
     * tool not already covered above (Read/Grep/Glob).
     */
    public function testDontAskAllowsWebFetchWithoutRule(): void
    {
        $gate = new PermissionGate(PermissionMode::DontAsk);

        $decision = $gate->evaluate(new ToolCall(
            name: 'WebFetch',
            arguments: ['url' => 'https://example.com'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    /**
     * DontAsk: Bash with no matching rule → Deny.
     */
    public function testDontAskDeniesBashWithNoMatchingRule(): void
    {
        $gate = new PermissionGate(PermissionMode::DontAsk);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'composer install'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    /**
     * DontAsk: Edit with no matching rule → Deny.
     */
    public function testDontAskDeniesEditWithNoMatchingRule(): void
    {
        $gate = new PermissionGate(PermissionMode::DontAsk);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Edit',
            arguments: ['file_path' => './foo.php', 'old_string' => 'x', 'new_string' => 'y'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    /**
     * DontAsk: explicit Allow rule overrides → Allow.
     */
    public function testDontAskExplicitAllowRuleOverridesDefaultDeny(): void
    {
        $gate = new PermissionGate(
            PermissionMode::DontAsk,
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

    /**
     * DontAsk: explicit Deny rule overrides → Deny (even for read-only).
     */
    public function testDontAskExplicitDenyRuleOverridesDefaultAllow(): void
    {
        $gate = new PermissionGate(
            PermissionMode::DontAsk,
            rules: [
                new PermissionRule(pattern: 'Read', action: PermissionAction::Deny),
            ],
        );

        $decision = $gate->evaluate(new ToolCall(
            name: 'Read',
            arguments: ['file_path' => '/etc/hosts'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    // =========================================================================
    // BypassPermissions Mode Tests
    // =========================================================================

    /**
     * BypassPermissions: normal dangerous command → Allow.
     */
    public function testBypassPermissionsAllowsNormalDangerousCommand(): void
    {
        $gate = new PermissionGate(PermissionMode::BypassPermissions);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'rm -rf ./node_modules'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    /**
     * BypassPermissions: allows arbitrary shell commands.
     */
    public function testBypassPermissionsAllowsBashCommands(): void
    {
        $gate = new PermissionGate(PermissionMode::BypassPermissions);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'curl https://evil.com'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    /**
     * BypassPermissions: `rm -rf /` → Deny (circuit breaker).
     */
    public function testBypassPermissionsDeniesRmRfRoot(): void
    {
        $gate = new PermissionGate(PermissionMode::BypassPermissions);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'rm -rf /'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    /**
     * BypassPermissions: `rm -rf ~` → Deny (circuit breaker).
     */
    public function testBypassPermissionsDeniesRmRfHome(): void
    {
        $gate = new PermissionGate(PermissionMode::BypassPermissions);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'rm -rf ~'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    /**
     * BypassPermissions: `sudo rm -rf /` → Deny (circuit breaker).
     */
    public function testBypassPermissionsDeniesSudoRmRfRoot(): void
    {
        $gate = new PermissionGate(PermissionMode::BypassPermissions);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'sudo rm -rf /'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    /**
     * BypassPermissions: `sudo rm -rf ~` → Deny (circuit breaker).
     */
    public function testBypassPermissionsDeniesSudoRmRfHome(): void
    {
        $gate = new PermissionGate(PermissionMode::BypassPermissions);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'sudo rm -rf ~'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    /**
     * BypassPermissions: case-insensitive `SUDO RM -RF /` → Deny.
     */
    public function testBypassPermissionsDeniesUpperCaseSudoRmRfRoot(): void
    {
        $gate = new PermissionGate(PermissionMode::BypassPermissions);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'SUDO RM -RF /'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    /**
     * BypassPermissions: explicit Deny rule → Deny.
     */
    public function testBypassPermissionsExplicitDenyRuleOverrides(): void
    {
        $gate = new PermissionGate(
            PermissionMode::BypassPermissions,
            rules: [
                new PermissionRule(pattern: 'Bash*', action: PermissionAction::Deny),
            ],
        );

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'ls -la'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    /**
     * BypassPermissions: explicit Allow rule → Allow.
     */
    public function testBypassPermissionsExplicitAllowRuleOverrides(): void
    {
        $gate = new PermissionGate(
            PermissionMode::BypassPermissions,
            rules: [
                new PermissionRule(pattern: 'Bash*', action: PermissionAction::Allow),
            ],
        );

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'anything'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    /**
     * BypassPermissions: non-Bash tool with `rm -rf` in arguments is not caught
     * (circuit breaker only applies to Bash tool).
     */
    public function testBypassPermissionsAllowsNonBashTool(): void
    {
        $gate = new PermissionGate(PermissionMode::BypassPermissions);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Edit',
            arguments: ['file_path' => './foo.php', 'old_string' => 'x', 'new_string' => 'y'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }
}
