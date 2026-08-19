<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * {@see StdioMcpServer::start()} must COMPLETE OR GIVE UP, and this is the file
 * that says so in wall-clock terms.
 *
 * `start()` makes TWO blocking exchanges — `initialize` and `tools/list` — and
 * had no bound of any kind on either. That was latent while nothing in `src/`
 * constructed an {@see McpClient}; {@see \SugarCraft\Crush\Cli\Bootstrap::mcpClient()}
 * makes it a LAUNCH hang, on the path every session and every Ctrl+P provider
 * switch reaches, before the TUI has drawn anything.
 *
 * THREE FAILURE SHAPES, NOT TWO, and the third is why a per-read timeout would
 * not have been enough:
 *
 *  - DEAD — the pipe closes. Already handled: `readLine()` sees EOF.
 *  - SLOW/SILENT — the process lives, holds the pipe open and writes nothing.
 *  - LIVE, CHATTY, NEVER ANSWERING — it emits perfectly valid JSON-RPC
 *    NOTIFICATIONS in a loop. `readResponse()` skips notifications, so every
 *    individual read returns PROMPTLY and no per-read timeout ever fires while
 *    the loop never terminates.
 *
 * Both of the last two are measured below against a WALL CLOCK shared by the
 * whole handshake, which is the only instrument that sees the third shape.
 *
 * THE FIXTURES ARE SCRIPTS THIS TEST WRITES, not installed servers: nothing here
 * needs `npx`, a node runtime or a network peer, so the suite cannot be green
 * only on machines that happen to have one. Same house style as the rest of
 * `tests/MCP/`.
 *
 * THE BUDGETS HERE ARE DELIBERATELY TINY (1s), and the shipped default is
 * {@see StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS} — 60s, sized for a cold
 * `npx` fetch. A test may not pay that: `phpunit.xml`'s `defaultTimeLimit` is
 * 60s, so a test that actually waited out the default budget would be recorded
 * RISKY and (with `failOnRisky`) red. So these pass the budget explicitly, which
 * is also what proves the parameter is honoured at all.
 */
final class StdioMcpServerHandshakeTest extends TestCase
{
    /**
     * Comfortably above the 1s budget the tests below set and far below the
     * unbounded block they replace, so neither a loaded box nor a regression is
     * ambiguous. A regression does not merely exceed this — it never returns, and
     * `defaultTimeLimit` is what then reports it.
     */
    private const BOUND_SECONDS = 6.0;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_mcp_handshake_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
        file_put_contents($this->tempDir . '/chatty.php', self::CHATTY_SERVER);
        file_put_contents($this->tempDir . '/silent.php', self::SILENT_SERVER);
        file_put_contents($this->tempDir . '/prompt.php', self::PROMPT_SERVER);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    /**
     * THE SHAPE NO PER-READ TIMEOUT CAN SEE. Measured before the deadline
     * existed, against this very fixture: `timeout 15 php probe.php` returned
     * rc=124 — `start()` never returned at all.
     */
    public function testAServerThatEmitsNotificationsForeverIsGivenUpOn(): void
    {
        $elapsed = $this->timeStartOf('chatty.php', 1.0);

        $this->assertLessThan(
            self::BOUND_SECONDS,
            $elapsed,
            sprintf('a chatty never-answering server held the launch for %.2fs', $elapsed),
        );
    }

    /** The silent case: alive, pipe open, nothing written, ever. */
    public function testAServerThatNeverWritesAnythingIsGivenUpOn(): void
    {
        $elapsed = $this->timeStartOf('silent.php', 1.0);

        $this->assertLessThan(
            self::BOUND_SECONDS,
            $elapsed,
            sprintf('a silent server held the launch for %.2fs', $elapsed),
        );
    }

