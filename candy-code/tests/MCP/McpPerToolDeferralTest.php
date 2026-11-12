<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\McpToolBridge;
use SugarCraft\Code\Util\TokenEstimate;

/**
 * #18: deferral is decided per tool. A server is never demoted as a unit, so a
 * large server defers only the tools it takes to get the launch under the
 * lazy schema floor, and everything else stays a first-class bridge.
 */
final class McpPerToolDeferralTest extends TestCase
{
    private string $deadPath;

    protected function setUp(): void
    {
        $this->deadPath = '/nonexistent/mcp-18-deferral-' . getmypid() . '/.mcp.json';
    }

    private function descriptor(string $server, string $name, int $padding): McpTool
    {
        return McpTool::fromArray([
            'name' => $name,
            'description' => 'first ' . str_repeat('x', $padding) . ' last',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['note' => ['type' => 'string']],
                'required' => [],
            ],
        ], $server);
    }

    private function cost(McpTool $tool): int
    {
        return TokenEstimate::ofToolSchema(
            McpToolBridge::wireNameFor($tool->serverName, $tool->name),
            $tool->description,
            $tool->inputSchema,
        );
    }

    public function testASmallServerStaysFullyEager(): void
    {
        $small = [
            $this->descriptor('tiny-18', 'ping', 0),
            $this->descriptor('tiny-18', 'pong', 0),
        ];
        $total = array_sum(array_map(fn (McpTool $t): int => $this->cost($t), $small));

        $shape = McpToolShape::derive(new McpClient($this->deadPath), $small, [], $total + 1);

        $this->assertFalse($shape->lazy, 'a launch under the floor defers nothing');
        $this->assertCount(2, $shape->bridges());
        $this->assertSame([], $shape->dispatchedDescriptors);
        $this->assertSame('', $shape->lazyDemotionNotice());
    }

    public function testALargeServerDefersOnlyEnoughToolsToFit(): void
    {
        $big = $this->descriptor('large-18', 'big', 3000);
        $mid = $this->descriptor('large-18', 'mid', 1500);
        $tiny = $this->descriptor('large-18', 'tiny', 100);
        $costs = [$this->cost($big), $this->cost($mid), $this->cost($tiny)];
        $total = array_sum($costs);

        // Deferring the largest alone brings the eager total under the floor;
        // deferring nothing does not. Exactly one tool should leave.
        $floor = $total - $costs[0] + 1;
        $shape = McpToolShape::derive(new McpClient($this->deadPath), [$big, $mid, $tiny], [], $floor);

        $this->assertTrue($shape->lazy);
        $this->assertSame(['large-18/big'], $shape->deferredTools);
        $this->assertSame(['mid', 'tiny'], array_map(
            static fn (McpTool $t): string => $t->name,
            $shape->eagerDescriptors,
        ));
        $this->assertSame(['big'], array_map(
            static fn (McpTool $t): string => $t->name,
            $shape->dispatchedDescriptors,
        ));
        $this->assertCount(2, $shape->bridges(), 'the untouched tools keep their first-class bridges');
        $this->assertStringContainsString('large-18/big', $shape->lazyDemotionNotice());
        $this->assertStringNotContainsString('mid', $shape->lazyDemotionNotice(), 'the bridged siblings are not named');
    }

    public function testADeferredToolIsStillReachableThroughMcpCall(): void
    {
        $big = $this->descriptor('large-18', 'big', 3000);
        $tiny = $this->descriptor('large-18', 'tiny', 100);
        $floor = $this->cost($tiny) + 1;

        $shape = McpToolShape::derive(new McpClient($this->deadPath), [$big, $tiny], [], $floor);
        $this->assertNotNull($shape->findDispatched('large-18', 'big'), 'the deferred tool is on the dispatched roster');

        $result = $shape->callTool()->execute([
            'id' => 'c1',
            'server' => 'large-18',
            'tool' => 'big',
            'arguments' => [],
        ]);

        $this->assertInstanceOf(McpCallTool::class, $shape->callTool());
        $this->assertStringNotContainsString(
            'limited to the deferred roster',
            $result->content(),
            'a deferred tool passes mcp_call\'s roster gate; it only fails later, at the dead client',
        );
    }
}
