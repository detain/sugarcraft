<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

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
        $this->cache->set('file:///test.php', 'textDocument/definition@0:0', $expected);

        $result = $this->client->definitions('file:///test.php');

        $this->assertSame($expected, $result);
    }

    public function testDefinitionsQueriesConnectionOnCacheMiss(): void
    {
        $expected = [['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 1, 'character' => 0]]]];
        $this->connection->setDefinitionsResult($expected);

        $result = $this->client->definitions('file:///test.php');

        $this->assertSame($expected, $result);
        $this->assertTrue($this->cache->has('file:///test.php', 'textDocument/definition@0:0'));
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
        $this->cache->set('file:///test.php', 'textDocument/references@0:0', $expected);

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
        $this->cache->set('file:///test.php', 'textDocument/hover@0:0', $expected);

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
        $this->cache->set('file:///test.php', 'textDocument/codeAction@0:0', $expected);

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

    /**
     * THE POSITIONAL CACHE KEY, asserted behaviourally rather than by inspecting
     * the key string, and in BOTH directions because either half alone passes a
     * broken build.
     *
     * Same file, same method, different cursor: the answer stored for 0:0 must
     * NOT be handed back for 1:9. Before {@see LspClient::positionalKey()} it
     * was — one client, one connected server, `references` at line 1 then at
     * line 2 reached the server exactly ONCE and returned the first position's
     * answer for the second question, with nothing in the result saying so.
     *
     * The second half is what stops "fix" the cache by disabling it: same file,
     * same method, SAME cursor must still be a hit, and the connection's newer
     * answer must NOT be the one returned.
     */
    public function testACachedAnswerAtOnePositionIsNotReusedAtAnother(): void
    {
        $atOrigin = [['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 0, 'character' => 0]]]];
        $fresh = [['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 41, 'character' => 7]]]];

        $this->cache->set('file:///test.php', 'textDocument/definition@0:0', $atOrigin);
        $this->connection->initialize();
        $this->connection->setDefinitionsResult($fresh);

        $this->assertSame($fresh, $this->client->definitions('file:///test.php', 1, 9), 'a new position must ask');
        $this->assertSame($atOrigin, $this->client->definitions('file:///test.php', 0, 0), '0:0 was cached');
    }

    public function testASecondQueryAtTheSamePositionIsStillServedFromTheCache(): void
    {
        $cached = [['uri' => 'file:///test.php', 'range' => ['start' => ['line' => 1, 'character' => 9]]]];
        $wouldBeFresh = [['uri' => 'file:///other.php', 'range' => ['start' => ['line' => 2, 'character' => 0]]]];

        $this->cache->set('file:///test.php', 'textDocument/references@1:9', $cached);
        $this->connection->initialize();
        $this->connection->setReferencesResult($wouldBeFresh);

        $this->assertSame($cached, $this->client->references('file:///test.php', 1, 9));
    }

    /**
     * `codeAction` is the only positional request whose answer also depends on an
     * argument past the cursor — the diagnostics being asked about — so the
     * context is part of its key too. Asserted the same way: a context that
     * differs must not be served the other context's answer.
     */
    public function testCodeActionsWithADifferentContextIsADifferentCacheEntry(): void
    {
        $forEmptyContext = [['title' => 'cached for no context']];
        $fresh = [['title' => 'asked with a context']];

        $this->client->codeActions('file:///test.php', 0, 0, []);
        $this->assertTrue($this->cache->has('file:///test.php', 'textDocument/codeAction@0:0'));

        $this->cache->set('file:///test.php', 'textDocument/codeAction@0:0', $forEmptyContext);
        $this->connection->initialize();
        $this->connection->setCodeActionsResult($fresh);

        $withContext = $this->client->codeActions('file:///test.php', 0, 0, ['diagnostics' => [['code' => 'E1']]]);

        $this->assertSame($fresh, $withContext);
        $this->assertSame($forEmptyContext, $this->client->codeActions('file:///test.php', 0, 0, []));
    }

    /**
     * `clearFile()` swept only the CONSTRUCTOR's cache, which is the same object
     * as the default server's — so the narrower version was invisible until a
     * second server existed, and then left that server answering a saved file
     * from before the save. `clearAll()` always swept all of them; this pins that
     * `clearFile()` now does too, and that it takes every POSITION of the file
     * rather than only 0:0.
     */
    public function testClearFileEvictsEveryPositionOnEveryRegisteredServer(): void
    {
        $second = new FakeLspCache();
        $this->client->addServer('typescript', new FakeLspConnection(), $second);

        $this->cache->set('file:///test.php', 'textDocument/definition@0:0', [['uri' => 'a']]);
        $this->cache->set('file:///test.php', 'textDocument/definition@7:3', [['uri' => 'b']]);
        $second->set('file:///test.php', 'textDocument/hover@7:3', ['contents' => 'c']);
        $this->cache->set('file:///kept.php', 'textDocument/definition@0:0', [['uri' => 'd']]);

        $this->client->clearFile('file:///test.php');

        $this->assertFalse($this->cache->has('file:///test.php', 'textDocument/definition@0:0'));
        $this->assertFalse($this->cache->has('file:///test.php', 'textDocument/definition@7:3'));
        $this->assertFalse($second->has('file:///test.php', 'textDocument/hover@7:3'), 'the added server too');
        $this->assertTrue($this->cache->has('file:///kept.php', 'textDocument/definition@0:0'), 'other files stay');
    }

    /**
     * THE DECODER IS THE TOLERANT END OF THE URI PAIR, and this is the only test
     * that can prove it.
     *
     * `uriToPath()` used `urldecode()`, which implements
     * `application/x-www-form-urlencoded` — in which a literal `+` means a SPACE.
     * A `file://` URI is not a form body and `+` is legal in a POSIX filename, so
     * `file:///src/Web+Fetch.php` decoded to a path that does not exist and
     * `fallbackGrep()` returned `[]` — "no references" for a file it never
     * opened.
     *
     * WHY IT IS TESTED HERE AND NOT THROUGH THE TOOL. `LspTool::fileUri()` now
     * percent-encodes, and `%2B` decodes correctly under BOTH functions — so
     * with the producer fixed, a tool-level test cannot tell them apart (measured:
     * reverting this line to `urldecode()` left all 37 `LspToolTest` cases green).
     * `$uri` is a PUBLIC argument of every query method, though, so a caller that
     * is not that tool can hand over a raw `+`, and that is the case this drives.
     *
     * Disconnected on purpose: `fallbackGrep()` is the only consumer of the
     * decoded path.
     */
    public function testAUriWithAnUnencodedPlusStillResolvesToItsFile(): void
    {
        $dir = (string) realpath((string) sys_get_temp_dir()) . '/sc_lspc_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $path = $dir . '/Web+Fetch.php';
        file_put_contents($path, "<?php\n\$plusTarget = 1;\necho \$plusTarget;\n");

        try {
            $this->connection->disconnect(); // setUp() connects; the fallback is the path under test
            $this->assertFalse($this->connection->isConnected());

            $hits = $this->client->referencesFor('php', 'file://' . $path, 1, 0);

            $this->assertCount(2, $hits, 'both occurrences of $plusTarget');
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    /**
     * `documentSymbol` deliberately keeps the FILE-shaped key: the request takes
     * no position, so qualifying it with one would turn every cursor move into a
     * fresh whole-file parse for an identical answer.
     */
    public function testSymbolsKeepsTheFileShapedKeyBecauseTheRequestHasNoPosition(): void
    {
        $this->connection->initialize();
        $this->connection->setSymbolsResult([['name' => 'MyClass', 'kind' => 5]]);

        $this->client->symbols('file:///test.php');

        $this->assertTrue($this->cache->has('file:///test.php', 'textDocument/documentSymbol'));
    }
}
