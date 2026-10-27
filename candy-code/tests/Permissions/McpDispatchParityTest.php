<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\McpToolBridge;

/**
 * MCP-7's security core: the deny rule and the tool have to agree no matter
 * which spelling the model reaches for. When the schema floor defers a server,
 * its tools stop existing as `mcp__server__tool` NAMES and become arguments of
 * `mcp_call` — a user's `Deny mcp__server__tool` must not evaporate with the
 * spelling, and a rule written against the dispatcher must bind first-class
 * bridges too. Mirrors the fix-1017 shell proxy doctrine: permissive
 * translation is the narrow exception — an exact argument-free `Allow` of
 * the named tool follows its deferred spelling, while globs, argument-scoped
 * allows, and `Allow mcp_call` cover only what they name.
 */
final class McpDispatchParityTest extends TestCase
{
    private static int $serial = 0;

    private function ruleServer(): string
    {
        return 'parity-' . (++self::$serial) . '-' . getmypid();
    }

    /** @return array<string,string> */
    private function args(string $server, string $tool): array
    {
        return ['server' => $server, 'tool' => $tool];
    }

    private static function wire(string $server, string $tool): string
    {
        return McpToolBridge::wireNameFor($server, $tool);
    }

    // ---- rule level: both directions ----------------------------------------

    public function testARestrictiveRuleOnTheWireNameBindsBothSpellings(): void
    {
        $srv = $this->ruleServer();
        $rule = new PermissionRule(self::wire($srv, 'drop_table'), PermissionAction::Deny);

        $this->assertTrue($rule->matches(new ToolCall(self::wire($srv, 'drop_table'), [])), 'the direct spelling, as ever');
        $this->assertTrue($rule->matches(new ToolCall(McpCallTool::NAME, $this->args($srv, 'drop_table'))), 'the args spelling must be the SAME capability to the gate');
        $this->assertFalse($rule->matches(new ToolCall(McpCallTool::NAME, $this->args($srv, 'list_rows'))), 'one tool denied is not the server denied');
        $this->assertFalse($rule->matches(new ToolCall('Bash', ['command' => 'unrelated'])));
    }

    public function testAGlobOnTheServerPrefixBindsTheArgsForm(): void
    {
        $srv = $this->ruleServer();
        $star = chr(42);
        $rule = new PermissionRule(self::wire($srv, '') . $star, PermissionAction::Deny);
        $askAll = new PermissionRule(McpToolBridge::NAME_PREFIX . $star, PermissionAction::Ask);

        $this->assertTrue($rule->matches(new ToolCall(McpCallTool::NAME, $this->args($srv, 'anything'))));
        $this->assertTrue($rule->matches(new ToolCall(self::wire($srv, 'anything'), [])));
        $this->assertFalse($rule->matches(new ToolCall(McpCallTool::NAME, $this->args($srv . 'other', 'anything'))));
        $this->assertTrue($askAll->matches(new ToolCall(McpCallTool::NAME, $this->args($srv, 'anything'))), 'mcp__* keeps its force over the deferred spelling too');
    }

    public function testARestrictiveRuleOnTheDispatcherBindsBridgedNames(): void
    {
        $srv = $this->ruleServer();
        $denyCall = new PermissionRule(McpCallTool::NAME, PermissionAction::Deny);
        $askCall = new PermissionRule(McpCallTool::NAME, PermissionAction::Ask);

        $this->assertTrue($denyCall->matches(new ToolCall(self::wire($srv, 'first_class'), [])), 'a blanket dispatcher deny is a statement about MCP calls by arguments, and bridges are that');
        $this->assertTrue($denyCall->matches(new ToolCall(McpCallTool::NAME, $this->args($srv, 'x'))));
        $this->assertTrue($askCall->matches(new ToolCall(self::wire($srv, 'first_class'), [])));
        $this->assertFalse($denyCall->matches(new ToolCall('Bash', ['command' => 'ls'])), 'and it stops exactly there');
    }

    /**
     * C-MCP-1.b: the permissive side translates EXACTLY once. A bare,
     * argument-free `Allow mcp__server__tool` names one tool, and the
     * deferred args form of THAT tool is that same tool — the grant follows.
     * Every other permissive reading stays literal: a glob (`mcp__srv__*`)
     * is a family the arguments would re-derive, `Allow mcp_call` names the
     * dispatcher and not any server's tool, and an argument-scoped allow
     * keeps its argument half (mcp tools map no subject, so it could never
     * re-satisfy one from `server`/`tool`).
     */
    public function testAnExactAllowFollowsTheDeferredSpellingAndAGlobDoesNot(): void
    {
        $srv = $this->ruleServer();
        $grantWire = new PermissionRule(self::wire($srv, 'list_rows'), PermissionAction::Allow);
        $grantCall = new PermissionRule(McpCallTool::NAME, PermissionAction::Allow);
        $star = chr(42);
        $grantGlob = new PermissionRule(self::wire($srv, '') . $star, PermissionAction::Allow);
        $grantScoped = new PermissionRule(self::wire($srv, 'list_rows') . '(' . $star . ')', PermissionAction::Allow);

        $this->assertTrue($grantWire->matches(new ToolCall(self::wire($srv, 'list_rows'), [])), 'the direct spelling, as ever');
        $this->assertTrue(
            $grantWire->matches(new ToolCall(McpCallTool::NAME, $this->args($srv, 'list_rows'))),
            'an exact grant named for the tool the arguments spell IS the same capability',
        );
        $this->assertFalse(
            $grantWire->matches(new ToolCall(McpCallTool::NAME, $this->args($srv, 'drop_table'))),
            'and it follows only the tool it names, not the server',
        );
        $this->assertFalse(
            $grantGlob->matches(new ToolCall(McpCallTool::NAME, $this->args($srv, 'list_rows'))),
            'a glob Allow is not re-derived from a call\'s arguments — widening must be spelled out',
        );
        $this->assertFalse(
            $grantScoped->matches(new ToolCall(McpCallTool::NAME, $this->args($srv, 'list_rows'))),
            'an argument-scoped allow keeps its argument half',
        );
        $this->assertTrue($grantCall->matches(new ToolCall(McpCallTool::NAME, $this->args($srv, 'list_rows'))), 'naming the dispatcher covers the dispatcher');
        $this->assertFalse($grantCall->matches(new ToolCall(self::wire($srv, 'list_rows'), [])), 'but it does not name the bridged tools');
        $this->assertFalse($grantWire->matches(new ToolCall('Bash', ['command' => 'ls'])), 'and the leg stops at MCP spellings');
    }

