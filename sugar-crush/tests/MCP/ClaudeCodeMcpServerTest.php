<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\MCP\ClaudeCodeMcpServer;
use SugarCraft\Crush\MCP\McpTool;

/**
 * E699 — the gated `claude-mcp` adapter, end to end over a fake binary.
 *
 * Hermetic by construction: the operator grant always points at PHP_BINARY
 * running an inline NDJSON responder, the way McpFrameCapTest,
 * ClaudeCodeMcpClientStdinWedgeTest and McpWriteBudgetShapeTest already
 * spawn real children of the wrapped client. CI never has a `claude`, and
 * NOTHING here assumes what its flags are spelled like — the fake answers
 * the wire framing only.
 */
final class ClaudeCodeMcpServerTest extends TestCase
{
    /** Bounded waits for child-state assertions; generous to absorb CI load. */
    private const OC_CHILD_WAIT_SECONDS = 3.0;

    private string $ocWorkDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ocWorkDir = sys_get_temp_dir() . '/oc_ccms_' . bin2hex(random_bytes(6));
        mkdir($this->ocWorkDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->ocWorkDir . '/*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($this->ocWorkDir);

        parent::tearDown();
    }

    /**
     * The whole point of the transport, in one happy path: a granted,
     * absolute, executable binary is spawned with the operator's argv, the
     * initialize NOTIFICATION and the tools/list round-trip fill the
     * start-time cache the panel counts, a call maps through, and stop
     * reaps. Every frame rides the shared NDJSON dialect.
     */
    public function testTheGatedGrantStartsCachesToolsAndServesCalls(): void
    {
        $server = $this->ocGrantServer($this->ocGrantScript());

        $server->start();
        try {
            self::assertTrue($server->isUp());

            $tools = $server->listTools();
            self::assertCount(1, $tools);
            self::assertContainsOnlyInstancesOf(McpTool::class, $tools);
            self::assertSame('demo', $tools[0]->name);
            self::assertSame('gated', $tools[0]->serverName);

            $result = $server->callTool('demo', ['x' => 1]);
            self::assertSame('demo said hi', $result['content'][0]['text']);
        } finally {
            $server->stop();
        }

        self::assertFalse($server->isUp(), 'a stopped transport that still reads up is a lying panel row');
    }

    /**
     * The argv default is exactly {@see ClaudeCodeMcpServer::DEFAULT_ARGS}
     * when the operator grants the binary alone, and exactly their list
     * when they grant an override — the ONLY two shapes, because the
     * repository can supply neither.
     */
    public function testTheOperatorDefaultAndTheOperatorOverrideAreTheOnlyArgvPolarities(): void
    {
        $defaulted = ClaudeCodeMcpServer::fromGrant('g1', ['type' => ClaudeCodeMcpServer::TYPE], [
            'binary' => PHP_BINARY, 'args' => null, 'env' => null,
        ]);
        self::assertSame(ClaudeCodeMcpServer::DEFAULT_ARGS, $defaulted->spawnArgs);

        $overridden = ClaudeCodeMcpServer::fromGrant('g2', ['type' => ClaudeCodeMcpServer::TYPE], [
            'binary' => PHP_BINARY, 'args' => ['--mcp', '--tweaked'], 'env' => null,
        ]);
        self::assertSame(['--mcp', '--tweaked'], $overridden->spawnArgs);
    }

    /**
     * The entry may carry the TYPE and nothing else. Every spawn key —
     * however innocuous it looks — is a config error naming the rule, and
     * the check runs BEFORE the grant is consulted: even a fully granted
     * operator config does not license the repository to name a binary.
     *
     * @return array<array{string}>
     */
    public static function repositorySpawnKeys(): array
    {
        return [['command'], ['args'], ['env']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('repositorySpawnKeys')]
    public function testAnEntryNamingAnySpawnKeyCostsTheServerTheRun(string $key): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the repository does not name this binary');

        ClaudeCodeMcpServer::fromGrant(
            'greedy',
            ['type' => ClaudeCodeMcpServer::TYPE, $key => ['whatever' => true]],
            ['binary' => PHP_BINARY, 'args' => null, 'env' => null],
        );
    }

    public function testAnUngatedRunRefusesAndNamesTheOperatorKeyNotAnyPath(): void
    {
        $caught = null;
        try {
            ClaudeCodeMcpServer::fromGrant('cc', ['type' => ClaudeCodeMcpServer::TYPE], null);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'the no-grant path must throw');
        self::assertStringContainsString('claudeMcpBinary', $caught->getMessage());
        // The refusal must survive the trip to the transcript: this class is
        // constructed from strings the operator never wrote here, and none of
        // them may carry a path.
        self::assertStringNotContainsString('/usr', $caught->getMessage());
    }

