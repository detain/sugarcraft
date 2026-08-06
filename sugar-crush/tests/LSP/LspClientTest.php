<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP\Tests;

use PHPUnit\Framework\TestCase;
use Mockery;
use Mockery\MockInterface;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\LSP\LspConnection;
use SugarCraft\Crush\LSP\LspCache;

final class LspClientTest extends TestCase
{
    private MockInterface $connection;
    private MockInterface $cache;
    private LspClient $client;

    protected function setUp(): void
    {
        $this->connection = Mockery::mock(LspConnection::class);
        $this->cache = Mockery::mock(LspCache::class);
        $this->client = new LspClient($this->connection, $this->cache);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testDefinitionsReturnsCachedResultOnCacheHit(): void
    {
        $expected = [['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 1, 'character' => 0]]]];
        $this->cache->shouldReceive('has')->with('file:///test.php', 'textDocument/definition')->once()->andReturn(true);
        $this->cache->shouldReceive('get')->with('file:///test.php', 'textDocument/definition')->once()->andReturn($expected);
        $this->connection->shouldNotReceive('definitions');

        $result = $this->client->definitions('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testDefinitionsQueriesConnectionOnCacheMiss(): void
    {
        $expected = [['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 1, 'character' => 0]]]];
        $this->cache->shouldReceive('has')->with('file:///test.php', 'textDocument/definition')->once()->andReturn(false);
        $this->connection->shouldReceive('isConnected')->once()->andReturn(true);
        $this->connection->shouldReceive('definitions')->with('file:///test.php', 0, 0)->once()->andReturn($expected);
        $this->cache->shouldReceive('set')->with('file:///test.php', 'textDocument/definition', $expected)->once();

        $result = $this->client->definitions('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testDefinitionsFallsBackToGrepWhenDisconnected(): void
    {
        $this->cache->shouldReceive('has')->andReturn(false);
        $this->connection->shouldReceive('isConnected')->andReturn(false);
        $this->cache->shouldReceive('set')->once();

        $result = $this->client->definitions('file:///test.php');

        $this->assertIsArray($result);
    }

    public function testReferencesReturnsCachedResultOnCacheHit(): void
    {
        $expected = [['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 1, 'character' => 0]]]];
        $this->cache->shouldReceive('has')->with('file:///test.php', 'textDocument/references')->once()->andReturn(true);
        $this->cache->shouldReceive('get')->with('file:///test.php', 'textDocument/references')->once()->andReturn($expected);

        $result = $this->client->references('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testReferencesQueriesConnectionOnCacheMiss(): void
    {
        $expected = [['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 1, 'character' => 0]]]];
        $this->cache->shouldReceive('has')->with('file:///test.php', 'textDocument/references')->once()->andReturn(false);
        $this->connection->shouldReceive('isConnected')->once()->andReturn(true);
        $this->connection->shouldReceive('references')->with('file:///test.php', 0, 0)->once()->andReturn($expected);
        $this->cache->shouldReceive('set')->once();

        $result = $this->client->references('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testHoverReturnsCachedResultOnCacheHit(): void
    {
        $expected = ['contents' => 'int $foo'];
        $this->cache->shouldReceive('has')->with('file:///test.php', 'textDocument/hover')->once()->andReturn(true);
        $this->cache->shouldReceive('get')->with('file:///test.php', 'textDocument/hover')->once()->andReturn($expected);

        $result = $this->client->hover('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testHoverQueriesConnectionOnCacheMiss(): void
    {
        $expected = ['contents' => 'int $foo'];
        $this->cache->shouldReceive('has')->with('file:///test.php', 'textDocument/hover')->once()->andReturn(false);
        $this->connection->shouldReceive('isConnected')->once()->andReturn(true);
        $this->connection->shouldReceive('hover')->with('file:///test.php', 0, 0)->once()->andReturn($expected);
        $this->cache->shouldReceive('set')->once();

        $result = $this->client->hover('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testSymbolsReturnsCachedResultOnCacheHit(): void
    {
        $expected = [['name' => 'foo', 'kind' => 12]];
        $this->cache->shouldReceive('has')->with('file:///test.php', 'textDocument/documentSymbol')->once()->andReturn(true);
        $this->cache->shouldReceive('get')->with('file:///test.php', 'textDocument/documentSymbol')->once()->andReturn($expected);

        $result = $this->client->symbols('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testSymbolsQueriesConnectionOnCacheMiss(): void
    {
        $expected = [['name' => 'foo', 'kind' => 12]];
        $this->cache->shouldReceive('has')->with('file:///test.php', 'textDocument/documentSymbol')->once()->andReturn(false);
        $this->connection->shouldReceive('isConnected')->once()->andReturn(true);
        $this->connection->shouldReceive('symbols')->with('file:///test.php')->once()->andReturn($expected);
        $this->cache->shouldReceive('set')->once();

        $result = $this->client->symbols('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testAddServerRegistersConnectionAndCache(): void
    {
        $conn2 = Mockery::mock(LspConnection::class);
        $cache2 = Mockery::mock(LspCache::class);
        $conn2->shouldReceive('isConnected')->andReturn(true)->zeroOrMoreTimes();

        $this->client->addServer('typescript', $conn2, $cache2);

        $this->assertTrue($this->client->isConnected('typescript'));
        $this->assertContains('typescript', $this->client->servers());
    }

    public function testUseSwitchesLanguage(): void
    {
        $conn2 = Mockery::mock(LspConnection::class);
        $cache2 = Mockery::mock(LspCache::class);
        $conn2->shouldReceive('isConnected')->andReturn(true)->zeroOrMoreTimes();

        $this->client->addServer('typescript', $conn2, $cache2);
        $switched = $this->client->use('typescript');

        $this->assertSame('typescript', $switched->language());
    }

    public function testUseSwitchesConnection(): void
    {
        $tsConn = Mockery::mock(LspConnection::class);
        $tsCache = Mockery::mock(LspCache::class);
        $tsConn->shouldReceive('isConnected')->andReturn(true)->zeroOrMoreTimes();

        $this->client->addServer('typescript', $tsConn, $tsCache);

        // PHP connection and cache should NOT be queried after switching to TypeScript.
        $this->connection->shouldNotReceive('definitions');
        $this->connection->shouldNotReceive('references');
        $this->connection->shouldNotReceive('hover');
        $this->connection->shouldNotReceive('symbols');
        $this->cache->shouldNotReceive('has');
        $this->cache->shouldNotReceive('set');

        // TypeScript connection and cache should be queried on cache miss.
        $tsCache->shouldReceive('has')->andReturn(false)->zeroOrMoreTimes();
        $tsCache->shouldReceive('set')->zeroOrMoreTimes();
        $tsConn->shouldReceive('definitions')->andReturn([]);
        $tsConn->shouldReceive('references')->andReturn([]);
        $tsConn->shouldReceive('hover')->andReturn(null);
        $tsConn->shouldReceive('symbols')->andReturn([]);

        $switched = $this->client->use('typescript');

        // Verify language switched.
        $this->assertSame('typescript', $switched->language());

        // Trigger queries that should now go to TypeScript connection.
        $switched->definitions('file:///test.ts');
        $switched->references('file:///test.ts');
        $switched->hover('file:///test.ts');
        $switched->symbols('file:///test.ts');
    }

    public function testUseThrowsOnUnknownLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No server registered');

        $this->client->use('nonexistent');
    }

    public function testDefinitionsForThrowsOnUnknownLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No server registered');

        $this->client->definitionsFor('nonexistent', 'file:///test.php');
    }

    public function testIsConnectedReturnsCorrectState(): void
    {
        $this->connection->shouldReceive('isConnected')->once()->andReturn(true);
        $this->assertTrue($this->client->isConnected());

        $this->connection->shouldReceive('isConnected')->once()->andReturn(false);
        $this->assertFalse($this->client->isConnected());
    }

    public function testLanguageReturnsCurrentLanguage(): void
    {
        $this->assertSame('php', $this->client->language());
    }

    public function testServersReturnsAllRegistered(): void
    {
        $this->assertSame(['php'], $this->client->servers());
    }

    public function testDefinitionsForUsesCorrectServer(): void
    {
        $conn2 = Mockery::mock(LspConnection::class);
        $cache2 = Mockery::mock(LspCache::class);
        $conn2->shouldReceive('isConnected')->andReturn(true)->zeroOrMoreTimes();
        $expected = [['uri' => 'file:///test.ts', 'range' => ['start' => ['line' => 1, 'character' => 0]]]];
        $conn2->shouldReceive('definitions')->andReturn($expected);
        $cache2->shouldReceive('has')->andReturn(false);
        $cache2->shouldReceive('set')->once();

        $this->client->addServer('typescript', $conn2, $cache2);
        $result = $this->client->definitionsFor('typescript', 'file:///test.ts');

        $this->assertSame($expected, $result);
    }

    public function testCodeActionsReturnsCachedResultOnCacheHit(): void
    {
        $expected = [['title' => 'Fix typo', 'kind' => 'quickfix']];
        $this->cache->shouldReceive('has')->with('file:///test.php', 'textDocument/codeAction')->once()->andReturn(true);
        $this->cache->shouldReceive('get')->with('file:///test.php', 'textDocument/codeAction')->once()->andReturn($expected);

        $result = $this->client->codeActions('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testCodeActionsQueriesConnectionOnCacheMiss(): void
    {
        $expected = [['title' => 'Fix typo', 'kind' => 'quickfix']];
        $this->cache->shouldReceive('has')->with('file:///test.php', 'textDocument/codeAction')->once()->andReturn(false);
        $this->connection->shouldReceive('isConnected')->once()->andReturn(true);
        $this->connection->shouldReceive('codeActions')->with('file:///test.php', 0, 0, [])->once()->andReturn($expected);
        $this->cache->shouldReceive('set')->once();

        $result = $this->client->codeActions('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testCodeActionsFallsBackToEmptyWhenDisconnected(): void
    {
        $this->cache->shouldReceive('has')->andReturn(false);
        $this->connection->shouldReceive('isConnected')->andReturn(false);
        $this->cache->shouldReceive('set')->once();

        $result = $this->client->codeActions('file:///test.php');

        $this->assertSame([], $result);
    }

    public function testCodeActionsPassesContextToConnection(): void
    {
        $context = ['diagnostics' => []];
        $expected = [['title' => 'Import missing class', 'kind' => 'quickfix']];
        $this->cache->shouldReceive('has')->with('file:///test.php', 'textDocument/codeAction')->once()->andReturn(false);
        $this->connection->shouldReceive('isConnected')->once()->andReturn(true);
        $this->connection->shouldReceive('codeActions')->with('file:///test.php', 5, 10, $context)->once()->andReturn($expected);
        $this->cache->shouldReceive('set')->once();

        $result = $this->client->codeActions('file:///test.php', 5, 10, $context);

        $this->assertSame($expected, $result);
    }

    public function testCodeActionsForThrowsOnUnknownLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No server registered');

        $this->client->codeActionsFor('nonexistent', 'file:///test.php');
    }

    public function testCodeActionsForUsesCorrectServer(): void
    {
        $conn2 = Mockery::mock(LspConnection::class);
        $cache2 = Mockery::mock(LspCache::class);
        $conn2->shouldReceive('isConnected')->andReturn(true)->zeroOrMoreTimes();
        $expected = [['title' => 'Add missing return', 'kind' => 'quickfix']];
        $conn2->shouldReceive('codeActions')->andReturn($expected);
        $cache2->shouldReceive('has')->andReturn(false);
        $cache2->shouldReceive('set')->once();

        $this->client->addServer('typescript', $conn2, $cache2);
        $result = $this->client->codeActionsFor('typescript', 'file:///test.ts');

        $this->assertSame($expected, $result);
    }
}
