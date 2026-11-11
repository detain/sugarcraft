<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Code\MCP\ClaudeCodeMcpServer;
use SugarCraft\Code\MCP\HttpMcpServer;

/**
 * Audit MCP-9 on the two transports candy-code owns (the stdio one is the
 * sugar-mcp-client library's, pinned by its StdioMcpServerNestedArgumentsWireTest).
 *
 * A model's `{"filter":{}}` reaches the transport as `['filter' => []]`
 * (every provider parser decodes assoc). Before the fix only the top-level
 * `arguments` was coerced, so the nested map went out as `"filter":[]` and
 * an official-SDK server refused the call. Both rows assert the BYTES that
 * left the process: Guzzle's encoded request body for HTTP, and what a
 * non-assoc-decoding child received for claude-mcp.
 */
final class McpNestedArgumentsWireTest extends TestCase
{
    /** The schema both fake servers advertise for their one tool. */
    private const INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'filter' => ['type' => 'object', 'properties' => []],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'options' => ['anyOf' => [['$ref' => '#/$defs/Options'], ['type' => 'null']]],
        ],
        '$defs' => ['Options' => [
            'type' => 'object',
            'properties' => ['headers' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
        ]],
    ];

    private const ARGUMENTS = ['filter' => [], 'tags' => [], 'options' => ['headers' => []]];

    private const EXPECTED_WIRE = '{"filter":{},"tags":[],"options":{"headers":{}}}';

    /** Two shapes only an index that tracks the live tool list can tell apart. */
    private const INDEX_SCHEMA_ONE = ['type' => 'object', 'properties' => ['filter' => ['type' => 'object']]];

    private const INDEX_SCHEMA_TWO = ['type' => 'object', 'properties' => ['filter' => ['type' => 'array']]];

    private const META_ONLY_SCHEMA = [
        'type' => 'object',
        'properties' => ['info' => ['type' => 'object', 'properties' => []]],
    ];

    private string $workDir = '';

    protected function tearDown(): void
    {
        if ($this->workDir !== '') {
            foreach (glob($this->workDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->workDir);
        }

        parent::tearDown();
    }

    public function testHttpToolsCallBodyCarriesNestedEmptyObjectsAsObjects(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode(['result' => ['capabilities' => new \stdClass()]])),
            new Response(202),
            new Response(200, [], (string) json_encode(['result' => ['tools' => [
                ['name' => 'search', 'description' => 'nested', 'inputSchema' => self::INPUT_SCHEMA],
            ]]])),
            new Response(200, [], (string) json_encode(['result' => ['content' => [['type' => 'text', 'text' => 'ok']]]])),
        ]));
        $stack->push(Middleware::history($history));

        $server = new HttpMcpServer(
            name: 'nested-http',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: new Client(['handler' => $stack]),
        );
        $server->start();

        $result = $server->callTool('search', self::ARGUMENTS);

        self::assertSame(['content' => [['type' => 'text', 'text' => 'ok']]], $result);

        $call = null;
        foreach ($history as $transaction) {
            $request = $transaction['request'];
            self::assertInstanceOf(RequestInterface::class, $request);
            $body = (string) $request->getBody();
            if ((json_decode($body)->method ?? null) === 'tools/call') {
                $call = $body;
            }
        }

        self::assertNotNull($call, 'no tools/call request reached the HTTP client');
        self::assertStringContainsString('"arguments":' . self::EXPECTED_WIRE, $call);
    }

    public function testClaudeMcpToolsCallCarriesNestedEmptyObjectsAsObjects(): void
    {
        $this->workDir = sys_get_temp_dir() . '/mcp9_' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0o700, true);
        $script = $this->workDir . '/responder.php';
        file_put_contents($script, sprintf(<<<'PHP'
            <?php
            $schema = json_decode(%s);
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line); // NOT assoc: {} and [] stay distinct
                if (!$msg instanceof stdClass || !property_exists($msg, 'id')) { continue; }
                $out = ['jsonrpc' => '2.0', 'id' => $msg->id];
                if ($msg->method === 'initialize') {
                    $out['result'] = ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass(),
                        'serverInfo' => ['name' => 'fake', 'version' => '0']];
                } elseif ($msg->method === 'tools/list') {
                    $out['result'] = ['tools' => [['name' => 'search', 'description' => 'nested', 'inputSchema' => $schema]]];
                } elseif ($msg->method === 'tools/call') {
                    $out['result'] = ['content' => [['type' => 'text', 'text' => json_encode($msg->params->arguments)]]];
                }
                echo json_encode($out), "\n";
                fflush(STDOUT);
            }
            PHP, var_export((string) json_encode(self::INPUT_SCHEMA), true)) . "\n");

        $server = ClaudeCodeMcpServer::fromGrant('gated', ['type' => ClaudeCodeMcpServer::TYPE], [
            'binary' => PHP_BINARY,
            'args' => [$script],
            'env' => null,
        ]);

        $server->start();
        try {
            $result = $server->callTool('search', self::ARGUMENTS);
        } finally {
            $server->stop();
        }

        self::assertSame(self::EXPECTED_WIRE, $result['content'][0]['text'] ?? null);
    }

    /**
     * n32 (cc-review-1111, LIB-CORE-B N2): the schema lookup rode a linear
     * scan of {@see $tools} per call on all three transports. The cure is a
     * per-server name => schema index rebuilt iff the tool list is
     * REASSIGNED — so these legs pin the two properties the old scan never
     * exercised: invalidation on refresh (new McpTool instances arriving is
     * exactly what a second start() does) and first-row-wins on a duplicated
     * name, the answer the scan's break-on-first-match returned.
     */
    public function testTheSchemaIndexRebuildsWhenTheToolListIsReassigned(): void
    {
        $server = $this->bareHttpServer();
        $tools = new \ReflectionProperty($server, 'tools');
        $source = new \ReflectionProperty($server, 'schemaIndexSource');
        $lookup = new \ReflectionMethod($server, 'inputSchemaOf');

        $tools->setValue($server, [self::schemaTool('search', self::INDEX_SCHEMA_ONE)]);
        self::assertSame(self::INDEX_SCHEMA_ONE, $lookup->invoke($server, 'search'));
        // The index keeps the very array it was built from; while nothing is
        // reassigned the identity check is a pointer compare, not a walk.
        self::assertSame($tools->getValue($server), $source->getValue($server));

        $tools->setValue($server, [self::schemaTool('search', self::INDEX_SCHEMA_TWO)]);
        self::assertSame(self::INDEX_SCHEMA_TWO, $lookup->invoke($server, 'search'));
        self::assertSame([], $lookup->invoke($server, 'never-advertised'));
    }

    public function testTheSchemaIndexKeepsTheFirstRowForADuplicatedName(): void
    {
        $server = $this->bareHttpServer();
        $tools = new \ReflectionProperty($server, 'tools');
        $tools->setValue($server, [
            self::schemaTool('search', self::INDEX_SCHEMA_ONE),
            self::schemaTool('search', self::INDEX_SCHEMA_TWO),
        ]);

        self::assertSame(
            self::INDEX_SCHEMA_ONE,
            (new \ReflectionMethod($server, 'inputSchemaOf'))->invoke($server, 'search'),
            'a duplicated tool name must answer with the first row, exactly as the scan did',
        );
    }

    /**
     * The wire-shaped leg: the SECOND tool named answers from the index built
     * at the first call, and neither lookup can serve the other's schema —
     * a single-slot or insertion-order-blind cache reddens one leg or the
     * other while both transports still hand-crank the same conform path
     * {@see testHttpToolsCallBodyCarriesNestedEmptyObjectsAsObjects()} pins.
     */
    public function testTheWireAnswersEveryToolNameFromOneIndexBuild(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode(['result' => ['capabilities' => new \stdClass()]])),
            new Response(202),
            new Response(200, [], (string) json_encode(['result' => ['tools' => [
                ['name' => 'search', 'description' => 'nested', 'inputSchema' => self::INPUT_SCHEMA],
                ['name' => 'meta', 'description' => 'meta', 'inputSchema' => self::META_ONLY_SCHEMA],
            ]]])),
            new Response(200, [], (string) json_encode(['result' => ['content' => []]])),
            new Response(200, [], (string) json_encode(['result' => ['content' => []]])),
        ]));
        $stack->push(Middleware::history($history));

        $server = new HttpMcpServer(
            name: 'index-http',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: new Client(['handler' => $stack]),
        );
        $server->start();

        self::assertSame(['content' => []], $server->callTool('meta', ['info' => []]));
        self::assertSame(['content' => []], $server->callTool('search', self::ARGUMENTS));

        $bodies = [];
        foreach ($history as $transaction) {
            $body = (string) $transaction['request']->getBody();
            $method = json_decode($body)->method ?? null;
            if ($method === 'tools/call') {
                $bodies[(string) json_decode($body)->params->name] = $body;
            }
        }

        self::assertStringContainsString('"arguments":{"info":{}}', $bodies['meta'] ?? '');
        self::assertStringContainsString('"arguments":' . self::EXPECTED_WIRE, $bodies['search'] ?? '');
    }

    /**
     * Structural arm of the same finding: all three transports must carry the
     * ONE lookup shape. A future fourth copy, or one sibling quietly keeping
     * the scan, reddens here — the triplication was the finding's symptom.
     */
    public function testEveryServerSchemaLookupIsTheMemoisedIndex(): void
    {
        $files = [
            'HttpMcpServer.php',
            'SseMcpServer.php',
            'ClaudeCodeMcpServer.php',
        ];
        $root = dirname(__DIR__, 2) . '/src/MCP/';

        foreach ($files as $file) {
            $body = self::methodBody(file_get_contents($root . $file) ?: '', 'inputSchemaOf');
            self::assertStringContainsString('$this->schemaIndexSource !== $this->tools', $body, $file);
            self::assertStringNotContainsString('return $tool->inputSchema;', $body, $file);
        }
    }

    private function bareHttpServer(): HttpMcpServer
    {
        return new HttpMcpServer(
            name: 'bare-index-http',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
        );
    }

    /** @param array<array-key,mixed> $schema */
    private static function schemaTool(string $name, array $schema): \SugarCraft\Code\MCP\McpTool
    {
        return new \SugarCraft\Code\MCP\McpTool($name, 'row', $schema, 'srv');
    }

    /** Slice one method's body out of a class file by brace walk from its declaration. */
    private static function methodBody(string $source, string $method): string
    {
        $at = strpos($source, 'function ' . $method . '(');
        self::assertNotFalse($at, 'declaration of ' . $method . ' vanished from the source');
        $open = strpos($source, '{', $at);
        self::assertNotFalse($open);
        $depth = 0;
        $length = \strlen($source);
        for ($i = $open; $i < $length; ++$i) {
            if ($source[$i] === '{') {
                ++$depth;
            } elseif ($source[$i] === '}') {
                --$depth;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        self::fail('unbalanced braces scanning ' . $method);
    }
}
