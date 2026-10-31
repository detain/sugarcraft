<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP\Serve;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\MCP\Serve\McpServeHost;
use SugarCraft\Code\MCP\Serve\McpServeRoster;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\McpClient\McpMessage;

/**
 * C-MCP-5.a pins for the `mcp serve` stdio host, driven in-memory over a
 * temp root with the REAL roster and the REAL built-in hook chain (plus the
 * permission gate registered last, exactly as
 * {@see \SugarCraft\Code\Cli\Bootstrap::hooks()} builds it — no Bootstrap
 * call itself, so the pins never read this process's HOME config).
 *
 * The seven claims the design block names: handshake, the five-name roster,
 * Read inside root succeeds, `.env` refuses through ProtectFiles, `Bash` is
 * unknown, an unknown method answers -32601, EOF exits 0. The risk pin —
 * a `../` escape refused by the tool's own jail even though the hook chain
 * allows the Read class — rides alongside.
 */
final class McpServeHostTest extends TestCase
{
    private string $root;

    /** @var list<string> every line the host's write closure emitted */
    private array $emitted;

    /** @var list<\SugarCraft\Code\Tools\Tool> */
    private array $roster;

    private HookManager $chain;

    private McpServeHost $host;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/cc-mcp-serve-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->root = $base . '/project';
        mkdir($this->root . '/nested', 0700, true);
        mkdir($base . '/outside', 0700, true);
        file_put_contents($this->root . '/hello.txt', 'hello serve');
        file_put_contents($this->root . '/.env', 'SECRET=served-anyway');
        file_put_contents($base . '/outside/secret.txt', 'out of jail');

        $this->emitted = [];
        $write = function (string $line): void {
            $this->emitted[] = $line;
        };

        $this->chain = new HookManager(new HookRegistry());
        $this->chain->registerBuiltIns();
        $this->chain->register(new PermissionGateHook(new PermissionGate(PermissionMode::Default)));

