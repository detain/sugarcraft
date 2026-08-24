<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * AN MCP SERVER THAT TALKS TOO MUCH ON STDERR MUST NOT WEDGE THE SESSION.
 *
 * {@see StdioMcpServer::start()} opens fd 2 as a pipe and, before this file
 * existed, nothing in the class ever read it, closed it, or took it out of
 * blocking mode. A pipe has a fixed kernel buffer; a child whose stderr fills
 * it blocks in its own `write()`, stops answering on stdout, and
 * {@see StdioMcpServer::readLine()} then waits for a line that can never come
 * while holding the only thing that could have released it.
 *
 * THE BUFFER IS 65536 BYTES ON THIS HOST AND THE THRESHOLD IS EXACT. Generator:
 * a child running `fwrite(STDERR, str_repeat("e", N)); fwrite(STDOUT, "LINE\n");`
 * spawned with three pipes, parent selecting on stdout only, 3s bound. PHP 8.3.6,
 * Linux 6.8, three consecutive takes, identical every time:
 *
 *     N = 1000    -> line seen, 0.035s        N = 70000   -> NEVER,  bound hit
 *     N = 60000   -> line seen, 0.035s        N = 100000  -> NEVER,  bound hit
 *     N = 65536   -> line seen, 0.035s
 *
 * So {@see self::WEDGE_BYTES} is chosen above 65536 on purpose, and
 * {@see self::SAFE_BYTES} below it: the pair is what separates "the drain works"
 * from "this fixture was never capable of wedging anything".
 *
 * ⚠️ PHP VERSION AND KERNEL ARE PART OF THAT FIGURE. 65536 is Linux's default
 * pipe capacity, not a PHP constant. The tests below do not depend on the exact
 * number — they depend on {@see self::WEDGE_BYTES} being comfortably above
 * whatever it is on the runner — but the number in this doc-block is a
 * measurement of this host and nothing else.
 *
 * THE TWO PATHS ARE TESTED SEPARATELY BECAUSE THEIR BOUNDS DIFFER. `start()`
 * carries a handshake deadline, so a regression there merely fails slowly.
 * {@see StdioMcpServer::callTool()} passes NO deadline at all — deliberately,
 * see its doc-block — so a regression there hangs FOREVER, which is why that
 * one is observed from outside a child process with the parent holding the
 * clock rather than run in-process.
 */
final class StdioMcpServerStderrDrainTest extends TestCase
{
    /** Above the measured 65536-byte pipe capacity: this WILL block the child. */
    private const WEDGE_BYTES = 100000;

    /** Below it: the child never blocks, so this row must pass either way. */
    private const SAFE_BYTES = 1000;

    /**
     * A `tools/call` argument big enough to overfill the parent's OWN stdin pipe
     * — measured above the same 65536-byte capacity. An MCP tool handed a file's
     * contents reaches this size routinely.
     */
    private const OVERSIZED_ARGUMENT_BYTES = 200000;

    /**
     * Generous next to the 0.035s the drained path measures, tight enough that
     * a 5s handshake budget being paid in full is unambiguous. Five lanes share
     * this box.
     */
    private const BOUND_SECONDS = 4.0;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_mcp_stderrdrain_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    // =========================================================================
    // The handshake path — bounded by start()'s own deadline
    // =========================================================================

