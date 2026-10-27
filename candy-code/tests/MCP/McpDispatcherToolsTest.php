<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\BuiltIn\McpListTool;
use SugarCraft\Code\Tools\McpToolBridge;

/**
 * MCP-7 tool-surface tier: what the model SEES and ANSWERS with the pair,
 * driven in-process against a frozen shape whose client can never reach a
 * server. The wire round-trip (a dispatched call actually reaching a stdio
 * peer) lives in McpLazyToolLoadingTest; this file pins the messages, the
 * roster discipline, and the error envelope.
 */
final class McpDispatcherToolsTest extends TestCase
{
    private static int $serial = 0;

    private string $deadPath;

    protected function setUp(): void
    {
        $this->deadPath = '/nonexistent/mcp-7-dispatch-' . getmypid() . '/.mcp.json';
    }

    private function dispatchServer(): string
    {
        return 'disp-' . (++self::$serial) . '-' . getmypid();
    }

    private function descriptor(string $server, string $name, int $padding = 0, array $annotations = []): McpTool
    {
        return McpTool::fromArray([
            'name' => $name,
            'description' => $padding > 0 ? 'first ' . str_repeat('x', $padding) . ' last' : 'Answer with pong.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['note' => ['type' => 'string']],
                'required' => [],
            ],
            'annotations' => $annotations,
        ], $server);
    }

    /** @return list<McpTool> */
    private function fatSet(string $server, string ...$names): array
    {
        return array_map(fn (string $n): McpTool => $this->descriptor($server, $n, 3000), $names);
    }

    private function lazyShape(array $descriptors): McpToolShape
    {
        $shape = McpToolShape::derive(new McpClient($this->deadPath), $descriptors, [], 10);
        $this->assertTrue($shape->lazy, 'fixture discipline: these descriptors must land dispatched');

        return $shape;
    }

    // ---- roster shape ---------------------------------------------------------

    public function testThePairDeclareStableBuiltInShapedSchemas(): void
    {
        $shape = $this->lazyShape($this->fatSet($this->dispatchServer(), 'a'));
        $list = $shape->listTool();
        $call = $shape->callTool();

        $this->assertSame('mcp_list', $list->name());
        $this->assertSame('mcp_call', $call->name());

        $listSchema = $list->inputSchema();
        $this->assertSame('object', $listSchema['type']);
        $this->assertArrayHasKey('server', $listSchema['properties']);
        $this->assertArrayHasKey('tool', $listSchema['properties'], 'C-MCP-6.a: the exact-name lookup door is part of the declared shape');
        $this->assertSame([], $listSchema['required']);

        $callSchema = $call->inputSchema();
        $this->assertSame(['server', 'tool'], $callSchema['required']);
        $this->assertSame(['server', 'tool', 'arguments'], array_keys($callSchema['properties']));
    }

    // ---- mcp_list ---------------------------------------------------------------

    public function testTheListNamesServersToolsAndSchemasOfTheFrozenRoster(): void
    {
        $srv = $this->dispatchServer();
        $shape = $this->lazyShape([...$this->fatSet($srv, 'alpha_tool', 'beta_tool')]);

        $result = $shape->listTool()->execute(['id' => 'r1']);

        $this->assertFalse($result->isError());
        $this->assertSame('r1', $result->toolCallId());
        $this->assertStringContainsString('Server ' . $srv . ':', $result->content());
        $this->assertStringContainsString('- alpha_tool: first x', $result->content());
        $this->assertStringContainsString('arguments:', $result->content());
        $this->assertStringContainsString('"note"', $result->content(), 'the schema travels — mcp_list is the model\'s bridge over the deferral');
    }

    public function testTheListFiltersByServerAndSaysSoWhenNothingMatches(): void
    {
        $srv = $this->dispatchServer();
        $shape = $this->lazyShape($this->fatSet($srv, 'alpha_tool'));

        $hit = $shape->listTool()->execute(['server' => $srv]);
        $this->assertTrue(str_contains($hit->content(), 'alpha_tool'));

        $miss = $shape->listTool()->execute(['server' => 'no-such-server']);
        $this->assertFalse($miss->isError(), 'an empty filter answer is information, not a failure');
        $this->assertStringContainsString('no deferred mcp tools', strtolower($miss->content()));

        $bad = $shape->listTool()->execute(['server' => 7]);
        $this->assertTrue($bad->isError());
    }

    /**
     * C-MCP-6.a: a roster whose full schema text rides above the output cap
     * answers with a names-only overview plus the fetch pointer, and the
     * "tool" argument then brings each exact schema back — reachable, not
     * truncated away.
     */
    public function testAnOverCapRosterAnswersNamesAndTheToolArgumentFetchesSchemas(): void
    {
        $srv = $this->dispatchServer();
        $names = [];
        for ($i = 0; $i < 24; ++$i) {
            $names[] = 'fat_tool_' . $i;
        }
        $shape = $this->lazyShape($this->fatSet($srv, ...$names));

        $roster = $shape->listTool()->execute(['id' => 'cap']);
        $this->assertFalse($roster->isError());
        $this->assertStringContainsString('ride above the mcp_list output cap', $roster->content());
        $this->assertStringContainsString('call mcp_list with "tool"', $roster->content(), 'the overview teaches the fetch door');
        foreach ($names as $name) {
            $this->assertStringContainsString('- ' . $name, $roster->content(), 'every tool is named in the overview');
        }
        $this->assertStringNotContainsString('"note"', $roster->content(), 'no schema JSON survives into a names-only overview');
        $this->assertStringNotContainsString('xxx', $roster->content(), 'the fat descriptions are the weight the overview sheds');

        $one = $shape->listTool()->execute(['id' => 'fetch', 'tool' => 'fat_tool_3']);
        $this->assertFalse($one->isError());
        $this->assertSame('fetch', $one->toolCallId());
        $this->assertStringContainsString('- fat_tool_3: first x', $one->content());
        $this->assertStringContainsString('arguments:', $one->content());
        $this->assertStringContainsString('"note"', $one->content(), 'the fetched entry carries the exact schema');
        $this->assertStringNotContainsString('- fat_tool_4', $one->content(), 'the lookup answers ONLY the named tool');

        $badTool = $shape->listTool()->execute(['tool' => 7]);
        $this->assertTrue($badTool->isError(), 'a non-string tool name is refused in the server-arm shape');
        $this->assertStringContainsString('must be a string', $badTool->content());

        $miss = $shape->listTool()->execute(['tool' => 'no_such_tool']);
        $this->assertFalse($miss->isError(), 'a wrong name is information, not a failure — the empty-server-filter polarity');
        $this->assertStringContainsString('no_such_tool', $miss->content());
    }

    public function testTheServerFilterSurvivesTheOverCapOverview(): void
    {
        $srvA = $this->dispatchServer();
        $srvB = $this->dispatchServer();
        $namesA = [];
        for ($i = 0; $i < 24; ++$i) {
            $namesA[] = 'a_tool_' . $i;
        }
        $shape = $this->lazyShape([...$this->fatSet($srvA, ...$namesA), ...$this->fatSet($srvB, 'b_tool')]);

        $hit = $shape->listTool()->execute(['server' => $srvA]);
        $this->assertStringContainsString('ride above the mcp_list output cap', $hit->content(), 'the filtered slice itself rides over the cap');
        $this->assertStringContainsString('Server ' . $srvA . ':', $hit->content());
        $this->assertStringNotContainsString('Server ' . $srvB . ':', $hit->content(), 'the filtered overview keeps the filter');
        $this->assertStringNotContainsString('b_tool', $hit->content());
        $this->assertStringContainsString('- a_tool_23', $hit->content());
    }

    // ---- mcp_call refusals (frozen roster discipline) --------------------------

    public function testTheCallRefusesToLeaveTheFrozenRoster(): void
    {
        $srv = $this->dispatchServer();
        $bridged = $this->descriptor($srv, 'kept_eager');
        $shape = McpToolShape::derive(
            new McpClient($this->deadPath),
            [...$this->fatSet($srv, 'deferred_one'), $bridged],
            [],
            10,
        );
        $this->assertTrue($shape->lazy);
        // Both spellings under ONE server name: the fat ones dispatched, the
        // thin one eager only if it is under the per-SERVER floor — it is not
        // (the floor prices the server total), so this fixture dispatches all
        // three; 'kept_eager' is still NOT on the dispatched roster... it is.
        // The refusal that must hold is a name never advertised at all:
        $result = $shape->callTool()->execute(['server' => $srv, 'tool' => 'never_advertised']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('never_advertised', $result->content());
        $this->assertStringContainsString('mcp_list', $result->content(), 'the refusal teaches the model the way to discover the real names');
    }

    public function testTheCallValidatesItsArgumentEnvelope(): void
    {
        $shape = $this->lazyShape($this->fatSet($this->dispatchServer(), 'a'));
        $call = $shape->callTool();

        foreach ([
            'nothing' => [],
            'no tool' => ['server' => 's'],
            'no server' => ['tool' => 't'],
            'array server' => ['server' => ['s'], 'tool' => 't'],
            'non-array arguments' => ['server' => 's', 'tool' => 't', 'arguments' => 'x'],
        ] as $label => $args) {
            $result = $call->execute($args + ['id' => $label]);
            $this->assertTrue($result->isError(), $label . ' must be refused');
            $this->assertSame($label, $result->toolCallId());
        }
    }

    public function testADispatchedCallTravelsThroughTheClientsRouterAndCarriesItsFailureEnvelope(): void
    {
        $srv = $this->dispatchServer();
        $shape = $this->lazyShape($this->fatSet($srv, 'round_trip'));
        $expectedWire = McpToolBridge::wireNameFor($srv, 'round_trip');

        $result = $shape->callTool()->execute([
            'id' => 'rt',
            'server' => $srv,
            'tool' => 'round_trip',
            'arguments' => ['note' => 'hi'],
        ]);

        // The client was built over a config path that cannot exist: no
        // server started, so callTool must FAIL — the pin is that the failure
        // arrives in the bridge's own envelope (an "Error: MCP tool <wire>
        // failed" result, not an escaped exception), naming the WIRE spelling,
        // which is only true if the call went through the same client path a
        // bridge uses. The router allow/deny and PreToolUse chain live below
        // that call; McpLazyToolLoadingTest proves the success leg on a real
        // stdio peer.
        $this->assertTrue($result->isError());
        $this->assertStringContainsString($expectedWire, $result->content());
        $this->assertStringContainsString('failed', $result->content());
        $this->assertGreaterThanOrEqual(0, $result->durationMs());
    }

    public function testThePairSchemasAreDescribedNotJustNamed(): void
    {
        $shape = $this->lazyShape($this->fatSet($this->dispatchServer(), 'a'));

        $this->assertStringContainsString('mcp_call', $shape->listTool()->description());
        $this->assertStringContainsString('schema', strtolower($shape->listTool()->description()));
        $this->assertStringContainsString('mcp_list', $shape->callTool()->description());
    }
}
