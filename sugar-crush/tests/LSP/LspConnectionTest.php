<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspConnection;

/**
 * Tests for LspConnection using a pipe mock that echoes JSON-RPC responses.
 */
final class LspConnectionTest extends TestCase
{
    private string $mockScript;
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock LSP server script that echoes back canned responses.
        $this->workDir = sys_get_temp_dir() . '/lsp-test-' . uniqid();
        mkdir($this->workDir, 0777, true);

        // Build a mock server that reads requests and emits matching responses.
        // It handles: initialize, shutdown, textDocument/definition, textDocument/references,
        // textDocument/hover, textDocument/documentSymbol.
        $this->mockScript = $this->workDir . '/mock_lsp_server.php';
        $serverCode = <<<'PHP'
<?php
declare(strict_types=1);
$buf = '';
$initialized = false;
while (true) {
    $chunk = fgets(STDIN);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $buf .= $chunk;
    $newline = strpos($buf, "\n");
    if ($newline === false) {
        continue;
    }
    $line = trim(substr($buf, 0, $newline));
    $buf = substr($buf, $newline + 1);
    if ($line === '') continue;
    $req = json_decode($line, true);
    if (!is_array($req) || !isset($req['jsonrpc'], $req['id'])) {
        // notification — read another
        if (isset($req['method']) && $req['method'] === 'exit') {
            break;
        }
        continue;
    }
    $id = (string) $req['id'];
    $method = $req['method'] ?? '';
    $result = null;
    if ($method === 'initialize') {
        $result = [
            'capabilities' => [
                'textDocument' => [
                    'hover' => ['dynamicRegistration' => true],
                    'definition' => ['dynamicRegistration' => true],
                    'references' => ['dynamicRegistration' => true],
                    'documentSymbol' => ['dynamicRegistration' => true],
                ],
            ],
            'serverInfo' => ['name' => 'mock-lsp', 'version' => '1.0.0'],
        ];
    } elseif ($method === 'shutdown') {
        $result = null;
    } elseif ($method === 'textDocument/definition') {
        $result = [
            ['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1, 'character' => 5]]],
        ];
    } elseif ($method === 'textDocument/references') {
        $result = [
            ['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 2, 'character' => 2], 'end' => ['line' => 2, 'character' => 6]]],
            ['uri' => 'file:///other.php', 'range' => ['start' => ['line' => 3, 'character' => 1], 'end' => ['line' => 3, 'character' => 5]]],
        ];
    } elseif ($method === 'textDocument/hover') {
        $result = ['contents' => [['language' => 'php', 'value' => 'int'], 'some documentation']];
    } elseif ($method === 'textDocument/documentSymbol') {
        $result = [
            ['name' => 'foo', 'kind' => 6, 'location' => ['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 3]]]],
        ];
    }
    $resp = ['jsonrpc' => '2.0', 'id' => $id];
    if ($result !== null) {
        $resp['result'] = $result;
    } elseif (isset($req['error'])) {
        $resp['error'] = $req['error'];
    }
    fwrite(STDOUT, json_encode($resp) . "\n");
    fflush(STDOUT);
    if ($method === 'exit' || $method === 'shutdown') {
        break;
    }
}
PHP;
        file_put_contents($this->mockScript, $serverCode);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->mockScript) && file_exists($this->mockScript)) {
            unlink($this->mockScript);
        }
        if (isset($this->workDir) && is_dir($this->workDir)) {
            @rmdir($this->workDir);
        }
    }

    // -------------------------------------------------------------------------
    // connect() / disconnect() / isConnected()
    // -------------------------------------------------------------------------

    public function testConnectReturnsServerCapabilities(): void
    {
        $conn = new LspConnection('php', [$this->mockScript], $this->workDir);
        $caps = $conn->connect();

        $this->assertIsArray($caps);
        $this->assertArrayHasKey('textDocument', $caps);
        $this->assertTrue($conn->isConnected());
    }

    public function testDisconnectCleansUpProcess(): void
    {
        $conn = new LspConnection('php', [$this->mockScript], $this->workDir);
        $conn->connect();
        $this->assertTrue($conn->isConnected());

        $conn->disconnect();
        $this->assertFalse($conn->isConnected());
    }

    public function testDisconnectWithoutConnectDoesNotThrow(): void
    {
        $conn = new LspConnection('php', [$this->mockScript], $this->workDir);
        $conn->disconnect();
        $this->assertFalse($conn->isConnected());
    }

    public function testIsConnectedReturnsFalseBeforeConnect(): void
    {
        $conn = new LspConnection('php', [$this->mockScript], $this->workDir);
        $this->assertFalse($conn->isConnected());
    }

    public function testConnectThrowsOnInvalidServer(): void
    {
        $conn = new LspConnection('/nonexistent/binary', [], $this->workDir);
        $this->expectException(\RuntimeException::class);
        $conn->connect();
    }

    // -------------------------------------------------------------------------
    // definitions()
    // -------------------------------------------------------------------------

    public function testDefinitionsReturnsLocations(): void
    {
        $conn = new LspConnection('php', [$this->mockScript], $this->workDir);
        $conn->connect();

        $defs = $conn->definitions('file:///test.php', 0, 0);
        $this->assertCount(1, $defs);
        $this->assertSame('file:///test.php', $defs[0]['uri']);

        $conn->disconnect();
    }

    public function testDefinitionsReturnsEmptyOnError(): void
    {
        $conn = new LspConnection('php', [$this->mockScript], $this->workDir);
        $conn->connect();

        // Pass invalid file handle so read returns null — triggers empty return.
        $defs = $conn->definitions('', -1, -1);
        $this->assertIsArray($defs);

        $conn->disconnect();
    }

    // -------------------------------------------------------------------------
    // references()
    // -------------------------------------------------------------------------

    public function testReferencesReturnsLocations(): void
    {
        $conn = new LspConnection('php', [$this->mockScript], $this->workDir);
        $conn->connect();

        $refs = $conn->references('file:///test.php', 0, 0);
        $this->assertCount(2, $refs);
        $this->assertSame('file:///test.php', $refs[0]['uri']);
        $this->assertSame('file:///other.php', $refs[1]['uri']);

        $conn->disconnect();
    }

    // -------------------------------------------------------------------------
    // hover()
    // -------------------------------------------------------------------------

    public function testHoverReturnsContent(): void
    {
        $conn = new LspConnection('php', [$this->mockScript], $this->workDir);
        $conn->connect();

        $hover = $conn->hover('file:///test.php', 0, 0);
        $this->assertNotNull($hover);
        $this->assertArrayHasKey('contents', $hover);

        $conn->disconnect();
    }

    public function testHoverReturnsNullOnMissingContent(): void
    {
        // Create a server that returns no contents.
        $emptyScript = $this->workDir . '/empty_hover.php';
        file_put_contents($emptyScript, <<<'PHP'
<?php
declare(strict_types=1);
while ($line = fgets(STDIN)) {
    $req = json_decode(trim($line), true);
    if (!is_array($req) || !isset($req['jsonrpc'], $req['id'])) continue;
    $method = $req['method'] ?? '';
    $result = $method === 'initialize'
        ? ['capabilities' => []]
        : ($method === 'textDocument/hover' ? [] : null);
    $resp = ['jsonrpc' => '2.0', 'id' => (string) $req['id']];
    if ($result !== null) $resp['result'] = $result;
    fwrite(STDOUT, json_encode($resp) . "\n");
    fflush(STDOUT);
    if ($method === 'exit') break;
}
PHP
        );

        $conn = new LspConnection('php', [$emptyScript], $this->workDir);
        $conn->connect();
        $hover = $conn->hover('file:///test.php', 0, 0);
        $this->assertNull($hover);
        $conn->disconnect();
    }

    // -------------------------------------------------------------------------
    // symbols()
    // -------------------------------------------------------------------------

    public function testSymbolsReturnsDocumentSymbols(): void
    {
        $conn = new LspConnection('php', [$this->mockScript], $this->workDir);
        $conn->connect();

        $syms = $conn->symbols('file:///test.php');
        $this->assertCount(1, $syms);
        $this->assertSame('foo', $syms[0]['name']);

        $conn->disconnect();
    }

    // -------------------------------------------------------------------------
    // diagnostics()
    // -------------------------------------------------------------------------

    public function testDiagnosticsReturnsEmptyArray(): void
    {
        $conn = new LspConnection('php', [$this->mockScript], $this->workDir);
        $conn->connect();

        $diags = $conn->diagnostics('file:///test.php');
        $this->assertIsArray($diags);
        $this->assertEmpty($diags);

        $conn->disconnect();
    }

    // -------------------------------------------------------------------------
    // Multiple in-flight requests (sequential — JSON-RPC is synchronous)
    // -------------------------------------------------------------------------

    public function testMultipleRequestsInSequence(): void
    {
        $conn = new LspConnection('php', [$this->mockScript], $this->workDir);
        $conn->connect();

        $defs = $conn->definitions('file:///test.php', 0, 0);
        $refs = $conn->references('file:///test.php', 0, 0);
        $hover = $conn->hover('file:///test.php', 0, 0);
        $syms = $conn->symbols('file:///test.php');

        $this->assertCount(1, $defs);
        $this->assertCount(2, $refs);
        $this->assertNotNull($hover);
        $this->assertCount(1, $syms);

        $conn->disconnect();
    }
}