    /**
     * The review-risk row made literal: `resolveExecutable()` would happily
     * find `php` on $PATH, and the gate STILL refuses. $PATH is not a
     * grant — whoever owns the PATH owns the binary, and the operator tier
     * was created precisely to say who that is.
     */
    public function testABareNameIsRefusedEvenThoughThePathSearchWouldResolveIt(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('$PATH is not a grant');

        ClaudeCodeMcpServer::fromGrant('cc', ['type' => ClaudeCodeMcpServer::TYPE], [
            'binary' => 'php', 'args' => null, 'env' => null,
        ]);
    }

    public function testAnAbsolutePathThatIsUnusableIsRefusedWithoutEchoingIt(): void
    {
        $missing = $this->ocWorkDir . '/no-such-binary';
        $notExecutable = $this->ocWorkDir . '/data-only';
        file_put_contents($notExecutable, "print('nope');\n");
        chmod($notExecutable, 0o644);

        foreach ([$missing, $notExecutable] as $binary) {
            $caught = null;
            try {
                ClaudeCodeMcpServer::fromGrant('cc', ['type' => ClaudeCodeMcpServer::TYPE], [
                    'binary' => $binary, 'args' => null, 'env' => null,
                ]);
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            self::assertNotNull($caught, 'an unusable grant must throw');
            self::assertStringContainsString('claudeMcpBinary', $caught->getMessage());
            self::assertStringNotContainsString($binary, $caught->getMessage(),
                'the operator path never rides a message that reaches the transcript');
        }
    }

    /**
     * A child that never answers costs the START poll the client already
     * bounds (~1s of 10ms reads) — a runtime failure, so
     * McpClient::startServer() skips it silently and the launch is
     * degraded, never refused. The throw here is the poll's own voice.
     */
    public function testADeafChildFailsInsideTheBoundedStartPollAndLeavesNoUpServer(): void
    {
        $script = $this->ocWritePhpScript('deaf', "<?php\nusleep(400000);\n");

        $server = $this->ocGrantServer($script);

        $started = microtime(true);
        $caught = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'a child that never answers tools/list must not pass start');
        self::assertStringContainsString('No response received', $caught->getMessage());
        self::assertLessThan(8.0, microtime(true) - $started, 'the start poll stays bounded');
    }

