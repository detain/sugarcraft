<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Tools\McpToolBridge;
use SugarCraft\Code\Util\TokenEstimate;

/**
 * MCP-7 unit tier: the pure math of the frozen split. Every server name here
 * is process-unique — the read-only hint table McpToolShape seeds is a static
 * AND-merge — and nothing in this file starts a client: derive() takes the
 * descriptor list as given, which is exactly what makes the split testable
 * without a stdio peer. (The wire-level pair, byte-identity against a real
 * handshake, and the frozen-roster cache live in McpLazyToolLoadingTest.)
 */
final class McpToolShapeTest extends TestCase
{
    private static int $serial = 0;

    private function shapeServer(): string
    {
        return 'shape-' . (++self::$serial) . '-' . getmypid();
    }

    private function descriptor(string $server, string $name, int $padding = 0, array $annotations = []): McpTool
    {
        return McpTool::fromArray([
            'name' => $name,
            'description' => $padding > 0 ? str_repeat('wide', $padding) : 'Answer with pong.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['note' => ['type' => 'string']],
                'required' => [],
            ],
            'annotations' => $annotations,
        ], $server);
    }

    // ---- the split ----------------------------------------------------------

    public function testAServerAtOrOverTheFloorIsDispatchedAndOneUnderStaysBridged(): void
    {
        $big = $this->shapeServer();
        $small = $this->shapeServer();
        $fat = $this->descriptor($big, 'heavy', 2000);
        $light = $this->descriptor($small, 'ping');

        $price = TokenEstimate::ofToolSchema(
            McpToolBridge::wireNameFor($big, $fat->name),
            $fat->description,
            $fat->inputSchema,
        );
        $this->assertGreaterThan($price >> 1, $price, 'the instrument prices the fat schema at a real number');

        $shape = McpToolShape::derive(
            new McpClient('/nonexistent/mcp-7-shape/.mcp.json'),
            [$light, $fat],
            [],
            (int) (($price + TokenEstimate::ofToolSchema(McpToolBridge::wireNameFor($small, 'ping'), $light->description, $light->inputSchema)) >> 1),
        );

        $this->assertTrue($shape->lazy);
        $this->assertSame([$small], array_values(array_unique(array_map(
            static fn (McpTool $d): string => $d->serverName,
            $shape->eagerDescriptors,
        ))));
        $this->assertSame([$big], array_values(array_unique(array_map(
            static fn (McpTool $d): string => $d->serverName,
            $shape->dispatchedDescriptors,
        ))));
        $this->assertNotNull($shape->findDispatched($big, 'heavy'));
        $this->assertNull($shape->findDispatched($small, 'ping'), 'the bridged tool is NOT also dispatchable — one spelling is enough');
        $this->assertNull($shape->findDispatched($big, 'made-up'));
    }

    public function testTheFloorZeroNeverDefersAnything(): void
    {
        $big = $this->shapeServer();
        $shape = McpToolShape::derive(
            new McpClient('/nonexistent/mcp-7-shape/.mcp.json'),
            [$this->descriptor($big, 'heavy', 5000)],
            [],
            0,
        );

        $this->assertFalse($shape->lazy);
        $this->assertSame([], $shape->dispatchedDescriptors);
        $this->assertCount(1, $shape->eagerDescriptors);
    }

    public function testTheDecisionIsPerServerNotPerConfigTotal(): void
    {
        // Two servers of 60-ish tokens each: the SUM is over 100, neither
        // server alone is. The dispatcher defuses schema bombs; it must not
        // tax a small server for a noisy neighbour.
        $a = $this->shapeServer();
        $b = $this->shapeServer();
        $shape = McpToolShape::derive(
            new McpClient('/nonexistent/mcp-7-shape/.mcp.json'),
            [$this->descriptor($a, 'one'), $this->descriptor($b, 'two')],
            [],
            100,
        );

        $this->assertFalse($shape->lazy);
        $this->assertCount(2, $shape->eagerDescriptors);
        $this->assertSame([], $shape->dispatchedDescriptors);
    }

    /** @return iterable<string, array{mixed}> */
    public static function eagerSpellings(): iterable
    {
        yield 'literal true' => [true, true];
        yield 'string one' => ['1', true];
        yield 'string true' => ['true', true];
        yield 'string YES' => ['YES', true];
        yield 'string on' => ['on', true];
        yield 'literal false' => [false, false];
        yield 'string zero' => ['0', false];
        yield 'string no' => ['no', false];
        yield 'the integer one is not a declaration' => [1, false];
        yield 'garbage' => ['maybe', false];
        yield 'null' => [null, false];
    }

    #[DataProvider('eagerSpellings')]
    public function testTheEagerFlagUsesTheHouseTruthyVocabulary(mixed $spelling, bool $expected): void
    {
        $big = $this->shapeServer();
        $shape = McpToolShape::derive(
            new McpClient('/nonexistent/mcp-7-shape/.mcp.json'),
            [$this->descriptor($big, 'heavy', 5000)],
            [$big => ['eager' => $spelling]],
            100,
        );

        $this->assertSame($expected, $shape->lazy === false);
        $this->assertSame($expected ? 1 : 0, count($shape->eagerDescriptors));
    }

    // ---- the eager half is byte-identical to the un-lazy world --------------

    public function testTheEagerBridgesAreIndistinguishableFromDirectlyBuiltOnes(): void
    {
        $srv = $this->shapeServer();
        $descriptors = [$this->descriptor($srv, 'ping'), $this->descriptor($srv, 'pong')];
        $client = new McpClient('/nonexistent/mcp-7-shape/.mcp.json');

        $bridges = McpToolShape::derive($client, $descriptors, [], 0)->bridges();

        $direct = array_map(static fn (McpTool $d): McpToolBridge => new McpToolBridge($client, $d), $descriptors);
        $this->assertCount(2, $bridges);
        foreach ($bridges as $i => $bridge) {
            $this->assertSame($direct[$i]->name(), $bridge->name());
            $this->assertSame($direct[$i]->description(), $bridge->description());
            $this->assertSame($direct[$i]->inputSchema(), $bridge->inputSchema());
        }
    }

    // ---- the hint funnel serves both spellings ------------------------------

    public function testDeriveSeedsTheHintTableForDispatchedDescriptorsToo(): void
    {
        $srv = $this->shapeServer();
        $read = $this->descriptor($srv, 'reads', 5000, ['readOnlyHint' => true]);
        $open = $this->descriptor($srv, 'fetches', 5000, ['readOnlyHint' => true, 'openWorldHint' => true]);
        $plain = $this->descriptor($srv, 'drops', 5000);

        $shape = McpToolShape::derive(
            new McpClient('/nonexistent/mcp-7-shape/.mcp.json'),
            [$read, $open, $plain],
            [],
            100,
        );
        $this->assertCount(3, $shape->dispatchedDescriptors, 'the fixture must land on the dispatched side or the hint pin measures nothing');

        $this->assertTrue(McpToolBridge::declaredReadOnly(McpToolBridge::wireNameFor($srv, 'reads')));
        $this->assertFalse(McpToolBridge::declaredReadOnly(McpToolBridge::wireNameFor($srv, 'fetches')), 'openWorld vetoes the hint, same rule the bridge applies');
        $this->assertFalse(McpToolBridge::declaredReadOnly(McpToolBridge::wireNameFor($srv, 'drops')));
    }

    public function testDispatchedWireNameParsesOnlyTheArgumentsForm(): void
    {
        $this->assertNull(McpToolBridge::dispatchedWireName([]));
        $this->assertNull(McpToolBridge::dispatchedWireName(['server' => '', 'tool' => 'x']));
        $this->assertNull(McpToolBridge::dispatchedWireName(['server' => 's', 'tool' => 42]));
        $srv = $this->shapeServer();
        $this->assertSame(
            McpToolBridge::wireNameFor($srv, 'go'),
            McpToolBridge::dispatchedWireName(['server' => $srv, 'tool' => 'go']),
        );
    }

    // ---- C-AGT-3: the per-preset scope view -----------------------------------

    public function testScopedToFiltersTheDispatchedHalfAndRebindsTheClientWithoutTouchingTheLaunchShape(): void
    {
        $kept = $this->shapeServer();
        $dropped = $this->shapeServer();
        $small = $this->shapeServer();
        $launch = new McpClient('/nonexistent/mcp-7-shape/.mcp.json', unrestricted: true);

        $shape = McpToolShape::derive(
            $launch,
            [
                $this->descriptor($kept, 'keeps', 5000),
                $this->descriptor($dropped, 'drops', 5000),
                $this->descriptor($small, 'ping'),
            ],
            [],
            100,
        );
        $this->assertSame(
            [$kept, $dropped],
            array_values(array_unique(array_map(
                static fn (McpTool $d): string => $d->serverName,
                $shape->dispatchedDescriptors,
            ))),
            'the fixture lands BOTH fat servers on the dispatched side or the filter measures nothing',
        );

        $scoped = $shape->scopedTo([$kept]);

        $this->assertSame([$kept], array_map(
            static fn (McpTool $d): string => $d->serverName,
            $scoped->dispatchedDescriptors,
        ));
        $this->assertNotNull($scoped->findDispatched($kept, 'keeps'));
        $this->assertNull($scoped->findDispatched($dropped, 'drops'), 'the outside server is gone from the frozen roster, not hidden at print');

        // The eager half and the lazy flag pass through: the shape is a
        // launch fact, the scope is a view on it.
        $this->assertSame($shape->eagerDescriptors, $scoped->eagerDescriptors);
        $this->assertTrue($scoped->lazy);

        // The launch shape is untouched — scoping never mutates what the
        // main agent's dispatchers carry.
        $this->assertCount(2, $shape->dispatchedDescriptors);

        // The client is a preset-bound clone; the launch client stays
        // preset-free. Reflection reads the private the routing branches on.
        $this->assertNotSame($launch, $scoped->client);
        $preset = new \ReflectionProperty(McpClient::class, 'agentPreset');
        $this->assertNull($preset->getValue($launch), 'forAgentPreset() must clone, never bind in place');
        $this->assertNotNull($preset->getValue($scoped->client));
    }

    public function testScopedToWithTheRouterAllowAllEmptyListKeepsTheWholeDispatchedHalf(): void
    {
        $big = $this->shapeServer();
        $shape = McpToolShape::derive(
            new McpClient('/nonexistent/mcp-7-shape/.mcp.json'),
            [$this->descriptor($big, 'heavy', 5000)],
            [],
            100,
        );

        $scoped = $shape->scopedTo([]);

        $this->assertSame($shape->dispatchedDescriptors, $scoped->dispatchedDescriptors, 'no restriction configured is not restrict to nothing');
        $this->assertNotSame($shape->client, $scoped->client);
    }
}
