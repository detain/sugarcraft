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
        // Uses LSP Content-Length header framing.
        // It handles: initialize, shutdown, textDocument/definition, textDocument/references,
        // textDocument/hover, textDocument/documentSymbol, textDocument/diagnostic, textDocument/codeAction.
        $this->mockScript = $this->workDir . '/mock_lsp_server.php';
        $serverCode = <<<'PHP'
<?php
declare(strict_types=1);
$buf = '';
$initialized = false;
while (true) {
    // Read until we have the header-body separator \r\n\r\n
    // Use explode to split at the FIRST \r\n\r\n, which is always the header-body separator
    // (Content-Length values are plain integers and cannot contain \r\n\r\n)
    $parts = explode("\r\n\r\n", $buf, 2);
    while (count($parts) < 2) {
        $chunk = fread(STDIN, 8192);
        if ($chunk === false || $chunk === '') {
            break 2;
        }
        $buf .= $chunk;
        $parts = explode("\r\n\r\n", $buf, 2);
    }
    $headerBlock = $parts[0];
    $buf = $parts[1];
    $contentLength = null;
    foreach (explode("\r\n", $headerBlock) as $header) {
        if (str_starts_with($header, 'Content-Length:')) {
            $contentLength = (int) trim(substr($header, 15));
            break;
        }
    }
    if ($contentLength === null) {
        continue;
    }
    // Read body using fread (not fgets) to get exact byte count — JSON has no newline
    while (strlen($buf) < $contentLength) {
        $needed = $contentLength - strlen($buf);
        $chunk = fread(STDIN, $needed);
        if ($chunk === false || $chunk === '') {
            break 2;
        }
        $buf .= $chunk;
    }
    $body = substr($buf, 0, $contentLength);
    $buf = substr($buf, $contentLength);
    $req = json_decode($body, true);
    if (!is_array($req) || !isset($req['jsonrpc'])) {
        continue;
    }
    $id = isset($req['id']) ? (string) $req['id'] : null;
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
                    'diagnostic' => ['dynamicRegistration' => true],
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
    } elseif ($method === 'textDocument/diagnostic') {
        $result = ['items' => []];
    } elseif ($method === 'textDocument/codeAction') {
        $result = [
            ['title' => 'Remove unused variable', 'kind' => 'quickfix', 'edit' => []],
            ['title' => 'Add missing semicolon', 'kind' => 'quickfix', 'edit' => []],
        ];
    }
    // Send response with Content-Length framing
    $resp = ['jsonrpc' => '2.0'];
    if ($id !== null) {
        $resp['id'] = $id;
    }
    if ($result !== null) {
        $resp['result'] = $result;
    } elseif (isset($req['error'])) {
        $resp['error'] = $req['error'];
    }
    $json = json_encode($resp);
    $header = "Content-Length: " . strlen($json) . "\r\n\r\n";
    fwrite(STDOUT, $header . $json);
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
        $conn = new LspConnection('php', [$this->mockScript]);
        $conn->connect('php', [], $this->workDir, 30.0);
        $caps = $conn->initialize();

        $this->assertIsArray($caps);
        $this->assertArrayHasKey('textDocument', $caps);
        $this->assertTrue($conn->isConnected());
    }

    public function testDisconnectCleansUpProcess(): void
    {
        $conn = new LspConnection('php', [$this->mockScript]);
        $conn->connect('php', [], $this->workDir, 30.0);
        $conn->initialize();
        $this->assertTrue($conn->isConnected());

        $conn->disconnect();
        $this->assertFalse($conn->isConnected());
    }

    public function testDisconnectWithoutConnectDoesNotThrow(): void
    {
        $conn = new LspConnection('php', [$this->mockScript]);
        $conn->disconnect();
        $this->assertFalse($conn->isConnected());
    }

    public function testIsConnectedReturnsFalseBeforeConnect(): void
    {
        $conn = new LspConnection('php', [$this->mockScript]);
        $this->assertFalse($conn->isConnected());
    }

    public function testConnectThrowsOnInvalidServer(): void
    {
        $conn = new LspConnection('/nonexistent/binary', []);
        $this->expectException(\RuntimeException::class);
        $conn->connect('/nonexistent/binary', [], $this->workDir, 30.0);
    }

    // -------------------------------------------------------------------------
    // definitions()
    // -------------------------------------------------------------------------

    public function testDefinitionsReturnsLocations(): void
    {
        $conn = new LspConnection('php', [$this->mockScript]);
        $conn->connect('php', [], $this->workDir, 30.0);
        $conn->initialize();

        $defs = $conn->definitions('file:///test.php', 0, 0);
        $this->assertCount(1, $defs);
        $this->assertSame('file:///test.php', $defs[0]['uri']);

        $conn->disconnect();
    }

    public function testDefinitionsReturnsEmptyOnError(): void
    {
        $conn = new LspConnection('php', [$this->mockScript]);
        $conn->connect('php', [], $this->workDir, 30.0);
        $conn->initialize();

        // Pass invalid URI so server responds with empty result.
        $defs = $conn->definitions('', -1, -1);
        $this->assertIsArray($defs);

        $conn->disconnect();
    }

    // -------------------------------------------------------------------------
    // references()
    // -------------------------------------------------------------------------

    public function testReferencesReturnsLocations(): void
    {
        $conn = new LspConnection('php', [$this->mockScript]);
        $conn->connect('php', [], $this->workDir, 30.0);
        $conn->initialize();

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
        $conn = new LspConnection('php', [$this->mockScript]);
        $conn->connect('php', [], $this->workDir, 30.0);
        $conn->initialize();

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
$buf = '';
while (true) {
    $parts = explode("\r\n\r\n", $buf, 2);
    while (count($parts) < 2) {
        $chunk = fread(STDIN, 8192);
        if ($chunk === false || $chunk === '') { break 2; }
        $buf .= $chunk;
        $parts = explode("\r\n\r\n", $buf, 2);
    }
    $headerBlock = $parts[0];
    $buf = $parts[1];
    $contentLength = null;
    foreach (explode("\r\n", $headerBlock) as $header) {
        if (str_starts_with($header, 'Content-Length:')) {
            $contentLength = (int) trim(substr($header, 15));
            break;
        }
    }
    if ($contentLength === null) { continue; }
    while (strlen($buf) < $contentLength) {
        $needed = $contentLength - strlen($buf);
        $chunk = fread(STDIN, $needed);
        if ($chunk === false || $chunk === '') { break 2; }
        $buf .= $chunk;
    }
    $body = substr($buf, 0, $contentLength);
    $buf = substr($buf, $contentLength);
    $req = json_decode($body, true);
    if (!is_array($req) || !isset($req['jsonrpc'])) continue;
    $method = $req['method'] ?? '';
    $result = $method === 'initialize'
        ? ['capabilities' => []]
        : ($method === 'textDocument/hover' ? [] : null);
    $resp = ['jsonrpc' => '2.0', 'id' => (string) $req['id']];
    if ($result !== null) $resp['result'] = $result;
    $json = json_encode($resp);
    fwrite(STDOUT, "Content-Length: " . strlen($json) . "\r\n\r\n" . $json);
    fflush(STDOUT);
    if ($method === 'exit') break;
}
PHP
        );

        $conn = new LspConnection('php', [$emptyScript]);
        $conn->connect('php', [], $this->workDir, 30.0);
        $conn->initialize();
        $hover = $conn->hover('file:///test.php', 0, 0);
        $this->assertNull($hover);
        $conn->disconnect();
    }

    // -------------------------------------------------------------------------
    // symbols()
    // -------------------------------------------------------------------------

    public function testSymbolsReturnsDocumentSymbols(): void
    {
        $conn = new LspConnection('php', [$this->mockScript]);
        $conn->connect('php', [], $this->workDir, 30.0);
        $conn->initialize();

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
        $conn = new LspConnection('php', [$this->mockScript]);
        $conn->connect('php', [], $this->workDir, 30.0);
        $conn->initialize();

        // Mock server returns textDocument/diagnostic result with empty items.
        $diags = $conn->diagnostics('file:///test.php');
        $this->assertIsArray($diags);
        // Mock server returns ['items' => []], so we get an empty result.
        $this->assertEmpty($diags);

        $conn->disconnect();
    }

    // -------------------------------------------------------------------------
    // Multiple in-flight requests (sequential — JSON-RPC is synchronous)
    // -------------------------------------------------------------------------

    public function testMultipleRequestsInSequence(): void
    {
        $conn = new LspConnection('php', [$this->mockScript]);
        $conn->connect('php', [], $this->workDir, 30.0);
        $conn->initialize();

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

    // -------------------------------------------------------------------------
    // codeActions()
    // -------------------------------------------------------------------------

    public function testCodeActionsReturnsCodeActions(): void
    {
        $conn = new LspConnection('php', [$this->mockScript]);
        $conn->connect('php', [], $this->workDir, 30.0);
        $conn->initialize();

        $actions = $conn->codeActions('file:///test.php', 0, 0, ['diagnostics' => []]);
        $this->assertCount(2, $actions);
        $this->assertSame('Remove unused variable', $actions[0]['title']);
        $this->assertSame('quickfix', $actions[0]['kind']);

        $conn->disconnect();
    }

    // -------------------------------------------------------------------------
    // onNotification() callback
    // -------------------------------------------------------------------------

    public function testOnNotificationCallback(): void
    {
        // Create a server that sends an unprompted window/showMessage notification
        // after initialization, using non-blocking read to avoid stalling.
        $notifyScript = $this->workDir . '/notify_lsp_server.php';
        $notifyCode = <<<'PHP'
<?php
declare(strict_types=1);
stream_set_blocking(STDIN, false);
$buf = '';
$notified = false;
while (true) {
    // Read available input without blocking.
    $chunk = fread(STDIN, 8192);
    if ($chunk !== false && $chunk !== '') {
        $buf .= $chunk;
    }
    // Parse any complete messages.
    $parts = explode("\r\n\r\n", $buf, 2);
    while (count($parts) >= 2) {
        $headerBlock = $parts[0];
        $buf = $parts[1];
        $contentLength = null;
        foreach (explode("\r\n", $headerBlock) as $header) {
            if (str_starts_with($header, 'Content-Length:')) {
                $contentLength = (int) trim(substr($header, 15));
                break;
            }
        }
        if ($contentLength === null) {
            $parts = explode("\r\n\r\n", $buf, 2);
            continue;
        }
        while (strlen($buf) < $contentLength) {
            $chunk = fread(STDIN, $contentLength - strlen($buf));
            if ($chunk === false || $chunk === '') { break 2; }
            $buf .= $chunk;
        }
        $body = substr($buf, 0, $contentLength);
        $buf = substr($buf, $contentLength);
        $req = json_decode($body, true);
        if (!is_array($req) || !isset($req['jsonrpc'])) {
            $parts = explode("\r\n\r\n", $buf, 2);
            continue;
        }
        $id = isset($req['id']) ? (string) $req['id'] : null;
        $method = $req['method'] ?? '';
        $result = null;
        if ($method === 'initialize') {
            $result = ['capabilities' => [], 'serverInfo' => ['name' => 'notify-lsp', 'version' => '1.0.0']];
        } elseif ($method === 'shutdown') {
            $result = null;
        }
        $resp = ['jsonrpc' => '2.0'];
        if ($id !== null) { $resp['id'] = $id; }
        if ($result !== null) { $resp['result'] = $result; }
        $json = json_encode($resp);
        fwrite(STDOUT, "Content-Length: " . strlen($json) . "\r\n\r\n" . $json);
        fflush(STDOUT);
        if ($method === 'exit' || $method === 'shutdown') { break; }
        // Send a window/showMessage notification after initialization.
        if (!$notified && $method === 'initialize') {
            $notified = true;
            $notif = ['jsonrpc' => '2.0', 'method' => 'window/showMessage', 'params' => ['type' => 3, 'message' => 'test notification']];
            $notifJson = json_encode($notif);
            fwrite(STDOUT, "Content-Length: " . strlen($notifJson) . "\r\n\r\n" . $notifJson);
            fflush(STDOUT);
        }
        $parts = explode("\r\n\r\n", $buf, 2);
    }
    usleep(10000);
}
PHP;
        file_put_contents($notifyScript, $notifyCode);

        $conn = new LspConnection('php', [$notifyScript]);
        $conn->connect('php', [], $this->workDir, 30.0);

        $received = [];
        $conn->onNotification(function (string $method, ?array $params) use (&$received): void {
            $received[] = ['method' => $method, 'params' => $params];
        });

        $conn->initialize();

        // Wait a short time for the notification to arrive.
        usleep(50000);

        $conn->disconnect();

        $this->assertCount(1, $received);
        $this->assertSame('window/showMessage', $received[0]['method']);
        $this->assertSame(3, $received[0]['params']['type']);
        $this->assertSame('test notification', $received[0]['params']['message']);

        unlink($notifyScript);
    }

    // -------------------------------------------------------------------------
    // Process death mid-read
    // -------------------------------------------------------------------------

    public function testProcessDiesMidRead(): void
    {
        $conn = new LspConnection('php', [$this->mockScript]);
        $conn->connect('php', [], $this->workDir, 5.0);
        $conn->initialize();

        // Get the underlying process resource via reflection to kill it.
        $reflection = new \ReflectionClass($conn);
        $processProp = $reflection->getProperty('process');
        $processProp->setAccessible(true);
        $process = $processProp->getValue($conn);

        // Kill the server process with SIGKILL.
        proc_terminate($process, SIGKILL);

        // The next request should return an I/O error since the process died.
        $defs = $conn->definitions('file:///test.php', 0, 0);
        // Process ended while reading — returns ioError response with empty result.
        $this->assertIsArray($defs);

        // Clean up.
        @proc_close($process);
    }

    // -------------------------------------------------------------------------
    // Missing Content-Length throws LspProtocolException
    // -------------------------------------------------------------------------

    public function testReadMessageThrowsOnMissingContentLength(): void
    {
        // Create a server that sends a message with header-body separator but no Content-Length,
        // then blocks on stdin (keeping the pipe open) long enough for the client to read and throw.
        $badScript = $this->workDir . '/bad_header_lsp_server.php';
        $badCode = <<<'PHP'
<?php
declare(strict_types=1);
// Read and discard any incoming request data.
fread(STDIN, 8192);
// Send a message with \r\n\r\n separator but no Content-Length header.
$body = '{"jsonrpc":"2.0","id":"0","result":{}}';
fwrite(STDOUT, "Some-Header: value\r\n\r\n" . $body);
fflush(STDOUT);
// Block on stdin to keep the pipe open; tearDown will kill us.
fread(STDIN, 8192);
PHP;
        file_put_contents($badScript, $badCode);

        $conn = new LspConnection('php', [$badScript]);

        try {
            $conn->connect('php', [], $this->workDir, 5.0);
            // The client sends initialize, reads the malformed response, throws LspProtocolException.
            $conn->sendRequest('initialize', [
                'processId' => getmypid(),
                'clientInfo' => ['name' => 'test', 'version' => '1.0.0'],
                'capabilities' => [],
            ]);
            $this->fail('Expected LspProtocolException was not thrown');
        } catch (\SugarCraft\Crush\LSP\LspProtocolException $e) {
            $this->assertStringContainsString('Missing Content-Length', $e->getMessage());
        } finally {
            $conn->disconnect();
            unlink($badScript);
        }
    }
}