    /**
     * A child that answers once and then exits reads NOT UP — the panel's
     * exited half — while the start-time tool count stays on the row, the
     * same posture the stdio sibling established. stop() after death is a
     * no-op, not a crash.
     */
    public function testACrashedChildReadsNotUpAndSurvivesStop(): void
    {
        $script = $this->ocWritePhpScript('oneshot', <<<'PHP'
            <?php
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line, true);
                if (!is_array($msg)) { continue; }
                if (($msg['method'] ?? '') === 'tools/list') {
                    echo json_encode(['jsonrpc' => '2.0', 'id' => $msg['id'] ?? 0, 'result' => ['tools' => [
                        ['name' => 'demo', 'description' => 'one', 'inputSchema' => ['type' => 'object']],
                    ]]]), "\n";
                    fflush(STDOUT);
                    break; // the crash posture: answer once, then leave
                }
            }
            PHP);

        $server = $this->ocGrantServer($script);
        $server->start();
        self::assertCount(1, $server->listTools());

        $deadline = microtime(true) + self::OC_CHILD_WAIT_SECONDS;
        while ($server->isUp() && microtime(true) < $deadline) {
            usleep(20000);
        }
        self::assertFalse($server->isUp(), 'the fake answers once and exits; a still-"up" row is a lying liveness read');

        $server->stop();
        self::assertFalse($server->isUp());
    }

    /**
     * THE GROUP-REAP PIN (E699/E673): the child is a CLI that spawns
     * grandchildren, so stop() must clear the WHOLE process group, not
     * just the pid it waited on. A leak here survives every other test in
     * the file — it is exactly the shape the descriptor census exists to
     * keep honest.
     */
    public function testStopReapsTheWholeContainmentGroupIncludingGrandchildren(): void
    {
        if (!\function_exists('posix_kill')) {
            self::markTestSkipped('the group-reap pin needs ext-posix');
        }

        $pidFile = $this->ocWorkDir . '/grandchild.pid';
        $script = $this->ocWritePhpScript('tree', <<<'PHP'
            <?php
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line, true);
                if (!is_array($msg)) { continue; }
                if (($msg['method'] ?? '') === 'tools/list') {
                    shell_exec('sleep 30 >/dev/null 2>&1 & echo $! > ' . escapeshellarg($argv[1]));
                    echo json_encode(['jsonrpc' => '2.0', 'id' => $msg['id'] ?? 0,
                        'result' => ['tools' => []]]), "\n";
                    fflush(STDOUT);
                }
            }
            PHP);

        $server = $this->ocGrantServer($script, [$pidFile]);
        $server->start();
        self::assertTrue($server->isUp());

        $deadline = microtime(true) + self::OC_CHILD_WAIT_SECONDS;
        $grandchildPid = 0;
        while (microtime(true) < $deadline) {
            if (is_file($pidFile) && (int) file_get_contents($pidFile) > 0) {
                $grandchildPid = (int) file_get_contents($pidFile);
                break;
            }
            usleep(20000);
        }
        self::assertGreaterThan(0, $grandchildPid, 'the fake child never reported its grandchild pid');
        self::assertTrue(posix_kill($grandchildPid, 0), 'the grandchild should be alive before stop()');

        $server->stop();

        $deadline = microtime(true) + self::OC_CHILD_WAIT_SECONDS;
        while (posix_kill($grandchildPid, 0) && microtime(true) < $deadline) {
            usleep(20000);
        }
        self::assertFalse(posix_kill($grandchildPid, 0),
            'stop() left a grandchild in the containment group — a bare-pid reap orphans exactly what this transport spawns');
    }

    /**
     * The env half of the containment choke point: operator-tier overrides
     * must REACH the child (they ride ProcessContainment::env()). If the
     * wrap is dropped this row goes red with an empty stderr echo.
     */
    public function testOperatorEnvOverridesReachTheSpawnedChild(): void
    {
        $script = $this->ocWritePhpScript('envprobe', <<<PHP
            <?php
            fwrite(STDERR, 'PROBE[' . var_export(getenv('OC_E699_PROBE'), true) . ']');
            while ((\$line = fgets(STDIN)) !== false) {
                \$msg = json_decode(\$line, true);
                if (!is_array(\$msg)) { continue; }
                \$id = \$msg['id'] ?? 0;
                \$method = \$msg['method'] ?? '';
                if (\$method === 'tools/list') {
                    echo json_encode(['jsonrpc' => '2.0', 'id' => \$id, 'result' => ['tools' => []]]), "\n";
                    fflush(STDOUT);
                }
            }
            PHP);

        $server = ClaudeCodeMcpServer::fromGrant('envy', ['type' => ClaudeCodeMcpServer::TYPE], [
            'binary' => PHP_BINARY, 'args' => [$script], 'env' => ['OC_E699_PROBE' => 'landed-here'],
        ]);
        $server->start();
        try {
            $deadline = microtime(true) + self::OC_CHILD_WAIT_SECONDS;
            $tail = '';
            while (microtime(true) < $deadline) {
                $server->pumpStderr();
                $tail = $server->stderrTail();
                if (str_contains($tail, 'landed-here')) {
                    break;
                }
                usleep(20000);
            }
            self::assertStringContainsString('PROBE[', $tail);
            self::assertStringContainsString('landed-here', $tail,
                'the operator env never reached the child — the spawn stopped routing through ProcessContainment::env()');
        } finally {
            $server->stop();
        }
    }

    /** A grant-shaped adapter over PHP_BINARY running the given script. */
    private function ocGrantServer(string $script, array $argvTail = []): ClaudeCodeMcpServer
    {
        return ClaudeCodeMcpServer::fromGrant('gated', ['type' => ClaudeCodeMcpServer::TYPE], [
            'binary' => PHP_BINARY,
            'args' => array_merge([$script], $argvTail),
            'env' => null,
        ]);
    }

    /** The stdio-fake every happy-path row drives: handshake, tools, one call. */
    private function ocGrantScript(): string
    {
        return $this->ocWritePhpScript('responder', <<<'PHP'
            <?php
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line, true);
                if (!is_array($msg)) { continue; }
                $id = $msg['id'] ?? 0;
                $method = $msg['method'] ?? '';
                if ($method === 'initialize') {
                    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => ['tools' => new \stdClass()],
                        'serverInfo' => ['name' => 'fake-claude-mcp', 'version' => '0'],
                    ]]), "\n";
                } elseif ($method === 'tools/list') {
                    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => [
                        ['name' => 'demo', 'description' => 'A fake demo tool', 'inputSchema' => ['type' => 'object']],
                    ]]]), "\n";
                } elseif ($method === 'tools/call') {
                    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [
                        ['type' => 'text', 'text' => 'demo said hi'],
                    ]]]), "\n";
                }
                fflush(STDOUT);
            }
            PHP);
    }

    private function ocWritePhpScript(string $label, string $body, array $extraArgv = []): string
    {
        $path = $this->ocWorkDir . '/' . $label . '.php';
        file_put_contents($path, $body . "\n");

        return $path;
    }
}