    /**
     * THE HEADLINE. A server that dumps more than one pipe buffer to stderr
     * before answering `initialize` still completes the handshake.
     *
     * The budget is 5s and the bound 4s, so a regression is reported by the
     * assertion rather than by `defaultTimeLimit`: undrained, the child blocks
     * at 65536 bytes, never answers, and `start()` throws when the budget runs
     * out.
     */
    public function testAServerThatFloodsStderrBeforeTheHandshakeStillComesUp(): void
    {
        $server = $this->serverWriting(self::WEDGE_BYTES, 'flooder');

        $start = microtime(true);
        $server->start();
        $elapsed = microtime(true) - $start;

        try {
            $this->assertCount(1, $server->listTools(), 'the handshake did not complete');
            $this->assertLessThan(
                self::BOUND_SECONDS,
                $elapsed,
                sprintf(
                    'start() took %.2fs against a server writing %d bytes of stderr — the child '
                    . 'is blocking in write() and its stderr is not being drained',
                    $elapsed,
                    self::WEDGE_BYTES,
                ),
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * THE NEGATIVE CONTROL for the row above. The same fixture, the same code
     * path, with a stderr volume BELOW the pipe capacity — it must pass whether
     * or not anything drains, which is what proves the passing row above is
     * about the drain and not about the fixture being harmless.
     */
    public function testTheSameFixtureUnderThePipeCapacityWasNeverAtRisk(): void
    {
        $server = $this->serverWriting(self::SAFE_BYTES, 'quiet');
        $server->start();

        try {
            $this->assertCount(1, $server->listTools());
        } finally {
            $server->stop();
        }
    }

    /**
     * ...and the flood is REAL. Without this the two rows above are satisfied by
     * a fixture whose `str_repeat()` produced nothing: a server that writes zero
     * bytes to stderr also comes up promptly.
     *
     * Reads the drained buffer straight off the object, and requires it to have
     * reached the cap — which can only happen if strictly more than
     * {@see StdioMcpServer}'s `MAX_STDERR_BYTES` bytes actually arrived.
     */
    public function testTheFloodFixtureGenuinelyWritesMoreThanOnePipeBufferOfStderr(): void
    {
        $server = $this->serverWriting(self::WEDGE_BYTES, 'flood-check', delayMicros: 200000);
        $server->start();

        try {
            $this->assertSame(
                $this->maxStderrBytes(),
                strlen($this->stderrTailOf($server)),
                'the fixture did not deliver more than one buffer of stderr, so the wedge rows '
                . 'above are passing against a server that was never capable of blocking',
            );
        } finally {
            $server->stop();
        }
    }

    // =========================================================================
    // The tool-call path — unbounded, so observed from outside
    // =========================================================================

    /**
     * `callTool()` PASSES NO DEADLINE, so undrained stderr wedges it PERMANENTLY
     * rather than for the length of a budget. That makes it the worse of the two
     * sites and the one that cannot be timed in-process: a regression would hang
     * this test until PHPUnit's own limit, or past it.
     *
     * So the exchange runs in a child `php` process and THIS process holds the
     * clock, kills the probe if it overruns, and reports the overrun as the
     * failure. Same instrument the rest of `tests/MCP/` uses for process
     * questions — nothing installed, nothing networked.
     */
    public function testAToolCallSurvivesAServerThatFloodsStderrFirst(): void
    {
        $probe = $this->tempDir . '/probe.php';
        file_put_contents($probe, sprintf(
            self::PROBE_TEMPLATE,
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->writeServerScript(self::WEDGE_BYTES, 'probe-server'), true),
        ));

        [$rc, $out, $elapsed] = $this->runBounded([PHP_BINARY, $probe], self::BOUND_SECONDS + 4.0);

        $this->assertSame(
            0,
            $rc,
            sprintf(
                'the callTool() probe did not finish (rc=%d) after %.2fs — an undrained stderr '
                . 'wedges callTool() forever, because it passes no deadline. Output: %s',
                $rc,
                $elapsed,
                trim($out) === '' ? '(none)' : trim($out),
            ),
        );
        $this->assertStringContainsString('CALLED:pong', $out, 'the tool call returned the wrong thing');
    }

    // =========================================================================
    // The third pipe — stdin, which the same flood also wedges
    // =========================================================================

    /**
     * STDIN IS PART OF THE SAME DEADLOCK, AND A ONE-SHOT DRAIN DOES NOT CLOSE IT.
     *
     * A child blocked writing stderr is not reading its stdin either, so once
     * the parent's own 64 KiB stdin buffer fills, `writeLine()` blocks BEFORE
     * `readLine()` — the other place that drains — is ever reached. Generator
     * for the numbers, PHP 8.3.6, Linux 6.8, three consecutive takes, identical
     * every time (D = 8192-byte stderr reads performed before a blocking write
     * of M bytes, against a child that floods N bytes of stderr first, 4s bound):
     *
     *     N=100000 D=0  M=200000 -> WEDGED    N=1000   D=0 M=200000 -> ok 0.35s
     *     N=100000 D=1  M=200000 -> WEDGED    N=100000 D=0 M=1000   -> ok 0.35s
     *     N=100000 D=20 M=200000 -> ok 0.35s
     *
     * The D=1 row is the one that matters: an earlier version of this fix drained
     * once before the blocking write and would have PASSED review while leaving
     * the deadlock exactly where it was. Both sides must be over their pipe
     * capacity, which is why the two control rows are in the table.
     *
     * Observed from a child process holding the clock, for the same reason the
     * `callTool()` row is: unfixed, this hangs forever rather than slowly.
     */
    public function testALargeToolCallSurvivesAServerAlreadyBlockedOnStderr(): void
    {
        $probe = $this->tempDir . '/bigprobe.php';
        file_put_contents($probe, sprintf(
            self::BIG_WRITE_PROBE_TEMPLATE,
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->writeBlockedServerScript(self::WEDGE_BYTES, 'big-server'), true),
            self::OVERSIZED_ARGUMENT_BYTES,
        ));

        [$rc, $out, $elapsed] = $this->runBounded([PHP_BINARY, $probe], self::BOUND_SECONDS + 6.0);

        $this->assertSame(
            0,
            $rc,
            sprintf(
                'a %d-byte tools/call against a server that floods stderr did not complete '
                . '(rc=%d) after %.2fs — stdin and stderr are deadlocked against each other. '
                . 'Output: %s',
                self::OVERSIZED_ARGUMENT_BYTES,
                $rc,
                $elapsed,
                trim($out) === '' ? '(none)' : trim($out),
            ),
        );
        $this->assertStringContainsString('BIGCALLED:pong', $out);
    }

    /**
     * THE CONTROL for the row above, and it is the row that says the fixture is
     * discriminating rather than merely passing: the SAME oversized argument
     * against a server whose stderr stays under the pipe capacity was never at
     * risk, and the same probe must complete for it too.
     */
    public function testTheOversizedCallWasOnlyEverAtRiskBecauseOfTheStderrFlood(): void
    {
        $probe = $this->tempDir . '/bigquiet.php';
        file_put_contents($probe, sprintf(
            self::BIG_WRITE_PROBE_TEMPLATE,
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->writeBlockedServerScript(self::SAFE_BYTES, 'big-quiet-server'), true),
            self::OVERSIZED_ARGUMENT_BYTES,
        ));

        [$rc, $out, ] = $this->runBounded([PHP_BINARY, $probe], self::BOUND_SECONDS + 6.0);

        $this->assertSame(0, $rc, 'the probe itself is broken: ' . trim($out));
        $this->assertStringContainsString('BIGCALLED:pong', $out);
    }

