<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
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
 * ⚠️ WHAT THIS FILE DOES NOT CLOSE, said plainly because the finding is bigger
 * than the fix. E436 is the NARROW CATCH itself. This closes the route through
 * this server type; {@see \SugarCraft\Crush\MCP\HttpMcpServer} carries a
 * character-identical `parseTools()` with the same gap, and any future throw
 * from a third party's output still walks through `catch (\RuntimeException)`.
 * Both of those files are outside this lane and are reported rather than
 * reached for.
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
     */
    public function testAMistypedToolEntryDoesNotAbortTheServerLaunch(): void
    {
        $server = $this->serverAnswering('[{"name":5}]');

        try {
            $server->start();

            // THE CONTROL, IN THIS ROW. `assertSame([], ...)` below is satisfied
            // by a fixture that never answered at all, by a `serverAnswering()`
            // that spawns nothing, and by a `listTools()` that always returns
            // `[]`. The sibling row that covers this covers the FILE, not this
            // row. So the same helper is driven with a well-formed list first and
            // required to produce something.
            $control = $this->serverAnswering('[{"name":"grep","description":"d","inputSchema":{}}]');

            try {
                $control->start();
                $this->assertNotSame(
                    [],
                    $control->listTools(),
                    'the harness produces nothing for a WELL-FORMED list either, so the empty '
                    . 'result below is not evidence that the mistyped entry was filtered',
                );
            } finally {
                $control->stop();
            }

            $this->assertSame([], $server->listTools(), 'the mistyped entry was kept, so something downstream will meet it instead');
        } catch (\Throwable $e) {
            $this->fail(
                'start() raised ' . get_class($e) . ' over a mistyped tool entry: ' . $e->getMessage()
                . ' — McpClient::startServer() catches only RuntimeException, so this aborts every '
                . 'OTHER server in the config too',
            );
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
     * {@see StdioMcpServer::TOOL_DEFINITION_TYPES} lists three keys with three
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
        $types = (new \ReflectionClass(StdioMcpServer::class))->getConstant('TOOL_DEFINITION_TYPES');

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
