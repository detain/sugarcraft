<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * {@see StdioMcpServer::stop()} must return in BOUNDED time.
 *
 * `proc_close()` WAITS for the child, so the two-line `proc_terminate()` +
 * `proc_close()` this method used to be handed the caller's deadline to a
 * process that is by definition somebody else's code. MEASURED on this host
 * against that version, with the fixture below: 8.30s. It was unreachable while
 * nothing in `src/` constructed an {@see \SugarCraft\Crush\MCP\McpClient};
 * {@see \SugarCraft\Crush\Cli\Bootstrap::mcpClient()} makes it a real hang on
 * every session exit.
 *
 * WHY SOME FIXTURES ARE INJECTED AND ONE IS STARTED, because the two answer
 * different questions and the file used to be able to answer only the first.
 * `proc_terminate()` signals the DIRECT CHILD and nothing else, so a
 * SIGTERM-ignoring trap only tests the escalation if it is installed THERE. The
 * injected `serverOver()` fixtures put a shell script directly under
 * `proc_open()` to get exactly that shape, cheaply and with no protocol to
 * speak.
 *
 * What they cannot answer is whether a REAL, config-driven server is the direct
 * child at all — and for as long as {@see StdioMcpServer::start()} handed
 * `proc_open()` an `escapeshellarg()`ed STRING it was not. MEASURED on this host
 * (`/bin/sh` -> dash, which does NOT apply the `-c` exec optimisation): the
 * direct child was the `sh -c` wrapper and the server was a GRANDCHILD, so
 * `stop()` killed dash in 0.01s and left the server alive, reparented to pid 1,
 * still answering over the inherited pipes:
 *
 *     1146812 1146811 sh -c '/usr/bin/php8.3' '…/stubborn.php'
 *     1146813 1146812 /usr/bin/php8.3 …/stubborn.php      <- the server
 *     after stop(): 1146812 gone, 1146813 ALIVE with PPID 1
 *
 * `start()` now uses the ARGV form, so the direct child IS the server. That is
 * what {@see testAConfigDrivenServerIsTheDirectChildAndIsKilled()} asserts, on a
 * fixture MCP server that reports its OWN pid and then ignores SIGTERM — the one
 * shape in this file where the process under test was produced by `start()`
 * rather than handed to it.
 */
final class StdioMcpServerShutdownTest extends TestCase
{
    /**
     * Comfortably above the 1.0s + 1.0s escalation and far below the 8.30s the
     * old two-liner measured, so neither a loaded box nor a regression is
     * ambiguous.
     */
    private const BOUND_SECONDS = 4.0;

    /** @var list<array{0: resource, 1: array}> */
    private array $spawned = [];

    /** @var list<int> pids a fixture reported for itself, killed on the way out */
    private array $reportedPids = [];

    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_mcp_shutdown_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Only processes THIS test started — never a global sweep.
        foreach ($this->spawned as [$proc, $pipes]) {
            if (is_resource($proc)) {
                @proc_terminate($proc, 9);
                @proc_close($proc);
            }
            unset($pipes);
        }
        $this->spawned = [];

