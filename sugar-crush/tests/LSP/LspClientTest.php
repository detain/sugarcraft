<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\LSP\LspCacheInterface;
use SugarCraft\Crush\LSP\LspConnectionInterface;
use SugarCraft\Crush\LSP\LspResponse;

/**
 * Fake LspConnection for testing - implements LspConnectionInterface.
 */
final class FakeLspConnection implements LspConnectionInterface
{
    private bool $connected = false;
    private array $definitionsResult = [];
    private array $referencesResult = [];
    private ?array $hoverResult = null;
    private array $symbolsResult = [];
    private array $diagnosticsResult = [];
    private array $codeActionsResult = [];

    public function connect(string $command, array $env = [], ?string $cwd = null, float $timeout = 30.0): void
    {
        $this->connected = true;
    }

    public function initialize(): array
    {
        $this->connected = true;
        return ['capabilities' => ['textDocumentSync' => 1]];
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function sendRequest(string $method, ?array $params = null): LspResponse
    {
        return LspResponse::ok(['result' => null]);
    }

    public function sendNotification(string $method, ?array $params = null): void
    {
    }

    public function onNotification(callable $callback): void
    {
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function capabilities(): ?array
    {
        return ['textDocumentSync' => 1];
    }

    public function definitions(string $uri, int $line, int $col): array
    {
        return $this->definitionsResult;
    }

    public function references(string $uri, int $line, int $col): array
    {
        return $this->referencesResult;
    }

    public function hover(string $uri, int $line, int $col): ?array
    {
        return $this->hoverResult;
    }

    public function symbols(string $uri): array
    {
        return $this->symbolsResult;
    }

    public function codeActions(string $uri, int $line, int $col, array $context = []): array
    {
        return $this->codeActionsResult;
    }

    public function diagnostics(string $uri): array
    {
        return $this->diagnosticsResult;
    }

    // Mutators for test setup
    public function setDefinitionsResult(array $result): void
    {
        $this->definitionsResult = $result;
    }

    public function setReferencesResult(array $result): void
    {
        $this->referencesResult = $result;
    }

    public function setHoverResult(?array $result): void
    {
        $this->hoverResult = $result;
    }

    public function setSymbolsResult(array $result): void
    {
        $this->symbolsResult = $result;
    }

    public function setDiagnosticsResult(array $result): void
    {
        $this->diagnosticsResult = $result;
    }

    public function setCodeActionsResult(array $result): void
    {
        $this->codeActionsResult = $result;
    }
}

/**
 * Fake LspCache for testing - implements LspCacheInterface.
 */
final class FakeLspCache implements LspCacheInterface
{
    /** @var array<string, array{value: mixed, expiresAt: float}> */
    private array $entries = [];

    public function set(string $uri, string $method, mixed $value): void
    {
        $this->entries[$uri . "\x00" . $method] = [
            'value' => $value,
            'expiresAt' => microtime(true) + 60.0,
        ];
    }

    public function get(string $uri, string $method): mixed
    {
        $key = $uri . "\x00" . $method;
        if (!isset($this->entries[$key])) {
            return null;
        }
        $entry = $this->entries[$key];
        if ($entry['expiresAt'] < microtime(true)) {
            unset($this->entries[$key]);
            return null;
        }
        return $entry['value'];
    }

    public function has(string $uri, string $method): bool
    {
        $key = $uri . "\x00" . $method;
        if (!isset($this->entries[$key])) {
            return false;
        }
        if ($this->entries[$key]['expiresAt'] < microtime(true)) {
            unset($this->entries[$key]);
            return false;
        }
        return true;
    }

    public function clearFile(string $uri): void
    {
        $prefix = $uri . "\x00";
        foreach (array_keys($this->entries) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->entries[$key]);
            }
        }
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    public function prune(): int
    {
        $now = microtime(true);
        $removed = 0;
        foreach (array_keys($this->entries) as $key) {
            if ($this->entries[$key]['expiresAt'] < $now) {
                unset($this->entries[$key]);
                $removed++;
            }
        }
        return $removed;
    }

    public function count(): int
    {
        return count($this->entries);
    }
}

final class LspClientTest extends TestCase
{
    private FakeLspConnection $connection;
    private FakeLspCache $cache;
    private LspClient $client;

