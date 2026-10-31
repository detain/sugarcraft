<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tools\ToolCall as EngineToolCall;
use SugarCraft\Code\Tools\UserTools\UserScriptTool;

/**
 * The permission twin of the MCP rule (`C-TOOL-6.e`): a `user__*` call is
 * write-classed, always asks wherever a judgment is made, is never counted as
 * a strike or a safe call by the auto breaker, and one explicit rule grants
 * exactly one tool.
 */
final class UserToolPermissionTest extends TestCase
{
    /**
     * @return array<string, array{0: PermissionMode, 1: PermissionDecision}>
     */
    public static function c6eModeRows(): array
    {
        return [
            'default asks' => [PermissionMode::Default, PermissionDecision::Ask],
            'plan refuses' => [PermissionMode::Plan, PermissionDecision::Deny],
            'auto asks' => [PermissionMode::Auto, PermissionDecision::Ask],
            'dont-ask refuses' => [PermissionMode::DontAsk, PermissionDecision::Deny],
            'accept-edits asks' => [PermissionMode::AcceptEdits, PermissionDecision::Ask],
            'bypass allows' => [PermissionMode::BypassPermissions, PermissionDecision::Allow],
        ];
    }

    public function testEveryModeDecidesAUserToolLikeTheDesignMatrix(): void
    {
        foreach (self::c6eModeRows() as $case => [$mode, $expected]) {
            $gate = new PermissionGate($mode, [], new SafetyClassifier());

            self::assertSame(
                $expected,
                $gate->evaluate(new ToolCall(UserScriptTool::NAME_PREFIX . 'fmt', ['path' => 'x'], 'c1')),
                $case,
            );
        }
    }

    public function testAnAutoAskLeavesTheBreakerExactlyAsItWas(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());
        // Strike once with a real blocked call so "untouched" has content:
        // a reset would move the count, a strike would raise it.
        $gate->evaluate(new ToolCall('Bash', ['command' => 'git push --force origin main'], 's1'));
        $before = $gate->autoBreaker();
        self::assertGreaterThan(0, $before['consecutiveBlocks'], 'the pin needs a live strike');

        $decision = $gate->evaluate(new ToolCall(UserScriptTool::NAME_PREFIX . 'fmt', ['path' => 'x'], 'c1'));

        self::assertSame(PermissionDecision::Ask, $decision);
        self::assertSame($before, $gate->autoBreaker(), 'an ask is neither a strike nor a safe call');
    }

    public function testAnExplicitRuleGrantsExactlyOneTool(): void
    {
        $gate = new PermissionGate(
            PermissionMode::Auto,
            [new PermissionRule(UserScriptTool::NAME_PREFIX . 'fmt', PermissionAction::Allow)],
            new SafetyClassifier(),
        );

        self::assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall(UserScriptTool::NAME_PREFIX . 'fmt', ['path' => 'x'], 'c1')),
        );
        self::assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall(UserScriptTool::NAME_PREFIX . 'other', ['path' => 'x'], 'c2')),
        );
    }

    public function testAnExplicitDenyRuleBindsEvenUnderBypass(): void
    {
        $gate = new PermissionGate(
            PermissionMode::BypassPermissions,
            [new PermissionRule(UserScriptTool::NAME_PREFIX . 'fmt', PermissionAction::Deny)],
            new SafetyClassifier(),
        );

        self::assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall(UserScriptTool::NAME_PREFIX . 'fmt', ['path' => 'x'], 'c1')),
        );
    }

    public function testAUserToolCallCountsAsAWriteForTheStepScanner(): void
    {
        // The step scanner reads the ENGINE pair (AssistantMessage::toolCalls());
        // the gate above reads the root pair — both are name-keyed.
        self::assertTrue(Runtime::stepRequestedAWrite([
            new EngineToolCall('c1', UserScriptTool::NAME_PREFIX . 'x', []),
        ]));
        self::assertTrue(Runtime::stepRequestedAWrite([
            new EngineToolCall('c1', 'mcp__db__drop', []),
        ]));
        self::assertFalse(Runtime::stepRequestedAWrite([
            new EngineToolCall('c1', 'Read', ['file_path' => '/tmp/x']),
        ]));
    }

    /**
     * The gate keeps its literal MCP statement — the twin arm is ADDED, the
     * sentence every existing pin reads is untouched (RuntimeTest's own
     * source regex plus this one lock the shape from both sides).
     */
    public function testTheGateStillHoldsTheLiteralMcpSentence(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Permissions/PermissionGate.php',
        );
        $bodyStart = strpos($source, 'function isWriteTool');
        self::assertIsInt($bodyStart);
        $body = substr($source, $bodyStart, strpos($source, "\n    }", $bodyStart) - $bodyStart);

        self::assertMatchesRegularExpression(
            '/return str_starts_with\(\$\w+->name, \'mcp__\'\);/',
            $body,
        );
        // The user arm is consulted BEFORE the literal final return — it
        // spells its prefix through the class constant, the MCP tail in
        // the literal the sentence preserves.
        $userArm = strpos($body, 'UserScriptTool::NAME_PREFIX');
        $mcpLine = strpos($body, "'mcp__'");
        self::assertIsInt($userArm);
        self::assertIsInt($mcpLine);
        self::assertLessThan($mcpLine, $userArm);
    }
}