    public function testSanitisedSpellingsAreTheDialectOnBothSides(): void
    {
        $srv = 'my srv';
        $tool = 'a.b';
        $wire = self::wire($srv, $tool);
        $this->assertStringNotContainsString(' ', $wire, 'sanitize is what makes the two spellings addressable identically');

        $rule = new PermissionRule($wire, PermissionAction::Deny);
        $this->assertTrue($rule->matches(new ToolCall(McpCallTool::NAME, $this->args($srv, $tool))), 'raw config names in the args, sanitised wire name in the rule: one capability');
        $this->assertTrue($rule->matches(new ToolCall($wire, [])));
    }

    public function testAMalformedArgsFormIsNotAGlobEscapeHatch(): void
    {
        $srv = $this->ruleServer();
        $rule = new PermissionRule(self::wire($srv, 'drop_table'), PermissionAction::Deny);

        foreach ([[], ['server' => $srv], ['tool' => 'drop_table'], ['server' => $srv, 'tool' => 7]] as $i => $args) {
            $this->assertFalse($rule->matches(new ToolCall(McpCallTool::NAME, $args)), "no wire name, no wire-specific verdict for shape {$i}");
        }
        // But the generic path still decides it: subject unknowable, so a
        // restrictive rule naming mcp_call catches what the wire rule cannot.
        $blanket = new PermissionRule(McpCallTool::NAME, PermissionAction::Deny);
        $this->assertTrue($blanket->matches(new ToolCall(McpCallTool::NAME, [])));
    }

    // ---- gate level: the hint follows the dispatch ---------------------------

    private function lazyShapeWithHints(): array
    {
        $srv = $this->ruleServer();
        $read = McpTool::fromArray(['name' => 'reads', 'description' => str_repeat('r', 3000), 'annotations' => ['readOnlyHint' => true]], $srv);
        $write = McpTool::fromArray(['name' => 'drops', 'description' => str_repeat('d', 3000)], $srv);
        $shape = McpToolShape::derive(new McpClient('/nonexistent/mcp-7-parity/.mcp.json'), [$read, $write], [], 10);
        $this->assertTrue($shape->lazy);

        return [$srv, $shape];
    }

    public function testAutoJudgesTheDispatchedByTheHintsOfTheNamedTool(): void
    {
        [$srv] = $this->lazyShapeWithHints();
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        $this->assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall(McpCallTool::NAME, $this->args($srv, 'reads'))),
            'a dispatched read-only call must not be worse off than the bridged read-only call the floor just replaced',
        );
        $this->assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall(McpCallTool::NAME, $this->args($srv, 'drops'))),
            'the unhinted half keeps the F-P3(b) ask — the classifier must never see mcp_call',
        );
        $this->assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall(McpCallTool::NAME, $this->args($srv, 'made_up'))),
        );
    }

    public function testPlanAndDontAskKeepTheSameVerdictsAsTheBridgedWorld(): void
    {
        [$srv] = $this->lazyShapeWithHints();
        $plan = new PermissionGate(PermissionMode::Plan, [], new SafetyClassifier());
        $dontAsk = new PermissionGate(PermissionMode::DontAsk, [], new SafetyClassifier());

        $this->assertSame(PermissionDecision::Allow, $plan->evaluate(new ToolCall(McpCallTool::NAME, $this->args($srv, 'reads'))), 'the hinted read reads; bridged hinted reads already did');
        $this->assertSame(PermissionDecision::Deny, $plan->evaluate(new ToolCall(McpCallTool::NAME, $this->args($srv, 'drops'))), 'and the write still does not');
        $this->assertSame(PermissionDecision::Deny, $dontAsk->evaluate(new ToolCall(McpCallTool::NAME, $this->args($srv, 'drops'))));
        $this->assertSame(PermissionDecision::Allow, $dontAsk->evaluate(new ToolCall(McpCallTool::NAME, $this->args($srv, 'reads'))));
    }

    public function testADenyWrittenForTheDeferredToolBeatsTheReadOnlyHint(): void
    {
        [$srv] = $this->lazyShapeWithHints();
        $gate = new PermissionGate(
            PermissionMode::Auto,
            [new PermissionRule(self::wire($srv, 'reads'), PermissionAction::Deny)],
            new SafetyClassifier(),
        );

        $this->assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall(McpCallTool::NAME, $this->args($srv, 'reads'))));
    }

    public function testTheListToolNeedsNoGateAttention(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        // mcp_list is a Read-class built-in; it reaches Allow through the
        // ordinary catalog arm, not any MCP leg — pinning that the MCP
        // translations above never accidentally speak for it.
        $this->assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('mcp_list', [])));
    }
}