    protected function setUp(): void
    {
        $this->connection = new FakeLspConnection();
        $this->connection->connect('php', [], '/tmp', 30.0);
        $this->cache = new FakeLspCache();
        $this->client = new LspClient($this->connection, $this->cache);
    }

    public function testDefinitionsReturnsCachedResultOnCacheHit(): void
    {
        $expected = [['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 1, 'character' => 0]]]];
        $this->cache->set('file:///test.php', 'textDocument/definition', $expected);

        $result = $this->client->definitions('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testDefinitionsQueriesConnectionOnCacheMiss(): void
    {
        $expected = [['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 1, 'character' => 0]]]];
        $this->connection->setDefinitionsResult($expected);

        $result = $this->client->definitions('file:///test.php');

        $this->assertSame($expected, $result);
        $this->assertTrue($this->cache->has('file:///test.php', 'textDocument/definition'));
    }

    public function testDefinitionsReturnsEmptyOnDisconnected(): void
    {
        // No connection made — cache miss, no LSP server
        $result = $this->client->definitions('file:///test.php');
        $this->assertSame([], $result);
    }

    public function testReferencesReturnsCachedResultOnCacheHit(): void
    {
        $expected = [['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 2, 'character' => 0]]]];
        $this->cache->set('file:///test.php', 'textDocument/references', $expected);

        $result = $this->client->references('file:///test.php', 0, 0);

        $this->assertSame($expected, $result);
    }

    public function testReferencesQueriesConnectionOnCacheMiss(): void
    {
        $expected = [['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 2, 'character' => 0]]]];
        $this->connection->setReferencesResult($expected);

        $result = $this->client->references('file:///test.php', 0, 0);

        $this->assertSame($expected, $result);
    }

    public function testHoverReturnsCachedResultOnCacheHit(): void
    {
        $expected = ['contents' => 'Hover text'];
        $this->cache->set('file:///test.php', 'textDocument/hover', $expected);

        $result = $this->client->hover('file:///test.php', 0, 0);

        $this->assertSame($expected, $result);
    }

    public function testHoverReturnsNullWhenNotConnected(): void
    {
        // Hover returns null when LSP unavailable
        $result = $this->client->hover('file:///test.php', 0, 0);
        $this->assertNull($result);
    }

    public function testSymbolsReturnsCachedResultOnCacheHit(): void
    {
        $expected = [['name' => 'MyClass', 'kind' => 5]];
        $this->cache->set('file:///test.php', 'textDocument/documentSymbol', $expected);

        $result = $this->client->symbols('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testSymbolsQueriesConnectionOnCacheMiss(): void
    {
        $expected = [['name' => 'MyClass', 'kind' => 5]];
        $this->connection->setSymbolsResult($expected);

        $result = $this->client->symbols('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testDiagnosticsReturnsPushedDiagnostics(): void
    {
        $expected = [['message' => 'unused variable', 'severity' => 2]];
        $this->client->handlePublishDiagnostics('file:///test.php', $expected);

        $result = $this->client->diagnostics('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testCodeActionsReturnsCachedResultOnCacheHit(): void
    {
        $expected = [['title' => 'Remove unused variable', 'kind' => 1]];
        $this->cache->set('file:///test.php', 'textDocument/codeAction', $expected);

        $result = $this->client->codeActions('file:///test.php', 0, 0, []);

        $this->assertSame($expected, $result);
    }

    public function testCodeActionsQueriesConnectionOnCacheMiss(): void
    {
        $expected = [['title' => 'Remove unused variable', 'kind' => 1]];
        $this->connection->setCodeActionsResult($expected);

        $result = $this->client->codeActions('file:///test.php', 0, 0, []);

        $this->assertSame($expected, $result);
    }
}