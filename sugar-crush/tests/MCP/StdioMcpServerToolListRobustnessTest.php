<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\HttpMcpServer;
use SugarCraft\Crush\MCP\McpTool;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * ONE MISTYPED TOOL IN A `tools/list` REPLY MUST NOT TAKE THE SESSION'S WHOLE
 * MCP SUBSYSTEM DOWN — AND IT DID.
 *
 * {@see StdioMcpServer::parseTools()} filtered `is_array($def)` and nothing
 * else, then handed the entry to {@see McpTool::fromArray()}, which reads
 * `$data['name'] ?? ''` into a `string` parameter. So a reply of
 * `{"tools":[{"name":5}]}` — a well-formed JSON-RPC message carrying a
 * well-formed MCP envelope — raises a `TypeError`. And a `TypeError` is not a
 * `RuntimeException`, which is the only class
 * {@see \SugarCraft\Crush\MCP\McpClient::startServer()} catches, under a comment
 * promising that "a single unreachable/misbehaving server must not abort loading
 * the rest".
 *
 * MEASURED END TO END on this host (PHP 8.3.6, Linux 6.8), three consecutive
 * takes, identical every time, driving `McpClient::startServers()` over a
 * two-server config the way {@see \SugarCraft\Crush\Cli\Bootstrap::mcpClient()}
 * does — one server answering `{"tools":[{"name":5}]}`, one answering correctly:
 *
 *     startServers() THREW TypeError ... Argument #1 ($name) must be of type
 *                    string, int given
 *     -> the well-formed server was never started
 *
 * WHY THAT MATTERS MORE THAN A MALFORMED TOOL USUALLY WOULD: `.mcp.json` is
 * cloned content, which is why starting a server from it is gated behind a
 * per-user trust grant at all. The reply is somebody else's bytes.
 *
 * ⚠️ WHAT THIS FILE DOES NOT CLOSE — REWRITTEN, BECAUSE IT NOW CLOSES LESS AND
 * MORE OF IT IS CLOSED ELSEWHERE.
 * WHAT THIS SAID: "This closes the route through this server type;
 * {@see \SugarCraft\Crush\MCP\HttpMcpServer} carries a character-identical
 * `parseTools()` with the same gap, and any future throw from a third party's
 * output still walks through `catch (\RuntimeException)`. Both of those files
 * are outside this lane."
 * WHAT IS TRUE NOW: both halves are closed, and neither is closed HERE. The type
 * filter moved onto {@see McpTool::tryFromArray()} so that the stdio and HTTP
 * servers share ONE mirror of `fromArray()`'s subscripts rather than two, and
 * {@see \SugarCraft\Crush\MCP\McpClient::startServer()} now catches
 * `\Throwable` with the `match` inside the guard. The whole-family behaviour —
 * including a third route nobody had named, an unknown `type` throwing from
 * OUTSIDE the old try — is pinned in
 * {@see \SugarCraft\Crush\Tests\MCP\McpClientServerIsolationTest}.
 * WHY THIS FILE STILL EARNS ITS PLACE: it is the only place that drives a REAL
 * child process through the stdio handshake with a hand-built `tools/list`
 * reply. The isolation file uses a MockHandler-backed HTTP client, so it proves
 * the client's behaviour and not the framing's.
 */
final class StdioMcpServerToolListRobustnessTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_stdio_toollist_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    /**
     * THE KNOWN-POSITIVE CONTROL, first, because every row below asserts that
     * something did NOT throw.
     *
     * {@see McpTool::fromArray()} is deliberately left strict — its promoted
     * properties are the contract every consumer of a tool list reads, and
     * widening them to survive a bad server would push the same `TypeError` out
     * to whichever consumer touched it first. This row requires that strictness
     * to still be real, so the rows below are about the FILTER and not about
     * `McpTool` having quietly become lenient.
     */
    public function testMcpToolIsStillStrictSoTheFilterIsWhatIsBeingTested(): void
    {
        foreach ([
            'name as int' => ['name' => 5],
            'name as array' => ['name' => ['a']],
            'name as bool' => ['name' => true],
            'description as bool' => ['name' => 'a', 'description' => false],
            'inputSchema as string' => ['name' => 'a', 'inputSchema' => 'nope'],
        ] as $label => $def) {
            try {
                McpTool::fromArray($def, 's');
                $this->fail("{$label} no longer raises out of McpTool::fromArray(); if that is deliberate, this whole file needs rewriting rather than relaxing");
            } catch (\TypeError) {
                $this->addToAssertionCount(1);
            }
        }

        // And the other polarity of the control: a well-formed entry, and an
        // entry that merely OMITS the optional keys, both construct — otherwise
        // "it throws" would be true of everything and the filter below could
        // legitimately reject the lot.
        $this->assertSame('a', McpTool::fromArray(['name' => 'a', 'description' => 'd', 'inputSchema' => []], 's')->name);
        $this->assertSame('', McpTool::fromArray(['name' => ''], 's')->description);
    }

    /**
     * THE HEADLINE. A server whose `tools/list` carries a mistyped entry STARTS,
     * and start() does not raise.
     *
     * ⚠️ ONLY `start()` IS INSIDE THE `catch (\Throwable)`, AND THAT IS A FIX
     * RATHER THAN A STYLE. PHPUnit reports a failed assertion by THROWING, so an
     * assertion inside that block is caught by it and re-reported under the
     * "start() raised ... over a mistyped tool entry" banner — which names a
     * defect that did not happen and sends the reader to `parseTools()` instead
     * of to the assertion that actually failed. MEASURED: mutating the harness to
     * answer an empty list for every input reds this row through the control
     * below, and with the assertions inside the try the failure text said
     * `start() raised PHPUnit\Framework\ExpectationFailedException`.
     *
     * THE CONTROL RUNS FIRST, and it is in this row rather than the sibling one.
     * `assertSame([], ...)` is satisfied by a fixture that never answered, by a
     * `serverAnswering()` that spawns nothing, and by a `listTools()` that always
     * returns `[]`. {@see testAWellFormedToolListIsUnaffectedByTheFilter()} covers
     * that for the FILE; it does not cover it for this ROW, and a reader deleting
     * that method would take this row's only positive component with it.
     */
    public function testAMistypedToolEntryDoesNotAbortTheServerLaunch(): void
    {
        $control = $this->serverAnswering('[{"name":"grep","description":"d","inputSchema":{}}]');

        try {
            $control->start();
            $this->assertNotSame(
                [],
                $control->listTools(),
                'the harness produces nothing for a WELL-FORMED list either, so the empty result '
                . 'below is not evidence that the mistyped entry was filtered',
            );
        } finally {
            $control->stop();
        }

        $server = $this->serverAnswering('[{"name":5}]');

        try {
            try {
                $server->start();
            } catch (\Throwable $e) {
                $this->fail(
                    'start() raised ' . get_class($e) . ' over a mistyped tool entry: ' . $e->getMessage()
                    . ' — McpClient::startServer() catches only RuntimeException, so this aborts every '
                    . 'OTHER server in the config too',
                );
            }

            $this->assertSame([], $server->listTools(), 'the mistyped entry was kept, so something downstream will meet it instead');
        } finally {
            $server->stop();
        }
    }

    /**
     * AND THE GOOD ONES SURVIVE THE BAD ONE, which is the claim the skip-rather-
     * than-throw shape is actually making. Without this row, a `parseTools()`
     * that returned `[]` for any list containing a bad entry would pass
     * everything above.
     */
    public function testAMalformedEntryIsSkippedAndItsWellFormedNeighboursAreNot(): void
    {
        $server = $this->serverAnswering(
            '[{"name":5},'
            . '{"name":"grep","description":"search","inputSchema":{"type":"object"}},'
            . '{"name":"edit","description":false},'
            . '{"name":"read"},'
            . '{"name":"write","description":null,"inputSchema":null},'
            . '"not even an object"]',
        );

        try {
            $server->start();
            $names = array_map(static fn (McpTool $t): string => $t->name, $server->listTools());
            sort($names);

            $this->assertSame(
                ['grep', 'read', 'write'],
                $names,
                'the filter kept the wrong set. `grep` is fully specified, `read` OMITS the '
                . 'optional keys and `write` sends them as explicit nulls — all three of which '
                . 'fromArray() supplies typed defaults for; the other three each break a '
                . 'different one of the three checks',
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * THE CONTROL FOR THE PAIR ABOVE: an entirely well-formed list comes through
     * untouched, with its fields intact rather than merely counted.
     */
    public function testAWellFormedToolListIsUnaffectedByTheFilter(): void
    {
        $server = $this->serverAnswering('[{"name":"grep","description":"search","inputSchema":{"type":"object"}}]');

        try {
            $server->start();
            $tools = $server->listTools();

            $this->assertCount(1, $tools);
            $this->assertSame('grep', $tools[0]->name);
            $this->assertSame('search', $tools[0]->description);
            $this->assertSame(['type' => 'object'], $tools[0]->inputSchema);
        } finally {
            $server->stop();
        }
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * THE FILTER IS A HAND MIRROR OF A CLASS IT DOES NOT OWN, AND DRIFT REOPENS
     * THE DEFECT SILENTLY.
     *
     * {@see McpTool::TOOL_DEFINITION_TYPES} lists three keys with three
     * checks. Those three are exactly what {@see McpTool::fromArray()} subscripts
     * out of `$data` today, and the checks match the constructor parameters they
     * land in. Nothing enforced that. A fourth `$data['newField'] ?? ...` reading
     * into a typed parameter reopens precisely the `TypeError` this file exists
     * to close, and every row above it would stay green — they exercise the three
     * keys that already work.
     *
     * So the correspondence is DERIVED here rather than restated: the keys come
     * out of `fromArray()`'s own source, the expected checks out of
     * {@see McpTool}'s constructor via reflection, and the const is required to
     * agree with both in both directions — a missing key is drift, and a stale
     * extra key is drift too.
     *
     * ⚠️ THE FIXTURE IS THE POINT. This row's central assertion is "two sets are
     * equal", which a scanner that has stopped finding anything satisfies as
     * happily as a correct one — an empty set equals an empty set. The first
     * block therefore pushes a KNOWN-ANSWER source through the same extractor and
     * requires it to return a specific non-empty set, including a key that is NOT
     * in the const. If the extractor dies, that block reds before the comparison
     * is ever reached.
     *
     * ⚠️ AND IT REFUSES WHAT IT CANNOT CLASSIFY. A constructor parameter whose
     * type is not in the small map below FAILS the row rather than being skipped.
     * A guard that quietly ignores the unparseable has a hole shaped exactly like
     * the next defect.
     */
    /**
     * THE CONTAINER IS A THIRD SHAPE, AND `?? []` DOES NOT COVER IT.
     *
     * Both `parseTools()` implementations read `$response['result']['tools'] ??
     * []`. That default fires when `tools` is ABSENT or null — not when it is
     * PRESENT and a scalar. A peer sending `{"result":{"tools":"nope"}}` handed
     * `foreach` a string, which on this host (PHP 8.3.6) is
     * `Warning: foreach() argument must be of type array|object, string given`
     * and zero iterations. Not an escape, because zero iterations is also the
     * right ANSWER — but a warning on a third party's malformed reply is noise
     * the operator cannot act on, and `failOnWarning="true"` makes it a red
     * suite the moment any row drives it.
     *
     * ⚠️ THE KNOWN-POSITIVE IS THE WARNING ITSELF (rule 25). Asserting "zero
     * tools" alone is satisfied by the unguarded code, by the guarded code, and
     * by a `parseTools()` deleted outright. So the row re-derives what the
     * UNGUARDED `foreach` does to a string before asserting that the guard stops
     * it — otherwise the expectation is exactly what a dead instrument returns.
     *
     * @dataProvider scalarContainers
     */
    public function testAScalarWhereTheToolListBelongsIsEmptyRatherThanAWarning(mixed $container): void
    {
        // ---- known-positive: the unguarded arithmetic, in isolation.
        $raised = null;
        set_error_handler(static function (int $no, string $msg) use (&$raised): bool {
            $raised = $msg;

            return true;
        });

        try {
            /** @phpstan-ignore-next-line foreach.nonIterable - the warning IS the observation */
            foreach ($container as $ignored) {
                // deliberately empty: the WARNING is what this block measures
            }
        } finally {
            restore_error_handler();
        }

        if (is_array($container)) {
            $this->assertNull($raised, 'an array container is not the shape under test');
        } else {
            $this->assertNotNull(
                $raised,
                'foreach over a non-array stopped warning on this PHP, so the guard below is '
                . 'protecting against nothing and this row should be re-derived, not deleted',
            );
            $this->assertStringContainsString('foreach()', (string) $raised);
        }

        // ---- the guard, in both classes that carry it.
        foreach ([StdioMcpServer::class, HttpMcpServer::class] as $class) {
            $server = $class === StdioMcpServer::class
                ? new StdioMcpServer('probe', PHP_BINARY, [], [])
                : new HttpMcpServer('probe', 'http://127.0.0.1:1/mcp', [], new Client());

            $parse = new \ReflectionMethod($class, 'parseTools');

            $quiet = null;
            set_error_handler(static function (int $no, string $msg) use (&$quiet): bool {
                $quiet = $msg;

                return true;
            });

            try {
                /** @var array<mixed> $tools */
                $tools = $parse->invoke($server, ['result' => ['tools' => $container]]);
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $tools, $class . '::parseTools() invented tools from a scalar');
            $this->assertNull(
                $quiet,
                $class . '::parseTools() still warns on a scalar tool list: ' . (string) $quiet,
            );
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function scalarContainers(): iterable
    {
        yield 'a string' => ['nope'];
        yield 'an int' => [7];
        yield 'a bool' => [true];
        yield 'an array (the control)' => [[]];
    }

    public function testTheTypeFilterStillMirrorsEveryKeyMcpToolReads(): void
    {
        // ---- The known-answer control, first, because everything after it is a
        // comparison that a dead extractor would satisfy.
        $fixture = <<<'PHP'
            public static function fromArray(array $data, string $serverName): self
            {
                return new self(
                    alpha: $data['alpha'] ?? '',
                    beta: $data['beta'] ?? [],
                    gamma: $data['gamma'] ?? 0,
                    serverName: $serverName,
                );
            }
            PHP;

        $this->assertSame(
            ['alpha', 'beta', 'gamma'],
            self::dataSubscriptsIn($fixture),
            'the extractor cannot read $data[...] subscripts out of a source it was handed, so '
            . 'the comparison below would be two empty sets agreeing with each other',
        );
        $this->assertNotContains(
            'gamma',
            array_keys($this->toolDefinitionTypes()),
            'the control fixture has to contain a key the const does NOT, or "the extractor '
            . 'found something" is indistinguishable from "the extractor echoed the const"',
        );

        // ---- The real comparison.
        $reader = new \ReflectionMethod(McpTool::class, 'fromArray');
        $source = (string) file_get_contents((string) $reader->getFileName());
        $lines = explode("\n", $source);
        $body = implode("\n", array_slice(
            $lines,
            $reader->getStartLine() - 1,
            $reader->getEndLine() - $reader->getStartLine() + 1,
        ));

        $read = self::dataSubscriptsIn($body);
        $this->assertNotSame([], $read, 'no $data subscripts found in fromArray() at all');

        $filter = $this->toolDefinitionTypes();

        $this->assertSame(
            $read,
            array_keys($filter),
            'the type filter and McpTool::fromArray() no longer read the same keys. A key '
            . 'fromArray() reads and the filter does not is a reopened TypeError on a peer\'s '
            . 'reply — add it with the check matching its constructor parameter. A key the '
            . 'filter carries and fromArray() no longer reads is a stale row — drop it.',
        );

        $expected = [];
        foreach ((new \ReflectionClass(McpTool::class))->getConstructor()?->getParameters() ?? [] as $param) {
            if (!in_array($param->getName(), $read, true)) {
                continue;   // supplied by fromArray()'s own arguments, not by $data
            }
            $type = $param->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $checks = ['string' => 'is_string', 'array' => 'is_array', 'int' => 'is_int', 'bool' => 'is_bool', 'float' => 'is_float'];

            $this->assertArrayHasKey(
                $name,
                $checks,
                "McpTool::\${$param->getName()} is typed `{$name}`, which this row does not know how "
                . 'to check. Add it to the map here — do NOT relax the assertion, and do NOT widen '
                . "McpTool's property types to make it go away, which would close this row by "
                . 'reopening the defect.',
            );
            $expected[$param->getName()] = $checks[$name];
        }

        $this->assertSame(
            $expected,
            $filter,
            'a key is checked with the wrong predicate, so a value of the wrong type still '
            . 'reaches the constructor and still raises the TypeError',
        );
    }

    /** @return array<string, string> */
    private function toolDefinitionTypes(): array
    {
        /** @var array<string, string> $types */
        $types = (new \ReflectionClass(McpTool::class))->getConstant('TOOL_DEFINITION_TYPES');

        return $types;
    }

    /**
     * Every `$data['key']` subscript in a PHP source fragment, in order, deduped.
     *
     * @return list<string>
     */
    private static function dataSubscriptsIn(string $php): array
    {
        preg_match_all('/\$data\[\x27([A-Za-z_][A-Za-z0-9_]*)\x27\]/', $php, $m);

        return array_values(array_unique($m[1]));
    }

    private function serverAnswering(string $toolsJson): StdioMcpServer
    {
        $script = $this->tempDir . '/tools_' . md5($toolsJson) . '.php';
        file_put_contents($script, sprintf(self::TOOL_LISTING_SERVER, $toolsJson));

        return new StdioMcpServer(
            name: 'toollist',
            command: PHP_BINARY,
            args: [$script],
            env: [],
            startTimeoutSeconds: 5.0,
        );
    }

    /**
     * Answers `initialize` with an object and `tools/list` with whatever literal
     * the caller supplies, so the fixture can produce replies no factory in this
     * tree would build.
     */
    private const TOOL_LISTING_SERVER = <<<'PHP'
        <?php
        $in = fopen('php://stdin', 'rb');
        while (($line = fgets($in)) !== false) {
            $message = json_decode(trim($line), true);
            if (!is_array($message) || !isset($message['id'])) {
                continue;
            }
            $result = ($message['method'] ?? '') === 'tools/list'
                ? '{"tools":%s}'
                : '{"protocolVersion":"2024-11-05"}';
            fwrite(STDOUT, '{"jsonrpc":"2.0","id":"' . $message['id'] . '","result":' . $result . '}' . "\n");
            fflush(STDOUT);
        }
        PHP;
}
