<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AskFreeGrant;
use SugarCraft\Code\Agents\StructuredOutputTool;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Tools\BuiltIn\Grep;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

final class AskFreeGrantTest extends TestCase
{
    public function testReadClassToolsAndTheAnswerChannelCannotAsk(): void
    {
        $tools = [self::named('Read'), self::named('Grep'), self::named('Glob'), new StructuredOutputTool(['type' => 'object'])];

        $this->assertNull(AskFreeGrant::askRisk($tools, [new PermissionGate(PermissionMode::Default)]));
        $this->assertNull(AskFreeGrant::askRisk([], []), 'a run with no tools cannot ask');
    }

    public function testAnyToolOutsideTheReadClassMayAsk(): void
    {
        // WebFetch is ParallelSafe yet asks under `default` — parallel-safety is not the test.
        $this->assertSame(
            'may ask: Bash, WebFetch',
            AskFreeGrant::askRisk([self::named('Read'), self::named('Bash'), self::named('WebFetch'), self::named('Bash')], []),
        );
        $this->assertSame(
            'may ask: a, b, c, d and 2 more',
            AskFreeGrant::askRisk(array_map(self::named(...), ['a', 'b', 'c', 'd', 'e', 'f']), []),
        );
        $this->assertSame('may ask: mcp__srv__read', AskFreeGrant::askRisk([self::named('mcp__srv__read')], []));
    }

    public function testAnAskRuleNamingAReadToolMayAsk(): void
    {
        $gate = new PermissionGate(PermissionMode::Default, [
            new PermissionRule('Bash(git *)', PermissionAction::Ask),
            new PermissionRule('Gr*', PermissionAction::Ask),
        ]);

        $this->assertSame(
            'may ask: an Ask rule "Gr*" covers Grep',
            AskFreeGrant::askRisk([self::named('Read'), self::named('Grep')], [null, $gate]),
        );
    }

    public function testAllowAndDenyRulesDoNotMakeAReadAsk(): void
    {
        $gate = new PermissionGate(PermissionMode::Default, [
            new PermissionRule('Read', PermissionAction::Deny),
            new PermissionRule('Grep', PermissionAction::Allow),
        ]);

        $this->assertNull(AskFreeGrant::askRisk([self::named('Read'), self::named('Grep')], [$gate]));
    }

    public function testTheRealBuiltInsAreJudgedByName(): void
    {
        $this->assertNull(AskFreeGrant::askRisk([new Read(), new Grep()], []));
    }

    private static function named(string $name): Tool
    {
        return new class ($name) implements Tool {
            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'probe';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult('', 'ok');
            }
        };
    }
}