    // =========================================================================
    // The failure a naive drain substitutes, and the diagnostic it buys
    // =========================================================================

    /**
     * A PIPE AT EOF IS PERMANENTLY READABLE, so a drain that keeps fd 2 in the
     * `stream_select()` set after the child closes it converts the hang into a
     * 100% CPU spin on the deadline-less {@see StdioMcpServer::callTool()} wait.
     *
     * Pinned on the flag rather than on CPU time, deliberately: a CPU-time
     * assertion on a box five lanes share is a coin flip, and the flag IS the
     * mechanism. Delete the `stderrOpen = false` in the EOF branch and this
     * goes red.
     */
    public function testStderrIsDroppedFromTheSelectSetOnceTheChildClosesIt(): void
    {
        $server = $this->serverWriting(0, 'closes-stderr', closeStderr: true);
        $server->start();

        try {
            $this->assertFalse(
                $this->stderrOpenOf($server),
                'the server closed its stderr and this class is still selecting on it — a pipe '
                . 'at EOF is always readable, so the unbounded callTool() wait now spins'
            );
        } finally {
            $server->stop();
        }

        // THE OTHER POLARITY, without which the assertion above is satisfied by a
        // flag that is simply never set: a server holding stderr OPEN must still
        // be selected on, or nothing gets drained and the wedge is back.
        $live = $this->serverWriting(self::SAFE_BYTES, 'holds-stderr');
        $live->start();

        try {
            $this->assertTrue(
                $this->stderrOpenOf($live),
                'stderr is not being selected on for a server that still holds it open, so no '
                . 'drain happens at all'
            );
        } finally {
            $live->stop();
        }
    }