    /**
     * THE CONTROL, and without it the two assertions above are satisfied by a
     * `start()` that simply always fails: a server that answers both halves of
     * the handshake comes up, reports its tool, and pays none of the budget.
     */
    public function testAPromptServerCompletesTheHandshakeWellInsideTheBudget(): void
    {
        $server = new StdioMcpServer(
            name: 'prompt',
            command: PHP_BINARY,
            args: [$this->tempDir . '/prompt.php'],
            env: [],
            startTimeoutSeconds: 1.0,
        );

        $start = microtime(true);
        $server->start();
        $elapsed = microtime(true) - $start;

        try {
            $tools = $server->listTools();
            $this->assertCount(1, $tools);
            $this->assertSame('ping', $tools[0]->name);
            $this->assertLessThan(
                0.5,
                $elapsed,
                sprintf('a well-behaved server must not pay the budget; took %.2fs', $elapsed),
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * ...and it still completes when the budget is the SHIPPED default rather than
     * a test-supplied one, so the fast path is not an artefact of these fixtures
     * passing a number.
     */
    public function testThePromptServerAlsoComesUpUnderTheDefaultBudget(): void
    {
        $server = new StdioMcpServer(
            name: 'prompt-default',
            command: PHP_BINARY,
            args: [$this->tempDir . '/prompt.php'],
            env: [],
        );

        $server->start();

        try {
            $this->assertCount(1, $server->listTools());
        } finally {
            $server->stop();
        }
    }

    /**
     * The budget is PER SERVER and comes out of `.mcp.json`, because "how long may
     * this one take to come up" is a property of the server: a local binary
     * answers in milliseconds while a cold `npx -y @modelcontextprotocol/server-…`
     * has a package tree to fetch first.
     *
     * Driven through {@see McpClient::startServers()} — the real config reader —
     * rather than the constructor, because the wiring from the config KEY to the
     * constructor argument is the part that can silently not exist.
     */
    public function testTheStartTimeoutInTheConfigIsHonoured(): void
    {
        $config = $this->tempDir . '/.mcp.json';
        file_put_contents($config, (string) json_encode(['mcpServers' => ['chatty' => [
            'type' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$this->tempDir . '/chatty.php'],
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

    /**
     * A `startTimeout` that is not a usable number falls back to the DEFAULT
     * rather than to "no bound at all" — a hand-edited config must not be able to
     * switch the guard off by typo. Asserted on the constructed value, because
     * asserting it by waiting would mean waiting out the 60s default.
     */
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

            $property = new \ReflectionProperty($server, 'startTimeoutSeconds');
            $property->setAccessible(true);

            $this->assertSame(
                StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS,
                $property->getValue($server),
                'startTimeout ' . var_export($bad, true) . ' must fall back to the default',
            );
        }
    }

    /**
     * The default's DOMAIN, pinned so the number cannot drift away from the reason
     * it was chosen: it bounds the HANDSHAKE of one stdio MCP server, sized for a
     * first-run `npx` package fetch (reported at 30–60s), and it is not a bound on
     * a `tools/call` — see {@see StdioMcpServer::callTool()}, which is deliberately
     * unbounded because a tool call is somebody else's work and may legitimately
     * take minutes.
     */
    public function testTheDefaultBudgetIsSixtySecondsForTheHandshakeOnly(): void
    {
        $this->assertSame(60.0, StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS);
    }

    // =========================================================================
    // Fixtures and helpers
    // =========================================================================

    /** Seconds spent in a `start()` that must fail rather than block. */
    private function timeStartOf(string $script, float $budget): float
    {
        $server = new StdioMcpServer(
            name: 'probe',
            command: PHP_BINARY,
            args: [$this->tempDir . '/' . $script],
            env: [],
            startTimeoutSeconds: $budget,
        );

        $start = microtime(true);
        try {
            $server->start();
            $this->fail('start() must not report success for a server that never answered');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to start MCP server: probe', $e->getMessage());
        } finally {
            $elapsed = microtime(true) - $start;
            $server->stop();
        }

        return $elapsed;
    }

    /**
     * Valid JSON-RPC forever, and never a response. `readResponse()` skips
     * notifications, so this starves it while every single read succeeds.
     */
    private const CHATTY_SERVER = <<<'PHP'
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
        PHP;

    /** Reads its stdin, holds the pipe open, answers nothing. */
    private const SILENT_SERVER = <<<'PHP'
        <?php
        while (($line = fgets(STDIN)) !== false) {
        }
        sleep(3600);
        PHP;

    /** Answers both halves of the handshake immediately. */
    private const PROMPT_SERVER = <<<'PHP'
        <?php
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $result = match ((string) ($msg['method'] ?? '')) {
                'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()],
                'tools/list' => ['tools' => [[
                    'name' => 'ping',
                    'description' => 'Answer with pong.',
                    'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                ]]],
                default => null,
            };
            echo json_encode($result === null
                ? ['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'error' => ['code' => -32601, 'message' => 'unknown']]
                : ['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
            flush();
        }
        PHP;

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
