<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\MCP\StdioMcpServer;
use SugarCraft\Code\Support\ProcessContainment;

/**
 * The stdio SEAM suite: what this adapter OWNS, not what the transport does.
 *
 * Phase-2a moved framing, handshake mechanics, stderr absorption and the
 * teardown ladder into `sugarcraft/sugar-mcp-client` — that law is pinned by the
 * library's own suite. What candy-code still owns, and what these rows
 * therefore pin, is exactly the adapter's three product decisions plus the
 * construction contract the rest of the product reads:
 *
 *  - the E672 containment pre-check and the wrapped/scrubbed spawn plan the
 *    library is handed through its `spawnPlanner` seam;
 *  - the `candy-code` client identity that reaches the server's initialize
 *    params;
 *  - the lib-tool -> crush-tool conversion at {@see StdioMcpServer::listTools()};
 *  - the construction surface (name, fallback budget, config pass-through) the
 *    McpClient factory and the docs rosters advertise, and the two
 *    crush-level integrations that ride the adapter: the `McpClient`
 *    fan-out pump and the per-server `startTimeout` from `.mcp.json`.
 */
final class StdioMcpServerTest extends TestCase
{
    /** Seconds a start() that must fail may take before it is a hang. */
    private const BOUND_SECONDS = 6.0;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/sc_stdio_seam_' . \bin2hex(\random_bytes(6));
        \mkdir($this->tempDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tempDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->tempDir);
    }

    // =========================================================================
    // Construction contract (unchanged by the swap, still product surface)
    // =========================================================================

    public function testCanBeCreatedWithAllParameters(): void
    {
        $server = new StdioMcpServer(
            name: 'test-server',
            command: 'echo',
            args: ['hello'],
            env: ['ECHO_VAR' => 'value'],
        );

        $this->assertSame('test-server', $server->name);
    }

    public function testCanBeCreatedWithEmptyArgs(): void
    {
        $server = new StdioMcpServer(
            name: 'no-args-server',
            command: 'ls',
            args: [],
            env: [],
        );

        $this->assertInstanceOf(StdioMcpServer::class, $server);
    }

    public function testCanBeCreatedWithEmptyEnv(): void
    {
        $server = new StdioMcpServer(
            name: 'no-env-server',
            command: 'date',
            args: [],
            env: [],
        );

        $this->assertInstanceOf(StdioMcpServer::class, $server);
    }

    public function testReadonlyNameProperty(): void
    {
        $server = new StdioMcpServer(
            name: 'readonly-test',
            command: 'echo',
            args: [],
            env: [],
        );

        $this->assertSame('readonly-test', $server->name);
    }

    public function testTheAdapterWrapsTheLibraryTransport(): void
    {
        $server = new StdioMcpServer(name: 'delegation', command: 'true', args: [], env: []);

        $this->assertInstanceOf(
            \SugarCraft\McpClient\StdioMcpServer::class,
            $this->transportOf($server),
            'the adapter stopped delegating to the library transport',
        );
    }

    public function testTheDefaultStartTimeoutDerivesFromTheLibraryConstant(): void
    {
        $this->assertSame(
            \SugarCraft\McpClient\StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS,
            StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS,
            'the product default and the transport default forked — the docs state one number',
        );
        $this->assertSame(60.0, StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS);
    }

    public function testAnUnusableStartTimeoutFallsBackToTheDefaultRatherThanOff(): void
    {
        foreach ([null, 0, -5, 'soon', [], true] as $bad) {
            $server = new StdioMcpServer(
                name: 'bad-budget',
                command: 'true',
                args: [],
                env: [],
                startTimeoutSeconds: is_numeric($bad) ? (float) $bad : null,
            );

            $this->assertSame(
                StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS,
                $this->startBudgetOf($server),
                'startTimeout ' . var_export($bad, true) . ' must fall back to the default',
            );
        }
    }

    // =========================================================================
    // The containment seam
    // =========================================================================

    public function testStartThrowsOnInvalidCommand(): void
    {
        $server = new StdioMcpServer(
            name: 'invalid-command',
            command: '/nonexistent/binary/that/does/not/exist',
            args: [],
            env: [],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to start MCP server: invalid-command');

        $server->start();
    }

    public function testTheSpawnPlanIsTheContainmentWrapAndScrubVerbatim(): void
    {
        $server = new StdioMcpServer(
            name: 'planner',
            command: 'php',
            args: ['/somewhere/server.php', '--flag'],
            env: ['MCP_ONLY' => 'wins'],
        );

        $plan = $this->planThrough($server, 'planner', ['php', '/somewhere/server.php', '--flag'], ['MCP_ONLY' => 'wins']);

        $this->assertSame(
            ProcessContainment::spawnSpec(['php', '/somewhere/server.php', '--flag']),
            $plan[0],
            'the planner stopped handing the transport the containment-wrapped argv',
        );
        $this->assertSame(
            ProcessContainment::mcpEnv(['MCP_ONLY' => 'wins']),
            $plan[1],
            'the planner stopped handing the transport the scrubbed environment (0.14-b: mcpEnv, credentials withheld)',
        );

        // C-TOOL-4.h review MINOR-1: the wrapper SHAPE is host-derived — Linux
        // steady state leads with `setsid -w --`, a Darwin box where setsid(1)
        // lacks -w (or is absent entirely) may lead with the PHP helper arm
        // `[PHP_BINARY, -r, <stub>, --]`. Pin what the code actually plans on
        // THIS host instead of hardcoding one shape. Unconditional on purpose:
        // where no detach probed clean the spread collapses to the bare
        // command, so there is no silent skip — deleting the prefix prepend
        // from spawnSpec() reddens right here on every host that does have a
        // real detach prefix.
        $this->assertSame(
            [...ProcessContainment::detachedSpawnPrefix(), 'php', '/somewhere/server.php', '--flag'],
            $plan[0],
            'the spawn plan must lead with exactly the detach prefix this host proved (or with nothing where none probed clean)',
        );

        $this->assertSame('wins', $plan[1]['MCP_ONLY'], 'the configured override must still win');
    }

    public function testThePreCheckRefusesBeforeAnyChildExists(): void
    {
        // The transport-side planner-throws-then-isUp-stays-false plumbing is
        // pinned by the library; what is crush's own is that the ADAPTER's
        // planner raises the containment error text, not the spawn failure's.
        $server = new StdioMcpServer(
            name: 'ghostly',
            command: 'definitely-not-on-any-path-' . \bin2hex(\random_bytes(6)),
            args: [],
            env: [],
        );

        $raised = null;

        try {
            $server->start();
        } catch (\RuntimeException $failure) {
            $raised = $failure->getMessage();
        }

        $this->assertSame('Failed to start MCP server: ghostly', $raised, 'an off-PATH program must fail the E672 pre-check');

        $this->assertFalse($server->isUp());
    }

    public function testStopDoesNotThrowWhenNotStarted(): void
    {
        $server = new StdioMcpServer(name: 'never', command: 'echo', args: [], env: []);

        $server->stop();
        $this->assertFalse($server->isUp());
    }

    public function testStopCanBeCalledMultipleTimes(): void
    {
        $server = new StdioMcpServer(name: 'twice', command: 'echo', args: [], env: []);

        $server->stop();
        $server->stop();
        $this->assertTrue(true, 'reaching here is the assertion');
    }

    public function testListToolsReturnsEmptyArrayWhenNotStarted(): void
    {
        $server = new StdioMcpServer(name: 'dormant', command: 'echo', args: [], env: []);

        $this->assertSame([], $server->listTools());
    }

    public function testCallToolReturnsErrorWhenNotStarted(): void
    {
        $server = new StdioMcpServer(name: 'offline', command: 'echo', args: [], env: []);

        $this->assertSame(['error' => 'Tool call failed'], $server->callTool('ping', []));
    }

    public function testFullLifecycleWithCatCommand(): void
    {
        $server = new StdioMcpServer(
            name: 'cat-test',
            command: 'cat',
            args: [],
            env: [],
            startTimeoutSeconds: 2.0,
        );

        // `cat` echoes the initialize request back instead of answering it.
        // The library's response matcher is STRICT — a frame only settles a
        // request when it parses as a response with the matching id — so the
        // echo is skipped as the non-response it is and the handshake gives
        // up at the budget rather than at the first confusing byte (the
        // pre-swap looser matcher failed fast on the echo; the strict skip is
        // the documented port delta). A small budget is therefore mandatory
        // here, and the throw plus the dormant tool list are pinned
        // together, exactly as the pre-swap suite did.
        $caught = null;
        $tools = null;
        $start = microtime(true);

        try {
            $server->start();
            $tools = $server->listTools();
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            $server->stop();
        }

        $this->assertNotNull(
            $caught,
            'the handshake with `cat` completed, which it cannot: cat has no JSON-RPC in it',
        );
        $this->assertStringContainsString('MCP server', $caught->getMessage());
        $this->assertLessThan(
            self::BOUND_SECONDS,
            microtime(true) - $start,
            'the handshake budget did not bound the give-up on an echoing child',
        );
        $this->assertNull(
            $tools,
            'listTools() ran, so start() no longer throws and the arm above is no longer the '
            . 'one under test — re-derive which outcome is real before relaxing this',
        );
    }

    // =========================================================================
    // Identity + conversion, e2e through a mirroring child
    // =========================================================================

    public function testTheHandshakeAdvertisesTheProductIdentity(): void
    {
        $captured = $this->tempDir . '/initialize.json';
        $server = new StdioMcpServer(
            name: 'mirror',
            command: PHP_BINARY,
            args: [$this->mirrorScript($captured), $captured],
            env: [],
            startTimeoutSeconds: 10.0,
        );

        $server->start();

        try {
            $this->assertFileExists($captured);
            $params = json_decode((string) file_get_contents($captured), true);
            $this->assertSame(
                ['name' => 'candy-code', 'version' => '1.0.0'],
                $params['clientInfo'] ?? null,
                'the adapter stopped injecting the product identity into the handshake',
            );
            $this->assertSame('2024-11-05', $params['protocolVersion'] ?? null);
        } finally {
            $server->stop();
        }
    }

    public function testToolsComeBackAsCrushMcpTools(): void
    {
        $server = new StdioMcpServer(
            name: 'convert',
            command: PHP_BINARY,
            args: [$this->twoToolServerScript()],
            env: [],
            startTimeoutSeconds: 10.0,
        );

        $server->start();

        try {
            $tools = $server->listTools();
            $this->assertCount(2, $tools);
            foreach ($tools as $tool) {
                $this->assertInstanceOf(McpTool::class, $tool, 'the bridge reads crush tools, not library ones');
                $this->assertSame('convert', $tool->serverName);
            }
            $this->assertSame('alpha', $tools[0]->name);
            $this->assertSame('first tool', $tools[0]->description);
            $this->assertSame(['type' => 'object'], $tools[0]->inputSchema);
            $this->assertSame('beta', $tools[1]->name);
        } finally {
            $server->stop();
        }
    }

    // =========================================================================
    // crush-level integrations salvaged from the pre-swap suites
    // =========================================================================

    public function testTheStartTimeoutInTheConfigIsHonoured(): void
    {
        $config = $this->tempDir . '/.mcp.json';
        file_put_contents($config, (string) json_encode(['mcpServers' => ['chatty' => [
            'type' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$this->chattyScript()],
            'startTimeout' => 1,
        ]]]));

        $client = new McpClient($config, unrestricted: true);

        $start = microtime(true);
        $client->startServers();
        $elapsed = microtime(true) - $start;

        try {
            // The server never came up, so it was skipped and advertises nothing.
            $this->assertSame([], $client->listTools());
            $this->assertLessThan(
                self::BOUND_SECONDS,
                $elapsed,
                sprintf('startServers() took %.2fs, so the config budget did not reach the server', $elapsed),
            );
        } finally {
            $client->stopServers();
        }
    }

    public function testTheClientPumpForwardsToEveryStartedStdioServer(): void
    {
        $configPath = $this->tempDir . '/mcp.json';
        file_put_contents($configPath, json_encode([
            'mcpServers' => [
                'pumpy' => [
                    'type' => 'stdio',
                    'command' => PHP_BINARY,
                    'args' => [$this->noiseServerScript($this->tempDir)],
                    'env' => new \stdClass(),
                ],
            ],
        ], JSON_PRETTY_PRINT));

        $client = new McpClient($configPath);
        $client->startServers();

        try {
            // Same pump-until-seen discipline as the pre-swap suite: a fixed
            // sleep reddened a correct forwarder when the child's post-reply
            // write landed late; forwarding is idempotent once the bytes are
            // in the tail, so repeating it is bounded work.
            $servers = (new \ReflectionProperty(McpClient::class, 'servers'))->getValue($client);
            $this->assertCount(1, $servers);
            $server = array_values($servers)[0];
            $this->assertInstanceOf(StdioMcpServer::class, $server);
            $deadline = microtime(true) + 3.0;
            do {
                $client->pumpStderr();
                $tail = $this->tailOfTransport($server);
            } while (!str_contains($tail, 'CLIENT-PUMPED') && microtime(true) < $deadline);

            $this->assertStringContainsString('CLIENT-PUMPED', $tail);
        } finally {
            $client->stopServers();
        }

        // Empty set after stopServers(): the forward loop must simply return.
        $client->pumpStderr();
        $this->assertTrue(true, 'reaching here is the assertion');
    }

    public function testTheSpawnPlanPrefixesInsideTheContainmentWrap(): void
    {
        // C-TOOL-4.e: the jail prefix sits AFTER the detach wrapper and
        // BEFORE the server command — the containment wrap stays the outer
        // shape and the @proc_open site in the library never sees a new
        // token (PREFIX-ONLY law).
        $server = new StdioMcpServer(
            name: 'jailplan',
            command: '/bin/true',
            args: ['--x'],
            env: [],
            spawnPrefix: ['sentinel-jail-bin', '--ro', '--'],
        );

        $plan = $this->planThrough($server, 'jailplan', ['/bin/true', '--x'], []);
        $detach = count(ProcessContainment::detachedSpawnPrefix());

        $this->assertSame(
            ['sentinel-jail-bin', '--ro', '--', '/bin/true', '--x'],
            array_slice($plan[0], $detach),
            'the jail prefix must lead the command, right after the detach wrapper',
        );
    }

    public function testThePreCheckStillGatesTheServerBinaryUnderAPrefix(): void
    {
        // If the pre-check ever read the prefix head instead of argv[0], the
        // on-PATH sentinel would pass and this would NOT raise — the bogus
        // binary behind it is what must fail.
        $binary = 'definitely-not-on-any-path-' . \bin2hex(\random_bytes(6));
        $server = new StdioMcpServer(
            name: 'jailpre',
            command: $binary,
            args: [],
            env: [],
            spawnPrefix: ['/bin/echo'],
        );

        $raised = null;
        try {
            $this->planThrough($server, 'jailpre', [$binary], []);
        } catch (\RuntimeException $failure) {
            $raised = $failure->getMessage();
        }

        $this->assertSame('Failed to start MCP server: jailpre', $raised);
    }

    // =========================================================================
    // Fixtures and helpers — names unique tree-wide (helper-drift discipline)
    // =========================================================================

    /** @return array{0: string|list<string>, 1: ?array<string,string>} */
    private function planThrough(StdioMcpServer $server, string $name, array $argv, array $env): array
    {
        $method = new \ReflectionMethod($server, 'spawnPlan');
        $method->setAccessible(true);

        /** @var array{0: string|list<string>, 1: ?array<string,string>} $plan */
        $plan = $method->invoke($server, $name, $argv, $env);

        return $plan;
    }

    private function transportOf(StdioMcpServer $server): \SugarCraft\McpClient\StdioMcpServer
    {
        $property = new \ReflectionProperty($server, 'transport');
        $property->setAccessible(true);

        /** @var \SugarCraft\McpClient\StdioMcpServer */
        return $property->getValue($server);
    }

    private function startBudgetOf(StdioMcpServer $server): float
    {
        $property = new \ReflectionProperty($this->transportOf($server), 'startTimeoutSeconds');
        $property->setAccessible(true);

        return (float) $property->getValue($this->transportOf($server));
    }

    private function tailOfTransport(StdioMcpServer $server): string
    {
        $property = new \ReflectionProperty($this->transportOf($server), 'stderrTail');
        $property->setAccessible(true);

        return (string) $property->getValue($this->transportOf($server));
    }

    /**
     * A well-behaved MCP child that persists the initialize PARAMS to
     * $captured before answering, so the handshake identity can be read from
     * the server's side of the wire. Bounded by its own deadline arithmetic —
     * no bare long sleep here on purpose (fixture-lifetime census).
     */
    private function mirrorScript(string $captured): string
    {
        $path = $this->tempDir . '/mirror-' . substr(md5($captured), 0, 8) . '.php';
        file_put_contents($path, <<<PHP
            <?php
            \$captured = \$argv[1];
            \$deadline = microtime(true) + 30.0;
            stream_set_blocking(STDIN, false);
            while (!feof(STDIN)) {
                if (microtime(true) >= \$deadline) {
                    exit(0);
                }
                \$line = fgets(STDIN);
                if (\$line === false) {
                    usleep(10000);
                    continue;
                }
                \$msg = json_decode(\$line, true);
                if (!is_array(\$msg) || !isset(\$msg['id'])) {
                    continue;
                }
                if ((string) (\$msg['method'] ?? '') === 'initialize') {
                    file_put_contents(\$captured, json_encode(\$msg['params'] ?? []));
                }
                \$result = match ((string) (\$msg['method'] ?? '')) {
                    'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()],
                    'tools/list' => ['tools' => []],
                    default => ['content' => [['type' => 'text', 'text' => 'ok']]],
                };
                echo json_encode(['jsonrpc' => '2.0', 'id' => (string) \$msg['id'], 'result' => \$result]), "\n";
                flush();
            }
            PHP);

        return $path;
    }

    /** Two valid tools; also proves the conversion keeps ORDER and count. */
    private function twoToolServerScript(): string
    {
        $path = $this->tempDir . '/twotool.php';
        file_put_contents($path, <<<'PHP'
            <?php
            $deadline = microtime(true) + 30.0;
            while (($line = fgets(STDIN)) !== false) {
                if (microtime(true) >= $deadline) {
                    exit(0);
                }
                $msg = json_decode($line, true);
                if (!is_array($msg) || !isset($msg['id'])) {
                    continue;
                }
                $result = match ((string) ($msg['method'] ?? '')) {
                    'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()],
                    'tools/list' => ['tools' => [
                        ['name' => 'alpha', 'description' => 'first tool', 'inputSchema' => ['type' => 'object']],
                        ['name' => 'beta', 'description' => 'second tool', 'inputSchema' => []],
                    ]],
                    default => ['content' => [['type' => 'text', 'text' => 'pong']]],
                };
                echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
                flush();
            }
            PHP);

        return $path;
    }

    /** Streams JSON-RPC notifications forever and never answers. */
    private function chattyScript(): string
    {
        $path = $this->tempDir . '/chatty.php';
        file_put_contents($path, <<<'PHP'
            <?php
            while (true) {
                echo json_encode([
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/progress',
                    'params' => ['progress' => 1],
                ]), "\n";
                flush();
                usleep(1000);
            }
            PHP);

        return $path;
    }

    /** Handshake-perfect child that writes to stderr AFTER its final reply. */
    private function noiseServerScript(string $dir): string
    {
        $path = $dir . '/noisy.php';
        file_put_contents($path, <<<'PHP'
            <?php
            $noised = false;
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line, true);
                if (!is_array($msg) || !isset($msg['id'])) {
                    continue;
                }
                $method = (string) ($msg['method'] ?? '');
                $result = match ($method) {
                    'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()],
                    'tools/list' => ['tools' => [[
                        'name' => 'ping',
                        'description' => 'Answer with pong.',
                        'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                    ]]],
                    default => ['content' => [['type' => 'text', 'text' => 'pong']]],
                };
                echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
                flush();
                if ($method === 'tools/list' && !$noised) {
                    $noised = true;
                    usleep(50000);
                    fwrite(STDERR, 'CLIENT-PUMPED');
                }
            }
            PHP);

        return $path;
    }
}