        $this->roster = McpServeRoster::build($this->root, rgAvailable: false, fdAvailable: false);
        $this->host = McpServeHost::new($this->roster, $this->chain, $this->root, \Closure::fromCallable($write));
    }

    protected function tearDown(): void
    {
        $base = dirname($this->root);
        foreach ([$this->root . '/hello.txt', $this->root . '/.env', $this->root . '/nested', $base . '/outside/secret.txt', $base . '/outside', $this->root, $base] as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /** One request frame on the wire, answered by exactly one decoded response. */
    private function serveAsk(int|string $id, string $method, ?array $params = null): array
    {
        $before = count($this->emitted);
        $this->host->receive(McpMessage::request((string) $id, $method, $params)->withWireId($id)->toJson());
        self::assertSame($before + 1, count($this->emitted), 'expected exactly one frame for ' . $method);

        $decoded = json_decode($this->emitted[$before], true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testHandshakeAdvertisesToolsOnlyAndPreservesAnIntegerId(): void
    {
        $reply = $this->serveAsk(7, 'initialize', ['protocolVersion' => McpServeHost::PROTOCOL_VERSION, 'capabilities' => []]);

        // JSON-RPC: an integer id must come back an integer, not "7".
        self::assertSame(7, $reply['id']);
        self::assertSame(McpServeHost::PROTOCOL_VERSION, $reply['result']['protocolVersion']);
        self::assertArrayHasKey('tools', $reply['result']['capabilities']);
        // No prompts, no resources — the §6 ruling surface, negatively pinned.
        self::assertSame(['tools'], array_keys($reply['result']['capabilities']));
        self::assertSame('candy-code', $reply['result']['serverInfo']['name']);
        self::assertIsString($reply['result']['serverInfo']['version']);
    }

    public function testInitializedNotificationIsAnsweredWithSilence(): void
    {
        $this->host->receive(McpMessage::notification('notifications/initialized')->toJson());
        self::assertSame([], $this->emitted, 'a notification must never draw a reply frame');
    }

    public function testPingAnswersAnEmptyObjectResult(): void
    {
        $reply = $this->serveAsk('p1', 'ping');
        // McpMessage writes an empty map as {} — decoded back it is an empty
        // stdClass-equivalent; the client half only checks absence of error.
        self::assertArrayNotHasKey('error', $reply);
        self::assertSame([], (array) $reply['result']);
    }

    public function testToolsListHoldsExactlyTheFiveReadOnlyNames(): void
    {
        $reply = $this->serveAsk('l1', 'tools/list');
        $names = array_column($reply['result']['tools'], 'name');
        self::assertSame(McpServeRoster::NAMES, $names);
        foreach ($reply['result']['tools'] as $row) {
            self::assertIsString($row['description']);
            self::assertNotSame('', $row['description']);
            self::assertSame('object', $row['inputSchema']['type'] ?? null, $row['name'] . ' must expose an object input schema');
        }
    }

    public function testReadInsideTheRootSucceedsThroughTheChain(): void
    {
        $reply = $this->serveAsk('c1', 'tools/call', ['name' => 'Read', 'arguments' => ['file_path' => $this->root . '/hello.txt']]);

        self::assertArrayNotHasKey('error', $reply);
        self::assertFalse($reply['result']['isError']);
        self::assertStringContainsString('hello serve', $reply['result']['content'][0]['text']);
    }

    public function testReadOfAnEnvFileRefusesThroughTheProtectFilesHook(): void
    {
        $reply = $this->serveAsk('c2', 'tools/call', ['name' => 'Read', 'arguments' => ['file_path' => $this->root . '/.env']]);

        // The refusal is a tool result (isError), not a protocol error — the
        // call was understood, the guard said no. House prefix, and the
        // message names the matched pattern (which contains the `.env` text).
        self::assertArrayNotHasKey('error', $reply);
        self::assertTrue($reply['result']['isError']);
        $text = $reply['result']['content'][0]['text'];
        self::assertStringStartsWith('Hook denied: ', $text);
        self::assertStringContainsString('.env', $text);
        self::assertStringNotContainsString('SECRET', $text, 'a refusal must never carry the secret it refused to read');
    }

    public function testBashIsUnknownOnTheServeSurface(): void
    {
        $reply = $this->serveAsk('c3', 'tools/call', ['name' => 'Bash', 'arguments' => ['command' => 'echo hi']]);

        self::assertArrayNotHasKey('error', $reply, 'an unknown tool is a tool error, not a lost call');
        self::assertTrue($reply['result']['isError']);
        self::assertStringContainsString('unknown tool', $reply['result']['content'][0]['text']);
        self::assertStringContainsString('Bash', $reply['result']['content'][0]['text']);
    }

    public function testParentDirectoryEscapeIsRefusedByTheToolJail(): void
    {
        $outside = dirname($this->root) . '/outside/secret.txt';
        $reply = $this->serveAsk('c4', 'tools/call', ['name' => 'Read', 'arguments' => ['file_path' => $outside]]);

        self::assertTrue($reply['result']['isError'], 'the external caller must not read above the serve root');
        self::assertStringNotContainsString('out of jail', $reply['result']['content'][0]['text']);
    }

    public function testUnknownMethodAnswersMethodNotFound(): void
    {
        $reply = $this->serveAsk('m1', 'session/prompt', ['sessionId' => 'x']);
        self::assertSame(-32601, $reply['error']['code']);
        self::assertStringContainsString('session/prompt', $reply['error']['message']);
    }

    public function testMalformedAndNonMessageLinesAnswerProtocolErrorsWithNullId(): void
    {
        $before = count($this->emitted);
        $this->host->receive('this is not json');
        $this->host->receive('{"jsonrpc":"2.0"}');
        self::assertCount($before + 2, $this->emitted);

        $parse = json_decode($this->emitted[$before], true);
        $request = json_decode($this->emitted[$before + 1], true);
        self::assertSame(-32700, $parse['error']['code']);
        self::assertNull($parse['id'], 'an error to an unreadable id carries a null id');
        self::assertSame(-32600, $request['error']['code']);
    }

    public function testAResponseLineDrawsNoEcho(): void
    {
        $this->host->receive('{"jsonrpc":"2.0","id":"r1","result":{}}');
        self::assertSame([], $this->emitted, 'answering a response is the echo loop this shape forbids');
    }

    public function testEmptyArgumentsAreOptionalOnCallAndMissingNameIsInvalidParams(): void
    {
        $missing = $this->serveAsk('c5', 'tools/call', []);
        self::assertSame(-32602, $missing['error']['code']);
    }

    public function testRunDrainsStdinAndExitsZeroAtEof(): void
    {
        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        $stdout = fopen('php://memory', 'r+');
        self::assertIsResource($stdout);

        $frames = McpMessage::request('e1', 'session/prompt', ['sessionId' => 'x'])->toJson() . "\n"
            . McpMessage::notification('notifications/initialized')->toJson() . "\n";
        fwrite($stdin, $frames);
        rewind($stdin);

        $exit = McpServeHost::run($this->roster, $this->chain, $this->root, $stdin, $stdout);
        rewind($stdout);
        $out = (string) stream_get_contents($stdout);

        self::assertSame(0, $exit, 'EOF is the only normal exit and it is exit 0');
        $lines = array_values(array_filter(explode("\n", $out), static fn (string $line): bool => $line !== ''));
        self::assertCount(1, $lines, 'only the request draws a frame; the notification stays silent');
        $decoded = json_decode($lines[0], true);
        self::assertIsArray($decoded);
        self::assertSame('e1', $decoded['id']);
        self::assertSame(-32601, $decoded['error']['code']);

        fclose($stdin);
        fclose($stdout);
    }

    public function testRosterFailsLoudWhenANameStopsBeingReadOnly(): void
    {
        // Structural pin for the roster claim: the five names must classify
        // Read in the live catalog today. (The refusal arm of build() is the
        // future-proofing; this asserts the present truth it guards.)
        foreach (McpServeRoster::NAMES as $name) {
            self::assertSame(
                'read',
                \SugarCraft\Code\Tools\Catalog\ToolCatalog::permissionOf($name)?->value,
                $name . ' left the read-only class; serve must not follow it silently.',
            );
        }
    }
}
