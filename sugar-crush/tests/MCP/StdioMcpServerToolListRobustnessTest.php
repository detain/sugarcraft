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
            . '"not even an object"]',
        );

        try {
            $server->start();
            $names = array_map(static fn (McpTool $t): string => $t->name, $server->listTools());
            sort($names);

            $this->assertSame(
                ['grep', 'read'],
                $names,
                'the filter kept the wrong set. `grep` is fully specified and `read` omits the '
                . 'optional keys, which fromArray() supplies typed defaults for; the other three '
                . 'each break a different one of the three checks',
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
