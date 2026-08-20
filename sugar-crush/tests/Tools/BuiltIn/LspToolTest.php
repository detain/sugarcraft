<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspCacheInterface;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\LSP\LspConnectionInterface;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tools\BuiltIn\LspTool;

/**
 * A connection that ANSWERS whatever it was told to and REMEMBERS what it was
 * asked, so a test can pin the coordinates the tool forwarded rather than only
 * the shape of the answer.
 *
 * `$connected` is settable because {@see LspClient} branches on `isConnected()`
 * into a grep FALLBACK, and a test about the tool must not accidentally be
 * measuring that fallback's output.
 */
final class SpyLspConnection implements LspConnectionInterface
{
    /** @var list<array{string, string, int, int}> method, uri, line, col */
    public array $asked = [];

    public function __construct(
        private bool $connected = true,
        private array $definitions = [],
        private array $references = [],
        private ?array $hover = null,
        private array $symbols = [],
        private array $codeActions = [],
    ) {}

    public function connect(string $command, array $env, ?string $cwd = null, float $timeout = 30.0): void
    {
        $this->connected = true;
    }

    public function initialize(): array
    {
        return ['capabilities' => []];
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function definitions(string $uri, int $line, int $col): array
    {
        $this->asked[] = ['definitions', $uri, $line, $col];

        return $this->definitions;
    }

    public function references(string $uri, int $line, int $col): array
    {
        $this->asked[] = ['references', $uri, $line, $col];

        return $this->references;
    }

    public function hover(string $uri, int $line, int $col): ?array
    {
        $this->asked[] = ['hover', $uri, $line, $col];

        return $this->hover;
    }

    public function symbols(string $uri): array
    {
        $this->asked[] = ['symbols', $uri, 0, 0];

        return $this->symbols;
    }

    public function codeActions(string $uri, int $line, int $col, array $context = []): array
    {
        $this->asked[] = ['codeActions', $uri, $line, $col];

        return $this->codeActions;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function capabilities(): ?array
    {
        return [];
    }

    public function onNotification(callable $callback): void {}
}

/** An in-memory cache with no TTL, so a test never races a clock. */
final class ArrayLspCache implements LspCacheInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];

    public function set(string $uri, string $method, mixed $value): void
    {
        $this->entries[$uri . '|' . $method] = $value;
    }

    public function get(string $uri, string $method): mixed
    {
        return $this->entries[$uri . '|' . $method] ?? null;
    }