        // A pid a fixture reported for ITSELF: under a regression it survives
        // `stop()`, and leaving it running would leak a process out of this suite.
        // Only pids this test's own fixtures wrote down — never a pattern sweep.
        foreach ($this->reportedPids as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            }
        }
        $this->reportedPids = [];

        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    public function testStopReturnsBoundedWhenTheDirectChildIgnoresSigterm(): void
    {
        $server = $this->serverOver("trap '' TERM; cat > /dev/null; sleep 8");

        $start = microtime(true);
        $server->stop();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            self::BOUND_SECONDS,
            $elapsed,
            sprintf(
                'stop() took %.2fs; a server child that ignores SIGTERM must not hold session shutdown open '
                . '(the pre-fix proc_terminate+proc_close pair measured 8.30s here)',
                $elapsed,
            ),
        );
    }

    /**
     * The control that makes the bound above mean something: a child that
     * HONOURS SIGTERM is not merely bounded, it is quick — so the assertion is
     * not passing because the escalation budget happens to be short.
     */
    public function testStopReturnsPromptlyWhenTheChildHonoursSigterm(): void
    {
        $server = $this->serverOver('cat > /dev/null');

        $start = microtime(true);
        $server->stop();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            0.5,
            $elapsed,
            sprintf('a well-behaved child must not pay the escalation budget; took %.2fs', $elapsed),
        );
    }

    /**
     * And the child is genuinely GONE afterwards, not merely un-waited-for: a
     * `stop()` that returned bounded by abandoning the process would satisfy the
     * timing assertions above and leave exactly the orphan this method exists to
     * prevent.
     */
    public function testTheIgnoringChildIsDeadAfterStopReturns(): void
    {
        $server = $this->serverOver("trap '' TERM; cat > /dev/null; sleep 8");
        $pid = $this->pidOf($server);

        $server->stop();

        $this->assertFalse(
            $this->isAlive($pid),
            "pid {$pid} survived stop(); signal 9 must have reached the direct child",
        );
    }

    /**
     * THE ONE THAT NEEDED A REAL `start()`. A configured server's OWN process must
     * be the process `stop()` signals — which is a claim about `start()`'s
     * `proc_open()` call shape, and no injected fixture can test it.
     *
     * The fixture writes `getmypid()` to a file, completes the handshake, then
     * ignores SIGTERM. Two assertions follow, and the first is the finding:
     *
     *  - the pid `proc_get_status()` reports IS the pid the server reported for
     *    itself. Under the previous `escapeshellarg()`-into-`sh -c` form these
     *    were two different processes (dash and its child), which is exactly how a
     *    "stopped" server kept running;
     *  - and that pid is GONE after `stop()` returns, i.e. the SIGTERM-then-9
     *    escalation actually reached the server rather than a wrapper that dies on
     *    the first signal regardless.
     */
    public function testAConfigDrivenServerIsTheDirectChildAndIsKilled(): void
    {
        $pidFile = $this->tempDir . '/server.pid';
        $script = $this->tempDir . '/stubborn-mcp.php';
        file_put_contents($script, self::STUBBORN_MCP_SERVER);

        $server = new StdioMcpServer(
            name: 'stubborn',
            command: PHP_BINARY,
            args: [$script, $pidFile],
            env: [],
            startTimeoutSeconds: 5.0,
        );
        $server->start();

        $directChild = $this->pidOf($server);
        $this->assertFileExists($pidFile, 'the fixture server must have run and reported its pid');
        $selfReported = (int) trim((string) file_get_contents($pidFile));
        $this->reportedPids[] = $selfReported;

        $this->assertSame(
            $selfReported,
            $directChild,
            "proc_open()'s direct child (pid {$directChild}) must BE the server (pid {$selfReported}); "
            . 'a shell wrapper in between is what left "stopped" servers running',
        );

        $server->stop();

        $this->assertFalse(
            $this->isAlive($selfReported),
            "the server (pid {$selfReported}) survived stop()",
        );
    }

    /**
     * `stop()` on a server that was never started, and `stop()` twice, are both
     * no-ops rather than errors — the shutdown seam
     * ({@see \SugarCraft\Crush\Cli\Bootstrap::stopMcpServers()}) may reach a
     * client whose servers a caller already stopped.
     */
    public function testStopIsSafeUnstartedAndIdempotent(): void
    {
        $server = new StdioMcpServer(name: 'never-started', command: 'true', args: [], env: []);
        $server->stop();
        $server->stop();

        $started = $this->serverOver('cat > /dev/null');
        $started->stop();
        $started->stop();

        $this->assertSame([], $started->listTools());
    }

    /**
     * A running {@see StdioMcpServer} whose direct child is $script.
     *
     * Injected through reflection because `start()` cannot produce this shape —
     * see the class docblock. The private property names are part of what is
     * being exercised: `stop()` reads exactly these.
     */
    private function serverOver(string $script): StdioMcpServer
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($script, $descriptors, $pipes);
        $this->assertIsResource($proc, 'the fixture child must have started');
        $this->spawned[] = [$proc, $pipes];

        // The trap is installed by the shell before it reaches `cat`, and
        // `proc_get_status()` says nothing about how far a child has got. One
        // short wait, so a SIGTERM cannot arrive before `trap` has run and make
        // the ignoring case pass for the wrong reason.
        usleep(150_000);

        $server = new StdioMcpServer(name: 'fixture', command: 'unused', args: [], env: []);

        $reflection = new \ReflectionClass($server);
        $process = $reflection->getProperty('process');
        $process->setAccessible(true);
        $process->setValue($server, $proc);
        $pipesProperty = $reflection->getProperty('pipes');
        $pipesProperty->setAccessible(true);
        $pipesProperty->setValue($server, $pipes);

        return $server;
    }

    private function pidOf(StdioMcpServer $server): int
    {
        $process = new \ReflectionProperty($server, 'process');
        $process->setAccessible(true);

        return (int) proc_get_status($process->getValue($server))['pid'];
    }

    /**
     * Is $pid still a live (non-zombie) process?
     *
     * Read from procfs rather than `posix_kill($pid, 0)`: after `stop()` the
     * child has been waited for, so it is not a zombie either way, but procfs
     * needs no optional extension.
     */
    private function isAlive(int $pid): bool
    {
        if (!is_dir('/proc')) {
            $this->markTestSkipped('needs procfs to observe the child');
        }

        return is_dir('/proc/' . $pid);
    }

    /**
     * A fixture MCP server that reports its own pid, answers the handshake, and
     * then ignores SIGTERM — so only the signal-9 escalation can end it.
     *
     * `pcntl` is not assumed: without it the trap cannot be installed and the
     * process is merely a normal server, which still satisfies the pid identity
     * assertion (the half this fixture exists for) and dies on the first signal.
     */
    private const STUBBORN_MCP_SERVER = <<<'PHP'
        <?php
        file_put_contents($argv[1], (string) getmypid());
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static fn () => null);
        }
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $result = match ((string) ($msg['method'] ?? '')) {
                'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()],
                'tools/list' => ['tools' => []],
                default => new stdClass(),
            };
            echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
            flush();
        }
        sleep(30);
        PHP;

    private function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
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