    /**
     * THE CAP KEEPS THE TAIL, NOT THE HEAD, because this text exists to answer
     * "why did it fail" and the reason a process gives is the last thing it
     * says. A head-truncating cap would reliably keep a startup banner and drop
     * the error.
     *
     * The fixture delays its handshake reply so the whole flood is drained
     * before the line arrives, which is what makes the byte count exact.
     */
    public function testTheStderrCapKeepsTheTailAndDiscardsTheHead(): void
    {
        $server = $this->serverWriting(
            self::WEDGE_BYTES,
            'marked',
            delayMicros: 200000,
            head: 'HEADMARK',
            tail: 'TAILMARK',
        );
        $server->start();

        try {
            $tail = $this->stderrTailOf($server);

            $this->assertSame($this->maxStderrBytes(), strlen($tail), 'the cap was not applied');
            $this->assertStringEndsWith('TAILMARK', $tail, 'the cap dropped the END of stderr');
            $this->assertStringNotContainsString(
                'HEADMARK',
                $tail,
                'the cap kept the head, so a noisy banner would evict the actual error'
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * The bytes are kept because they are the ONLY diagnostic a failed launch
     * has. Before the drain, a server that died printing a stack trace produced
     * exactly `Failed to start MCP server: <name>` and nothing else — its own
     * explanation went into a pipe nobody read.
     */
    public function testAFailedLaunchReportsWhatTheServerSaidOnStderr(): void
    {
        $script = $this->tempDir . '/dying.php';
        file_put_contents($script, self::DYING_SERVER);

        $server = new StdioMcpServer(
            name: 'dying',
            command: PHP_BINARY,
            args: [$script],
            env: [],
            startTimeoutSeconds: 5.0,
        );

        try {
            $server->start();
            $this->fail('start() must not report success for a server that never answered');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to start MCP server: dying', $e->getMessage());
            $this->assertStringContainsString(
                'Fatal: the widget backend is not configured',
                $e->getMessage(),
                "the child's own explanation is still being thrown away"
            );
        } finally {
            $server->stop();
        }
    }

    // =========================================================================
    // Fixtures and helpers
    // =========================================================================

    private function serverWriting(
        int $bytes,
        string $name,
        int $delayMicros = 0,
        bool $closeStderr = false,
        string $head = '',
        string $tail = '',
    ): StdioMcpServer {
        return new StdioMcpServer(
            name: $name,
            command: PHP_BINARY,
            args: [$this->writeServerScript($bytes, $name, $delayMicros, $closeStderr, $head, $tail)],
            env: [],
            startTimeoutSeconds: 5.0,
        );
    }

    /**
     * Writes a server script that floods stderr BEFORE answering, and returns
     * its path. The flood happens on every message, not only the first, so the
     * `tools/call` probe exercises the same wedge the handshake does.
     */
    private function writeServerScript(
        int $bytes,
        string $name,
        int $delayMicros = 0,
        bool $closeStderr = false,
        string $head = '',
        string $tail = '',
    ): string {
        $path = $this->tempDir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $name) . '.php';
        file_put_contents($path, sprintf(
            self::FLOODING_SERVER_TEMPLATE,
            $closeStderr ? 'fclose(STDERR);' : '',
            var_export($head, true),
            $bytes,
            var_export($tail, true),
            $delayMicros,
        ));

        return $path;
    }

    /**
     * A server that completes the handshake and THEN floods stderr unprompted,
     * so it is sitting blocked in its own `write()` — not at `fgets()` — when the
     * parent's next stdin write begins.
     *
     * ⚠️ THE ORDERING IS THE WHOLE TEST. {@see writeServerScript()} floods only
     * AFTER reading a line, and {@see StdioMcpServer::readLine()} drains that
     * flood completely before returning the reply — so by the time the parent
     * writes again, stderr is empty and the child is waiting at `fgets()`. Both
     * oversized-write rows were originally pointed at that fixture and were
     * therefore VACUOUS: removing the drain from the write loop, and putting
     * stdin back into blocking mode, both left them green. They are pinned by
     * this fixture instead, and by the mutations of those two exact lines.
     */
    private function writeBlockedServerScript(int $bytes, string $name): string
    {
        $path = $this->tempDir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $name) . '-blocked.php';
        file_put_contents($path, sprintf(self::BLOCKED_ON_STDERR_SERVER_TEMPLATE, $bytes));

        return $path;
    }

    /** @return array{0: int, 1: string, 2: float} rc, stdout+stderr, elapsed */
    private function runBounded(array $argv, float $budgetSeconds): array
    {
        $process = proc_open($argv, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process, 'could not spawn the probe');

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $start = microtime(true);
        $deadline = $start + $budgetSeconds;
        $out = '';
        $timedOut = false;

        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $out .= (string) stream_get_contents($pipes[2]);

            if (!proc_get_status($process)['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }
            usleep(5000);
        }

        $elapsed = microtime(true) - $start;

        if ($timedOut) {
            // Signal 9 rather than SIGTERM: the probe is wedged in a blocking
            // read, and the point of this branch is that it does not get to
            // decide when it stops.
            proc_terminate($process, 9);
        }

        $out .= (string) stream_get_contents($pipes[1]);
        $out .= (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $rc = proc_close($process);

        return [$timedOut ? -1 : $rc, $out, $elapsed];
    }

    private function stderrTailOf(StdioMcpServer $server): string
    {
        $property = new \ReflectionProperty($server, 'stderrTail');
        $property->setAccessible(true);

        return (string) $property->getValue($server);
    }

    private function stderrOpenOf(StdioMcpServer $server): bool
    {
        $property = new \ReflectionProperty($server, 'stderrOpen');
        $property->setAccessible(true);

        return (bool) $property->getValue($server);
    }

    /** Read off the class rather than restated, so the cap cannot drift from it. */
    private function maxStderrBytes(): int
    {
        return (int) (new \ReflectionClass(StdioMcpServer::class))->getConstant('MAX_STDERR_BYTES');
    }

    /**
     * %s close-stderr statement · %s head marker · %d flood bytes ·
     * %s tail marker · %d microseconds of delay before each reply.
     */
    private const FLOODING_SERVER_TEMPLATE = <<<'PHP'
        <?php
        %s
        $noise = %s . str_repeat('e', %d) . %s;
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            if ($noise !== '' && is_resource(STDERR)) {
                fwrite(STDERR, $noise);
            }
            usleep(%d);
            $method = (string) ($msg['method'] ?? '');
            if ($method === 'initialize') {
                $result = ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()];
            } elseif ($method === 'tools/list') {
                $result = ['tools' => [[
                    'name' => 'ping',
                    'description' => 'Answer with pong.',
                    'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                ]]];
            } else {
                $result = ['content' => [['type' => 'text', 'text' => 'pong']]];
            }
            echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
            flush();
        }
        PHP;

    /** %s autoloader path · %s server script path. */
    private const PROBE_TEMPLATE = <<<'PHP'
        <?php
        require %s;
        $server = new SugarCraft\Crush\MCP\StdioMcpServer(
            name: 'probe',
            command: PHP_BINARY,
            args: [%s],
            env: [],
            startTimeoutSeconds: 5.0,
        );
        $server->start();
        $raw = $server->callTool('ping', []);
        $server->stop();
        echo 'CALLED:', $raw['content'][0]['text'] ?? '(nothing)', "\n";
        PHP;

    /**
     * %d bytes of stderr, written unprompted the moment the handshake is done.
     * Above the pipe capacity the child parks inside that `fwrite()` and stops
     * reading stdin, which is the state the oversized write has to meet.
     */
    private const BLOCKED_ON_STDERR_SERVER_TEMPLATE = <<<'PHP'
        <?php
        $noise = str_repeat('e', %d);
        $flooded = false;
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $method = (string) ($msg['method'] ?? '');
            if ($method === 'initialize') {
                $result = ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()];
            } elseif ($method === 'tools/list') {
                $result = ['tools' => [[
                    'name' => 'ping',
                    'description' => 'Answer with pong.',
                    'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                ]]];
            } else {
                $result = ['content' => [['type' => 'text', 'text' => 'pong']]];
            }
            echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
            flush();
            if ($method === 'tools/list' && !$flooded) {
                $flooded = true;
                fwrite(STDERR, $noise);
            }
        }
        PHP;

    /** %s autoloader · %s server script · %d bytes of argument. */
    private const BIG_WRITE_PROBE_TEMPLATE = <<<'PHP'
        <?php
        require %s;
        $server = new SugarCraft\Crush\MCP\StdioMcpServer(
            name: 'bigprobe',
            command: PHP_BINARY,
            args: [%s],
            env: [],
            startTimeoutSeconds: 5.0,
        );
        $server->start();
        // Let the child reach its unprompted stderr flood and park in that
        // write() — 300ms, the same settle used by the generator in
        // StdioMcpServer::writeLine()'s doc-block.
        usleep(300000);
        $raw = $server->callTool('ping', ['blob' => str_repeat('x', %d)]);
        $server->stop();
        echo 'BIGCALLED:', $raw['content'][0]['text'] ?? '(nothing)', "\n";
        PHP;

    /** Explains itself on stderr, then exits without ever answering. */
    private const DYING_SERVER = <<<'PHP'
        <?php
        fwrite(STDERR, "Fatal: the widget backend is not configured\n");
        exit(1);
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