    public function clearFile(string $uri): void
    {
        foreach (array_keys($this->entries) as $key) {
            if (str_starts_with($key, $uri . '|')) {
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
        return 0;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function has(string $uri, string $method): bool
    {
        return \array_key_exists($uri . '|' . $method, $this->entries);
    }
}

/**
 * @see LspTool
 */
final class LspToolTest extends TestCase
{
    private string $root;

    private string $file;

    protected function setUp(): void
    {
        $this->root = (string) realpath((string) sys_get_temp_dir()) . '/sc_lsp_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/sub', 0o777, true);
        $this->file = $this->root . '/sub/Target.php';
        file_put_contents($this->file, "<?php\nfunction target(): void {}\n");
    }

    protected function tearDown(): void
    {
        // A glob sweep rather than one unlink, because several tests below write
        // their OWN fixture next to $this->file (a filename with a `+` in it, a
        // file whose line numbers are the thing under test). Removing only
        // $this->file left those behind and made both rmdir()s fail silently.
        foreach ((array) glob($this->root . '/sub/*') as $leftover) {
            @unlink((string) $leftover);
        }
        @rmdir($this->root . '/sub');
        @rmdir($this->root);
    }

    /**
     * Write a fixture under the root and return its ABSOLUTE path, so a test can
     * assert against the same string the tool resolves to.
     */
    private function write(string $relative, string $contents): string
    {
        $absolute = $this->root . '/' . $relative;
        file_put_contents($absolute, $contents);

        return $absolute;
    }

    // -------------------------------------------------------------------------
    // Contract surface
    // -------------------------------------------------------------------------

    public function testNameIsTheWireNameBootstrapAdvertises(): void
    {
        $this->assertSame('Lsp', (new LspTool())->name());
    }

    /**
     * The description has to name every operation the schema accepts, and the
     * assertion is driven off the SCHEMA rather than off a literal list — so an
     * operation added to the tool and forgotten in the prose reds here instead of
     * shipping as an undocumented argument value.
     */
    public function testDescriptionNamesEveryOperationTheSchemaAccepts(): void
    {
        $tool = new LspTool();
        $description = $tool->description();

        $operations = $tool->inputSchema()['properties']['operation']['enum'];
        $this->assertNotEmpty($operations);

        foreach ($operations as $operation) {
            $this->assertStringContainsString($operation, $description, $operation);
        }
    }

    /**
     * The two facts a caller gets wrong unprompted, and both are claims about
     * behaviour asserted elsewhere in this file: the zero-indexing is proven by
     * {@see testTheLineAndColumnAreForwardedUnchangedBecauseTheyAreAlreadyZeroIndexed()}
     * and the refuse-don't-answer-empty rule by
     * {@see testAnUnconfiguredLanguageIsAnErrorWhileAConfiguredEmptyAnswerIsNot()}.
     */
    public function testDescriptionStatesTheZeroIndexingAndTheRefusalContract(): void
    {
        $description = (new LspTool())->description();

        $this->assertStringContainsString('ZERO-INDEXED', $description);
        $this->assertStringContainsString('Grep', $description, 'the off-by-one is against Grep\'s 1-based output');
        $this->assertStringContainsString('error rather than an empty result', $description);
    }

    public function testSchemaRequiresOperationAndPathAndEnumeratesTheOperations(): void
    {
        $schema = (new LspTool())->inputSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertSame(['operation', 'path'], $schema['required']);
        $this->assertSame(
            ['definition', 'references', 'hover', 'symbols', 'codeActions', 'diagnostics'],
            $schema['properties']['operation']['enum'],
        );
        $this->assertSame('integer', $schema['properties']['line']['type']);
        $this->assertSame('integer', $schema['properties']['column']['type']);
    }

    /**
     * Only a rooted instance claims a boundary. An unrooted one really does
     * accept any readable file, and a schema that said otherwise would be the
     * tool lying about its own containment.
     */
    public function testOnlyARootedInstanceAdvertisesTheWorkspaceBoundary(): void
    {
        $rooted = (new LspTool(null, $this->root))->inputSchema();
        $unrooted = (new LspTool())->inputSchema();

        $this->assertStringContainsString(
            'inside the workspace root',
            $rooted['properties']['path']['description'],
        );
        $this->assertStringNotContainsString(
            'workspace root',
            $unrooted['properties']['path']['description'],
        );
    }

    // -------------------------------------------------------------------------
    // Argument validation
    // -------------------------------------------------------------------------

    /**
     * `execute([])` is what the corpus-driven conformance test in
     * {@see \SugarCraft\Crush\Tests\Tools\BuiltInToolTest} calls on every
     * built-in, so it must be a result and not a throw.
     */
    public function testAnEmptyArgumentListIsARefusalNamingTheOperationSet(): void
    {
        $result = (new LspTool())->execute([]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('operation must be one of', $result->content());
        $this->assertStringContainsString('references', $result->content());
    }

    public function testAnUnknownOperationIsRefusedAndQuotedBack(): void
    {
        $result = (new LspTool())->execute(['operation' => 'rename']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('(got "rename")', $result->content());
    }

    public function testAnEmptyPathIsRefused(): void
    {
        $result = (new LspTool())->execute(['operation' => 'hover', 'path' => '']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('path cannot be empty', $result->content());
    }

    /**
     * A CORRECTION TO THIS TEST'S OWN EARLIER NAME, which claimed the guard
     * prevented a crash. A mutation that replaced the guard's condition with
     * `false` still died here — but on the message, and its failure text
     * (`Failed asserting that 'Error: path outside workspace root' contains "NUL
     * byte"`) showed there was no crash to prevent. MEASURED on PHP 8.3.6:
     * `realpath()` does throw a ValueError on a NUL byte, but neither branch
     * reaches it with one. Rooted, `PathJail::resolve()` screens NUL in its own
     * `unusable()` first; unrooted, `is_file()` returns false rather than
     * throwing.
     *
     * So the guard buys the MESSAGE, and that is worth a test: without it the
     * rooted path answers `outside workspace root` for a path that is not
     * outside the root, which sends the caller to fix the wrong thing. Both
     * arrangements are driven, because they fail differently.
     */
    public function testANulByteIsRefusedInThisToolsOwnVocabularyRatherThanAsAContainmentVerdict(): void
    {
        foreach ([$this->root, null] as $root) {
            $result = (new LspTool($this->clientFor(), $root))
                ->execute(['operation' => 'hover', 'path' => "sub/Target\0.php"]);

            $this->assertTrue($result->isError(), var_export($root, true));
            $this->assertStringContainsString('NUL byte', $result->content(), var_export($root, true));
            $this->assertStringNotContainsString('outside workspace root', $result->content());
            $this->assertStringNotContainsString('file not found', $result->content());
        }
    }

    public function testTheToolCallIdIsEchoedOntoEveryResult(): void
    {
        $tool = new LspTool($this->clientFor());

        $refusal = (new LspTool())->execute(['id' => 'call_a']);
        $answer = $tool->execute(['id' => 'call_b', 'operation' => 'symbols', 'path' => $this->file]);

        $this->assertSame('call_a', $refusal->toolCallId());
        $this->assertSame('call_b', $answer->toolCallId());
    }

    // -------------------------------------------------------------------------
    // THE DESIGN DECISION: refuse, never answer empty
    // -------------------------------------------------------------------------

    /**
     * THE ONE ASSERTION THIS WHOLE TOOL EXISTS FOR, and it is a PAIR because
     * either half alone proves nothing.
     *
     * The same query, the same existing file, the same operation — differing only
     * in whether a server is registered for the language. With no server it must
     * be an ERROR: an empty success reads to a model as "this symbol has no
     * references", which is a fabricated fact about the codebase. With a server
     * that genuinely answers `[]` it must be a SUCCESS: that IS the answer, and
     * reporting it as an error would teach the model to distrust a true result.
     *
     * A build that returned an error in BOTH cases, or a success in both, passes
     * neither half. A test that only asserted the no-server case would pass a
     * build that errors unconditionally.
     */
    public function testAnUnconfiguredLanguageIsAnErrorWhileAConfiguredEmptyAnswerIsNot(): void
    {
        $args = ['operation' => 'references', 'path' => $this->file];

        $unconfigured = (new LspTool(null, $this->root))->execute($args);
        $configured = (new LspTool($this->clientFor(new SpyLspConnection(references: [])), $this->root))
            ->execute($args);

        $this->assertTrue($unconfigured->isError(), 'no server must be an error');
        $this->assertStringContainsString('no language server configured for php', $unconfigured->content());

        $this->assertFalse($configured->isError(), 'a server that answered [] gave a real answer');
        $this->assertStringContainsString('No references found', $configured->content());
        $this->assertStringNotContainsString('no language server configured', $configured->content());
    }

    /**
     * And the refusal names the LANGUAGE, not just "LSP unavailable". A client
     * with a `php` server asked about `go` must refuse in go's name — otherwise a
     * model has no way to tell "you have no LSP at all" from "you have one, for
     * another language".
     */
    public function testTheRefusalNamesTheRequestedLanguageNotTheDefaultOne(): void
    {
        $result = (new LspTool($this->clientFor(), $this->root))->execute([
            'operation' => 'definition',
            'path' => $this->file,
            'language' => 'go',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('no language server configured for go', $result->content());
        $this->assertStringNotContainsString('for php', $result->content());
    }

    /** A second registered language is reachable, so the refusal above is not just "anything but php". */
    public function testASecondRegisteredLanguageIsAsked(): void
    {
        $go = new SpyLspConnection(symbols: [['name' => 'main', 'kind' => 12]]);
        $client = $this->clientFor();
        $client->addServer('go', $go, new ArrayLspCache());

        $result = (new LspTool($client, $this->root))->execute([
            'operation' => 'symbols',
            'path' => $this->file,
            'language' => 'go',
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('main', $result->content());
        $this->assertStringContainsString('(language: go)', $result->content());
        $this->assertCount(1, $go->asked);
    }

    /**
     * THE ORDER OF THE TWO GUARDS IS ITSELF THE CONTRACT. The server check runs
     * BEFORE the path is resolved, so a launch with no LSP configured is told the
     * actionable thing ("configure a server") rather than a "file not found" for a
     * file it would never have queried. Driven with a path that is BOTH absent and
     * outside the root, so only the server message can be correct.
     */
    public function testTheMissingServerIsReportedBeforeAnyComplaintAboutThePath(): void
    {
        $result = (new LspTool(null, $this->root))->execute([
            'operation' => 'hover',
            'path' => '/nonexistent/outside/File.php',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('no language server configured', $result->content());
        $this->assertStringNotContainsString('file not found', $result->content());
        $this->assertStringNotContainsString('outside workspace root', $result->content());
    }

    // -------------------------------------------------------------------------
    // Containment
    // -------------------------------------------------------------------------

    public function testAPathOutsideTheRootIsRefused(): void
    {
        $outside = (string) realpath((string) sys_get_temp_dir()) . '/sc_lsp_outside_' . bin2hex(random_bytes(4));
        file_put_contents($outside, '<?php');

        try {
            $result = (new LspTool($this->clientFor(), $this->root))->execute([
                'operation' => 'references',
                'path' => $outside,
            ]);

            $this->assertTrue($result->isError());
            $this->assertStringContainsString('outside workspace root', $result->content());
        } finally {
            @unlink($outside);
        }
    }

    /**
     * `PathJail::resolve()` accepts a MISSING file whose parent exists, so
     * containment alone would forward `sub/Ghost.php` to the server — and every
     * LSP query for a URI the server never opened comes back empty, which is the
     * confident-lie shape again one layer down.
     */
    public function testAMissingFileInsideTheRootIsRefusedRatherThanQueried(): void
    {
        $connection = new SpyLspConnection();

        $result = (new LspTool($this->clientFor($connection), $this->root))->execute([
            'operation' => 'references',
            'path' => 'sub/Ghost.php',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('file not found', $result->content());
        $this->assertSame([], $connection->asked, 'nothing may reach the server for a file that is not there');
    }

    /** An unrooted instance still refuses a file that does not exist. */
    public function testAnUnrootedInstanceStillRefusesAMissingFile(): void
    {
        $result = (new LspTool($this->clientFor()))->execute([
            'operation' => 'references',
            'path' => $this->root . '/sub/Ghost.php',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('file not found', $result->content());
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    /**
     * Every operation reaches its own client method. Asserted by the SPY's
     * record rather than by the content, so an implementation that answered the
     * right shape from the wrong request cannot pass.
     *
     * `diagnostics` is absent from the mapping deliberately: it is server-PUSH
     * and reads a local map, so it makes no request at all —
     * {@see testDiagnosticsReadsThePushedMapAndMakesNoRequest()} covers it.
     */
    public function testEachOperationReachesItsOwnConnectionMethod(): void
    {
        foreach ([
            'definition' => 'definitions',
            'references' => 'references',
            'hover' => 'hover',
            'symbols' => 'symbols',
            'codeActions' => 'codeActions',
        ] as $operation => $expected) {
            $connection = new SpyLspConnection();

            (new LspTool($this->clientFor($connection), $this->root))->execute([
                'operation' => $operation,
                'path' => $this->file,
            ]);

            $this->assertSame([$expected], array_column($connection->asked, 0), $operation);
        }
    }

    /**
     * The URI is a `file://` URI over the RESOLVED path, and the coordinates are
     * forwarded UNCHANGED. The zero-indexing claim in {@see LspTool::description()}
     * is only true if nothing here adjusts them, and an off-by-one would return a
     * plausible answer about the wrong line — the least detectable failure this
     * tool has.
     */
    public function testTheLineAndColumnAreForwardedUnchangedBecauseTheyAreAlreadyZeroIndexed(): void
    {
        $connection = new SpyLspConnection();

        (new LspTool($this->clientFor($connection), $this->root))->execute([
            'operation' => 'definition',
            'path' => 'sub/Target.php',
            'line' => 1,
            'column' => 9,
        ]);

        $this->assertSame(
            ['definitions', 'file://' . $this->file, 1, 9],
            $connection->asked[0],
        );
    }

    /** A negative coordinate is clamped to 0 rather than sent to a server that would reject it. */
    public function testANegativeCoordinateIsClampedToZero(): void
    {
        $connection = new SpyLspConnection();

        (new LspTool($this->clientFor($connection), $this->root))->execute([
            'operation' => 'definition',
            'path' => 'sub/Target.php',
            'line' => -5,
            'column' => -1,
        ]);

        $this->assertSame([0, 0], [$connection->asked[0][2], $connection->asked[0][3]]);
    }

    public function testAnAnswerIsEncodedWithItsOperationAndTheLspMethodItCameFrom(): void
    {
        $hit = ['uri' => 'file://' . $this->file, 'range' => ['start' => ['line' => 1, 'character' => 9]]];
        $client = $this->clientFor(new SpyLspConnection(references: [$hit]));

        $result = (new LspTool($client, $this->root))->execute([
            'operation' => 'references',
            'path' => 'sub/Target.php',
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('references (textDocument/references)', $result->content());
        $this->assertStringContainsString('"character": 9', $result->content());
    }

    /** A hover that legitimately has nothing to say is a success, not an error. */
    public function testANullHoverIsASuccessSayingTheServerHadNothing(): void
    {
        $result = (new LspTool($this->clientFor(new SpyLspConnection(hover: null)), $this->root))->execute([
            'operation' => 'hover',
            'path' => 'sub/Target.php',
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('No hover found', $result->content());
    }

    /**
     * `diagnostics` reads the map {@see LspClient::handlePublishDiagnostics()}
     * fills from the server's own notifications; it is not a request, and the spy
     * proves none is made.
     */
    public function testDiagnosticsReadsThePushedMapAndMakesNoRequest(): void
    {
        $connection = new SpyLspConnection();
        $client = $this->clientFor($connection);
        $client->handlePublishDiagnostics('file://' . $this->file, [['message' => 'undefined variable $x']]);

        $result = (new LspTool($client, $this->root))->execute([
            'operation' => 'diagnostics',
            'path' => 'sub/Target.php',
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('undefined variable $x', $result->content());
        $this->assertSame([], $connection->asked);
    }

    /**
     * A `references` answer on a common identifier is unbounded the same way a
     * `grep` hit list is, and it is replayed into every following request of the
     * turn. The cap announces what it dropped rather than silently shortening,
     * which is the whole point of {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput}.
     */
    public function testALargeAnswerIsClippedWithAMarkerRatherThanSilentlyShortened(): void
    {
        $hits = [];
        for ($i = 0; $i < 400; ++$i) {
            $hits[] = ['uri' => 'file://' . $this->file, 'line' => $i];
        }
        $client = $this->clientFor(new SpyLspConnection(references: $hits));

        $result = (new LspTool($client, $this->root, maxOutputBytes: 512))->execute([
            'operation' => 'references',
            'path' => 'sub/Target.php',
        ]);

        $this->assertFalse($result->isError());
        $this->assertLessThan(1024, \strlen($result->content()));
        $this->assertStringContainsString('truncated', strtolower($result->content()));
    }

    /**
     * A DISCONNECTED but REGISTERED server is NOT a refusal: {@see LspClient}
     * falls back to a same-file grep in that case, which is a real (degraded)
     * answer this tool deliberately does not intercept. Pinned because the
     * obvious "refuse unless isConnected()" implementation would silently delete
     * that whole fallback path.
     */
    public function testARegisteredButDisconnectedServerFallsBackInsteadOfRefusing(): void
    {
        $client = $this->clientFor(new SpyLspConnection(connected: false));

        $result = (new LspTool($client, $this->root))->execute([
            'operation' => 'references',
            'path' => 'sub/Target.php',
            'line' => 1,
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringNotContainsString('no language server configured', $result->content());
        $this->assertStringContainsString('references', $result->content());
    }

    /**
     * AND THE FALLBACK'S ANSWER IS LABELLED AS ONE.
     *
     * This is a correction to my own first draft, not a nicety. That draft headed
     * every answer "from the %s language server", and MEASURED against a
     * disconnected spy it printed:
     *
     *     references (textDocument/references) from the php language server for …
     *     [ { "uri": …, "range": { "start": { "line": 1, "character": 0 } … } ]
     *
     * — one hit, produced by {@see LspClient}'s same-file GREP fallback, presented
     * as a semantic reference from a server that was never connected. That is the
     * difference between "these are the callers" and "these lines contain that
     * word", and the model cannot tell them apart without being told.
     *
     * The pair is what makes it falsifiable: connected must NOT carry the note,
     * disconnected must. A build that always warned would be as useless as one
     * that never did.
     */
    public function testOnlyTheDisconnectedAnswerCarriesTheTextMatchWarning(): void
    {
        $hit = ['uri' => 'file://' . $this->file, 'line' => 1];

        $connected = (new LspTool($this->clientFor(new SpyLspConnection(references: [$hit])), $this->root))
            ->execute(['operation' => 'references', 'path' => 'sub/Target.php', 'line' => 1]);
        $disconnected = (new LspTool($this->clientFor(new SpyLspConnection(connected: false)), $this->root))
            ->execute(['operation' => 'references', 'path' => 'sub/Target.php', 'line' => 1]);

        $this->assertStringNotContainsString('NOT connected', $connected->content());
        $this->assertStringNotContainsString('text matches', $connected->content());

        $this->assertStringContainsString('registered but NOT connected', $disconnected->content());
        $this->assertStringContainsString('text matches, not semantic results', $disconnected->content());
    }

    /** The warning reaches the EMPTY answer too — hover has no fallback at all. */
    public function testAnEmptyAnswerFromADisconnectedServerAlsoCarriesTheWarning(): void
    {
        $result = (new LspTool($this->clientFor(new SpyLspConnection(connected: false)), $this->root))
            ->execute(['operation' => 'hover', 'path' => 'sub/Target.php']);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('No hover found', $result->content());
        $this->assertStringContainsString('registered but NOT connected', $result->content());
    }

    // -------------------------------------------------------------------------
    // Coordinates: the value, the encoding, and the cache
    // -------------------------------------------------------------------------

    /**
     * EVERY PLACE THIS TOOL STATES ITS INDEXING, CHECKED AGAINST A MEASUREMENT OF
     * IT — because three earlier assertions checked only that the words
     * `ZERO-INDEXED` and `Grep` were PRESENT, and a mutation that inverted the
     * instruction to "add one when passing a Grep hit here" passed all of them.
     * An inverted instruction is worse than a missing one: it produces a
     * plausible answer about the wrong line.
     *
     * THE MEASUREMENT'S DOMAIN, stated because it is not a real server. The only
     * consumer of a line NUMBER in this build is
     * {@see LspClient::fallbackGrep()}, which indexes `file()`'s ZERO-based array
     * — the same convention the LSP spec mandates of a server, and the reason a
     * disconnected server is the arrangement used here. `$grepLine` is derived
     * the way `grep -n` derives it (`$index + 1`) rather than written down, so
     * the test cannot drift from the file it writes.
     *
     * The probe operation is `references`, whose fallback answers only when there
     * IS an identifier under the cursor — and the fixture's last line is `};` on
     * purpose, so that of the three candidate offsets exactly one can land: one
     * lands on the call site, one on a line with no identifier at all, one past
     * the end of the file. (`symbols` cannot be the probe: `documentSymbol`
     * carries no position, so its fallback ignores the cursor by design.)
     */
    public function testEveryStatementOfTheIndexingAgreesWithTheMeasuredIndexing(): void
    {
        $absolute = $this->write(
            'sub/Indexed.php',
            "<?php\nfunction probeTarget(): void {}\nprobeTarget();\n};\n",
        );

        $grepLine = null;
        foreach ((array) file($absolute, FILE_IGNORE_NEW_LINES) as $index => $text) {
            if (\is_string($text) && str_contains($text, 'probeTarget();')) {
                $grepLine = $index + 1; // `grep -n` numbers from 1
                break;
            }
        }
        $this->assertNotNull($grepLine, 'the fixture must contain the call site');

        $tool = new LspTool($this->clientFor(new SpyLspConnection(connected: false)), $this->root);

        $landed = [];
        foreach ([-1, 0, 1] as $offset) {
            $result = $tool->execute([
                'operation' => 'references',
                'path' => 'sub/Indexed.php',
                'line' => $grepLine + $offset,
            ]);
            if (!str_contains($result->content(), 'No references found')) {
                $landed[] = $offset;
            }
        }

        $this->assertSame([-1], $landed, 'a Grep line number needs one SUBTRACTED to address the same line');

        $schema = $tool->inputSchema();
        $statements = [
            'description()' => $tool->description(),
            'schema line' => $schema['properties']['line']['description'],
            'schema column' => $schema['properties']['column']['description'],
        ];

        foreach ($statements as $where => $text) {
            $this->assertStringContainsString('ZERO-INDEXED', $text, $where);
            $this->assertStringNotContainsString('ONE-INDEXED', $text, $where);
        }

        // The ARITHMETIC in prose must be the arithmetic just measured, and the
        // filter is exclusive so an inversion fails rather than merely stops
        // matching.
        $spelled = array_values(array_filter(
            ['subtract one', 'add one'],
            static fn (string $phrase): bool => str_contains($statements['description()'], $phrase),
        ));
        $this->assertSame($landed[0] === -1 ? ['subtract one'] : ['add one'], $spelled);

        // And the per-argument text the model reads must not contradict it.
        $this->assertStringContainsString('Grep prints 1-based', $statements['schema line']);
        $this->assertStringNotContainsString('Grep prints 0-based', $statements['schema line']);
    }

    /**
     * A model emitting JSON emits `"line": "12"` often enough that refusing it
     * would be the wrong call, and the code this replaced did something worse
     * than either: `is_int($args['line']) ? … : 0` answered about line 0 and
     * reported it as the answer to the question asked. MEASURED before the fix,
     * `['line' => '1', 'column' => '9']` reached the connection as `0, 0` with
     * `isError=false`.
     */
    public function testAnIntegralStringOrFloatCoordinateIsAcceptedAtItsValue(): void
    {
        foreach ([['1', '9'], [1.0, 9.0], [' 1 ', '9']] as [$line, $column]) {
            $connection = new SpyLspConnection();

            (new LspTool($this->clientFor($connection), $this->root))->execute([
                'operation' => 'definition',
                'path' => 'sub/Target.php',
                'line' => $line,
                'column' => $column,
            ]);

            $this->assertSame(
                [1, 9],
                [$connection->asked[0][2], $connection->asked[0][3]],
                var_export([$line, $column], true),
            );
        }
    }

    /**
     * And anything that is not an integer is REFUSED rather than silently
     * becoming line 0 — the same rule `operation` and `path` already followed.
     * `asked` is asserted empty as well as the error, because a refusal that had
     * already queried the server would be a refusal in name only.
     */
    public function testANonIntegralCoordinateIsRefusedRatherThanBecomingLineZero(): void
    {
        foreach ([
            ['line', '1.5'],
            ['line', 'twelve'],
            ['line', 1.5],
            ['column', true],
            ['column', [1]],
        ] as [$axis, $value]) {
            $connection = new SpyLspConnection();

            $result = (new LspTool($this->clientFor($connection), $this->root))->execute([
                'operation' => 'definition',
                'path' => 'sub/Target.php',
                $axis => $value,
            ]);

            $label = $axis . '=' . var_export($value, true);
            $this->assertTrue($result->isError(), $label);
            $this->assertStringContainsString($axis . ' must be a zero-indexed integer', $result->content(), $label);
            $this->assertSame([], $connection->asked, $label . ' must not reach the server');
        }
    }

    /**
     * THE URI IS PERCENT-ENCODED, and the assertion is a ROUND TRIP rather than
     * a spelling check, because the only thing that matters is that
     * {@see LspClient}'s decoder gets the path back.
     *
     * `'file://' . $path` did not. MEASURED: `sub/Web+Fetch.php`, two occurrences
     * of the identifier, a registered-but-disconnected server — the answer was
     * `isError=false` / "No references found", because `urldecode()` turned the
     * `+` into a space, `file_exists()` failed on a path that does not exist, and
     * the fallback returned `[]`. A successful empty answer for a file nobody
     * opened is precisely the fabrication this tool exists to refuse, arriving
     * through a legal filename rather than through a missing server.
     */
    public function testThePathRoundTripsThroughTheUriEvenWithAReservedCharacterInIt(): void
    {
        $absolute = $this->write('sub/Web+Fetch.php', "<?php\n");
        $connection = new SpyLspConnection();

        (new LspTool($this->clientFor($connection), $this->root))->execute([
            'operation' => 'definition',
            'path' => 'sub/Web+Fetch.php',
        ]);

        $uri = $connection->asked[0][1];

        $this->assertSame($absolute, rawurldecode(substr($uri, 7)), 'the decoder must get the path back');
        $this->assertStringContainsString('Web%2BFetch.php', $uri, 'the + must be escaped, not passed raw');
        $this->assertStringNotContainsString('%2F', $uri, 'separators must stay separators');
    }

    /**
     * And the end-to-end consequence, driven through the fallback that reads the
     * decoded path: the same file that used to answer "No references found"
     * answers with its hits.
     */
    public function testAFilenameWithAPlusIsAnsweredRatherThanReportedEmpty(): void
    {
        $this->write('sub/Web+Fetch.php', "<?php\n\$plusTarget = 1;\necho \$plusTarget;\n");

        $result = (new LspTool($this->clientFor(new SpyLspConnection(connected: false)), $this->root))->execute([
            'operation' => 'references',
            'path' => 'sub/Web+Fetch.php',
            'line' => 1,
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringNotContainsString('No references found', $result->content());
        $this->assertStringContainsString('"line": 1', $result->content());
        $this->assertStringContainsString('"line": 2', $result->content());
    }

    /**
     * THE COORDINATES REACH THE SERVER ON EVERY CALL, NOT ONLY THE FIRST.
     *
     * {@see LspClient} cached on `uri` + method with the POSITION LEFT OUT, so the
     * second question about a file was answered with the first question's answer.
     * MEASURED before the fix, one tool instance, a connected spy, the same file,
     * `references` at 1:9 then at 2:9: the connection was asked ONCE and the two
     * results were byte-identical. `testTheLineAndColumn…` above proves the
     * forwarding on a COLD cache only, which is why that test passed throughout.
     *
     * One assertion covers both directions: a second POSITION must appear in
     * `asked`, and a repeat of the FIRST position must not — so a "fix" that
     * merely stopped caching fails here too.
     */
    public function testASecondPositionInTheSameFileIsAskedWhileARepeatIsServedFromTheCache(): void
    {
        $connection = new SpyLspConnection(references: [['uri' => 'x', 'range' => []]]);
        $tool = new LspTool($this->clientFor($connection), $this->root);
        $args = ['operation' => 'references', 'path' => 'sub/Target.php'];

        $tool->execute($args + ['line' => 1, 'column' => 9]);
        $tool->execute($args + ['line' => 2, 'column' => 9]);
        $tool->execute($args + ['line' => 1, 'column' => 9]);

        $this->assertSame(
            [
                ['references', 'file://' . $this->file, 1, 9],
                ['references', 'file://' . $this->file, 2, 9],
            ],
            $connection->asked,
        );
    }

    // -------------------------------------------------------------------------
    // The two remaining ways an empty answer could still read as a fact
    // -------------------------------------------------------------------------

    /**
     * THE WARNING NAMES EXACTLY THE OPERATIONS THAT HAVE NO FALLBACK, and the
     * list is MEASURED here rather than quoted — an earlier version of this file
     * asserted only up to `text matches, not semantic results`, and a mutation
     * that changed the rest of the sentence to "hover/codeActions fall back the
     * same way" survived. The clause was true; nothing checked it.
     *
     * Fails in both directions now: if the prose stops matching, or if the CODE
     * grows a hover fallback, the measured set and the sentence disagree.
     */
    public function testTheDisconnectedWarningNamesExactlyTheOperationsWithNoFallback(): void
    {
        $this->write('sub/Fallback.php', "<?php\nfunction fbTarget(): void {}\nfbTarget();\n");
        $tool = new LspTool($this->clientFor(new SpyLspConnection(connected: false)), $this->root);

        $answered = [];
        $empty = [];
        $warning = '';
        $references = '';

        foreach ($tool->inputSchema()['properties']['operation']['enum'] as $operation) {
            if ($operation === 'diagnostics') {
                continue; // server-push: it reads a local map, so "fallback" is not a question about it
            }

            $content = $tool->execute([
                'operation' => $operation,
                'path' => 'sub/Fallback.php',
                'line' => 2,
            ])->content();

            $this->assertStringContainsString('registered but NOT connected', $content, $operation);
            $warning = $content;
            if ($operation === 'references') {
                $references = $content;
            }

            if (str_contains($content, 'No ' . $operation . ' found')) {
                $empty[] = $operation;
            } else {
                $answered[] = $operation;
            }
        }

        $this->assertSame(['definition', 'references', 'symbols'], $answered, 'these three have a grep fallback');
        $this->assertSame(['hover', 'codeActions'], $empty);
        $this->assertStringContainsString(implode('/', $empty) . ' have no fallback', $warning);

        // "text matches, not semantic results" is load-bearing, not decoration:
        // the references answer includes the DECLARATION line as well as the call
        // site, which a semantic reference search would not.
        $this->assertStringContainsString('"line": 1', $references);
        $this->assertStringContainsString('"line": 2', $references);
        $this->assertStringContainsString('same-file text search', $references);
    }

    /**
     * THE `symbols` FALLBACK LISTS THE FILE'S DECLARATIONS, which it did not
     * before: `documentSymbol` carries no position, so both `LspClient` callers
     * pass line 0, and the fallback then took the identifier from line 0 —
     * `<?php` — and looked for a declaration of `php`. MEASURED on this tree, a
     * fixture declaring one function and one class returned `[]`, i.e. the
     * success "No symbols found", for every PHP file there is.
     *
     * The cursor is asserted to be IRRELEVANT here rather than merely unused: two
     * different `line` values must give the same answer, because a position-
     * sensitive `documentSymbol` would be the wrong request. TWO CLIENTS, one per
     * probe, and that is not incidental — `documentSymbol` is cached per FILE, so
     * a second call on one client is a cache hit and would have proved the cache
     * rather than the cursor. (Noted because this test's first draft did exactly
     * that: the position-aware key added in this same change made the assertion
     * vacuous.)
     */
    public function testTheSymbolsFallbackEnumeratesDeclarationsAndIgnoresTheCursor(): void
    {
        $this->write(
            'sub/Declared.php',
            "<?php\n\nfinal class Widget\n{\n    public function assemble(): void {}\n}\n",
        );
        $cold = fn (): LspTool => new LspTool(
            $this->clientFor(new SpyLspConnection(connected: false)),
            $this->root,
        );

        $atZero = $cold()->execute(['operation' => 'symbols', 'path' => 'sub/Declared.php', 'line' => 0]);
        $atFour = $cold()->execute(['operation' => 'symbols', 'path' => 'sub/Declared.php', 'line' => 4]);

        $this->assertFalse($atZero->isError());
        $this->assertStringNotContainsString('No symbols found', $atZero->content());
        $this->assertStringContainsString('"name": "Widget"', $atZero->content());
        $this->assertStringContainsString('"name": "assemble"', $atZero->content());

        // LSP SymbolKind: Class = 5, Function = 12. The code this replaced
        // hard-coded 6 and called it Function; 6 is Method.
        $this->assertStringContainsString('"kind": 5', $atZero->content());
        $this->assertStringContainsString('"kind": 12', $atZero->content());

        $this->assertSame(
            substr($atZero->content(), (int) strpos($atZero->content(), '[')),
            substr($atFour->content(), (int) strpos($atFour->content(), '[')),
            'documentSymbol has no position, so the answer must not depend on one',
        );
    }

    /**
     * AN EMPTY `diagnostics` MAP CARRIES ITS OWN CAVEAT, because it is the one
     * operation whose empty answer today means "nobody ever pushed anything"
     * rather than "the server was asked and had nothing". Nothing in `src/` calls
     * `LspClient::handlePublishDiagnostics()` or registers an `onNotification()`
     * subscriber, so with a server configured — which is what the launcher named
     * in `Bootstrap::lspTool()` will do — an unnoted empty map would read as "no
     * problems in this file" for every file in the repo.
     *
     * The pair is what makes it falsifiable: a PUSHED map must NOT carry the
     * caveat (that answer is real), and another operation's empty answer must not
     * carry it either (that server was genuinely asked).
     */
    public function testAnEmptyDiagnosticsMapSaysNothingPushedItRatherThanReadingAsClean(): void
    {
        $tool = new LspTool($this->clientFor(), $this->root);

        $empty = $tool->execute(['operation' => 'diagnostics', 'path' => 'sub/Target.php']);

        $this->assertFalse($empty->isError());
        $this->assertStringContainsString('No diagnostics found', $empty->content());
        $this->assertStringContainsString('nothing in this build subscribes', $empty->content());
        $this->assertStringContainsString('NOT that this file has no problems', $empty->content());

        $otherOperation = $tool->execute(['operation' => 'references', 'path' => 'sub/Target.php']);
        $this->assertStringContainsString('No references found', $otherOperation->content());
        $this->assertStringNotContainsString('nothing in this build subscribes', $otherOperation->content());

        $client = $this->clientFor();
        $client->handlePublishDiagnostics('file://' . $this->file, [['message' => 'unused variable $x']]);
        $pushed = (new LspTool($client, $this->root))->execute([
            'operation' => 'diagnostics',
            'path' => 'sub/Target.php',
        ]);
        $this->assertStringContainsString('unused variable $x', $pushed->content());
        $this->assertStringNotContainsString('nothing in this build subscribes', $pushed->content());
    }

    /**
     * `Lsp` IS CLASSIFIED READ-ONLY BY THE PERMISSION GATE, and the pair is what
     * makes that mean something: `Plan` — the mode for reading a codebase before
     * touching it — must ALLOW it, while a write tool in the same mode must not.
     * Measured before the classification landed: `Plan/Read → Allow`,
     * `Plan/Grep → Allow`, `Plan/Lsp → Ask`, and `DontAsk/Lsp → Deny` outright.
     *
     * (`Crush\ToolCall` rather than a `Tools\` type because that is the gate's own
     * signature — this test drives the GATE, not the tool.)
     *
     * The claim is defensible only because every {@see LspTool::OPERATIONS} entry
     * is a QUERY: `codeActions` returns proposed edits and nothing here applies
     * one, and the mutating half of LSP (rename, formatting, applying an edit) is
     * absent from the tool by construction. So the enum is asserted too — a
     * seventh operation that wrote something would have to revisit this.
     */
    public function testTheGatePermitsLspInPlanModeBecauseEveryOperationIsAQuery(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);

        $this->assertSame(
            PermissionDecision::Allow,
            $gate->evaluate(new ToolCall(name: 'Lsp', arguments: ['operation' => 'references'])),
        );
        $this->assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall(name: 'Write', arguments: ['path' => 'x'])),
            'Plan mode must still refuse a write — otherwise the Allow above proves nothing',
        );

        $this->assertSame(
            ['definition', 'references', 'hover', 'symbols', 'codeActions', 'diagnostics'],
            (new LspTool())->inputSchema()['properties']['operation']['enum'],
            'the read-only classification is a claim about exactly this operation set',
        );
    }

    private function clientFor(?SpyLspConnection $connection = null): LspClient
    {
        return new LspClient($connection ?? new SpyLspConnection(), new ArrayLspCache());
    }
}
