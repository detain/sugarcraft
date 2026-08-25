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
     * WHY {@see \SugarCraft\Crush\LSP\LspConnection::isConnected()} HAD TO LEARN
     * ABOUT THE FRAMING LATCH — the downstream cost, measured here rather than
     * argued in a doc-block.
     *
     * ⚠️ THE SCOPE OF THIS ROW, CORRECTED. An earlier draft opened "every query
     * method in this class spells the same branch: consult the connection when
     * `isConnected()`, otherwise grep". It is the branch of the `definitions`,
     * `references` and `symbols` pairs only; `hover` and `codeActions` have no
     * grep arm at all and cache an empty answer on BOTH sides. This row is about
     * the fallback shape, and
     * {@see testTheHoverAndCodeActionPairsHaveNoGrepArmToBeSentDownTo()} is about
     * the other one.
     *
     * Within the fallback shape the branch is: consult the connection when
     * `isConnected()`, otherwise grep — and `$cache->set()` on BOTH sides. So the
     * predicate does not merely pick a source, it picks WHICH ANSWER BECOMES
     * PERMANENT for that uri+position. A connection that reports itself usable
     * while every send fails hands back `[]`, and this class writes that `[]`
     * into the cache; the grep fallback that would have found the real hits is
     * never reached, and never will be for that key.
     *
     * The two rows below are the same file and the same query, differing ONLY in
     * what the connection answers `isConnected()`:
     *
     *   isConnected() true, definitions/references empty -> `[]`, CACHED
     *   isConnected() false                              -> 2 real hits, cached
     *
     * The second row is the behaviour a latched
     * {@see \SugarCraft\Crush\LSP\LspConnection} now gets, and the first is what
     * it used to get. Note that the first row's `[]` survives the connection
     * subsequently producing a real answer — that is what "permanent" means here,
     * and it is asserted rather than asserted-about.
     */
    public function testAConnectionThatReportsItselfConnectedCachesItsEmptyAnswerForGood(): void
    {
        $dir = (string) realpath((string) sys_get_temp_dir()) . '/sc_lspc_latch_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $path = $dir . '/Latched.php';
        file_put_contents($path, "<?php\n\$latchTarget = 1;\necho \$latchTarget;\n");
        $uri = 'file://' . $path;

        try {
            // Row 1 — the pre-fix shape: usable-but-mute. `setUp()` connected it.
            $this->assertTrue($this->connection->isConnected());
            $this->connection->setReferencesResult([]);

            $this->assertSame(
                [],
                $this->client->referencesFor('php', $uri, 1, 0),
                'a connected server answering nothing is taken at its word, which is correct — '
                . 'the point of this row is what happens to that answer next',
            );
            $this->assertTrue(
                $this->cache->has($uri, 'textDocument/references@1:0'),
                'the empty answer was not cached, so this row cannot show that it is permanent',
            );

            $real = [['uri' => $uri, 'range' => ['start' => ['line' => 1, 'character' => 0]]]];
            $this->connection->setReferencesResult($real);
            $this->assertSame(
                [],
                $this->client->referencesFor('php', $uri, 1, 0),
                'the cached empty answer outlives the condition that produced it',
            );

            // Row 2 — what a latched connection now reports, and what it buys.
            $this->cache->clear();
            $this->connection->disconnect();
            $this->connection->setReferencesResult([]);

            $hits = $this->client->referencesFor('php', $uri, 1, 0);

            $this->assertCount(
                2,
                $hits,
                'a connection reporting itself unusable must send the query to the grep '
                . 'fallback, which is the degraded-but-real answer that path exists for',
            );
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    /**
     * THE OTHER HALF OF THE BRANCH SPLIT: four of the sites that consult
     * {@see \SugarCraft\Crush\LSP\LspConnection::isConnected()} have NO grep
     * arm, and the predicate's answer changes nothing at them.
     *
     * `hover`/`hoverFor` and `codeActions`/`codeActionsFor` cache `null` / `[]`
     * and return it when the predicate is false — this class says so in as many
     * words at both ("No fallback for hover", "No meaningful fallback for code
     * actions"). On a LATCHED connection the true arm lands on the same value:
     * `LspConnection::hover()` returns `null` on `$response->isError` and
     * `codeActions()` returns `[]`, so both arms cache the same empty answer.
     * That is the claim, and it is the one the framing-latch doc-block used to
     * get wrong by describing all ten sites as the fallback shape.
     *
     * ⚠️ WHY THE SYMBOLS ASSERTIONS ARE IN THIS ROW AND NOT A SEPARATE ONE. An
     * "the two arms agree" assertion passes just as well when the grep path is
     * broken for EVERYTHING — a fixture with no findable target, a temp file
     * that never got written, a uri that resolves nowhere. The last block drives
     * the SAME file and the SAME disconnected connection through `symbolsFor()`,
     * which is the fallback shape, and requires the two arms to DISAGREE there.
     * Without it this row would be an absence assertion with a dead instrument
     * behind it.
     */
    public function testTheHoverAndCodeActionPairsHaveNoGrepArmToBeSentDownTo(): void
    {
        $dir = (string) realpath((string) sys_get_temp_dir()) . '/sc_lspc_split_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $path = $dir . '/Split.php';
        file_put_contents($path, "<?php\nfinal class SplitProbe\n{\n    public function splitProbeMethod(): void\n    {\n    }\n}\n");
        $uri = 'file://' . $path;

        try {
            // ---- hover: the latched TRUE arm. The connection says it is usable
            // and answers nothing, which is what an ioError becomes upstream.
            $this->assertTrue($this->connection->isConnected());
            $this->connection->setHoverResult(null);

            $connectedHover = $this->client->hoverFor('php', $uri, 3, 4);
            $this->assertTrue(
                $this->cache->has($uri, 'textDocument/hover@3:4'),
                'the true arm did not cache, so the two arms cannot be compared on what they cached',
            );
            $connectedHoverCached = $this->cache->get($uri, 'textDocument/hover@3:4');

            // ---- hover: the FALSE arm, same file, same position.
            $this->cache->clear();
            $this->connection->disconnect();

            $disconnectedHover = $this->client->hoverFor('php', $uri, 3, 4);

            $this->assertNull($connectedHover, 'a latched hover is null');
            $this->assertNull($disconnectedHover, 'a disconnected hover is null too — there is no grep arm here');
            $this->assertSame(
                $connectedHoverCached,
                $this->cache->get($uri, 'textDocument/hover@3:4'),
                'the two arms cached different values for hover, so the predicate is NOT indifferent '
                . 'here and the isConnected() doc-block is wrong again in the other direction',
            );

            // ---- codeActions: the same pair.
            $this->cache->clear();
            $this->connection->connect('php', [], null, 30.0);
            $this->connection->setCodeActionsResult([]);

            $connectedActions = $this->client->codeActionsFor('php', $uri, 3, 4);
            $connectedActionsCached = $this->cache->get($uri, 'textDocument/codeAction@3:4');

            $this->cache->clear();
            $this->connection->disconnect();

            $disconnectedActions = $this->client->codeActionsFor('php', $uri, 3, 4);

            $this->assertSame([], $connectedActions);
            $this->assertSame([], $disconnectedActions, 'a disconnected codeActions is [] too — no grep arm here either');
            $this->assertSame(
                $connectedActionsCached,
                $this->cache->get($uri, 'textDocument/codeAction@3:4'),
                'the two arms cached different values for codeActions',
            );

            // ---- THE POSITIVE CONTROL. Same file, same disconnected connection,
            // a method in the FALLBACK shape: here the two arms must DISAGREE, or
            // everything above passed because grep found nothing for anybody.
            $this->cache->clear();
            $this->connection->connect('php', [], null, 30.0);
            $this->connection->setSymbolsResult([]);
            $connectedSymbols = $this->client->symbolsFor('php', $uri);

            $this->cache->clear();
            $this->connection->disconnect();
            $disconnectedSymbols = $this->client->symbolsFor('php', $uri);

            $this->assertSame([], $connectedSymbols, 'the true arm takes the server at its word');
            $this->assertNotSame(
                [],
                $disconnectedSymbols,
                'the fallback shape produced nothing either, so this fixture cannot tell '
                . '"hover has no grep arm" from "grep found nothing in this file at all"',
            );
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
