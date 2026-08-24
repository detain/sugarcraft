<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ClaudeCodeMcpClient;

/**
 * {@see ClaudeCodeMcpClient::disconnect()} must return in BOUNDED time, and the
 * server must be DEAD when it does.
 *
 * THE UNFIXED TWIN. {@see \SugarCraft\Crush\MCP\StdioMcpServer::stop()} already
 * escalates SIGTERM -> poll -> signal 9 and is pinned by
 * {@see \SugarCraft\Crush\Tests\MCP\StdioMcpServerShutdownTest}; this class owns
 * the same kind of child over the same transport and shipped
 * `fclose()`-then-bare-`proc_close()` instead. `proc_close()` WAITS, so that
 * spelling hands the caller's deadline to a third party's process.
 *
 * MEASURED on this host (PHP 8.3.6, Linux 6.8): against a direct child that
 * installs a no-op SIGTERM handler and then loops for eight seconds,
 * `proc_terminate()` immediately followed by `proc_close()` returned after
 * **7.77s**, with the child dead — it does NOT orphan, it blocks for the child's
 * whole remaining lifetime. The pre-fix `disconnect()` did not even signal
 * first, so it went straight into that wait. And it is reached from
 * `__destruct()`, which means the block lands wherever the last reference to the
 * client is dropped rather than at a point the author chose.
 *
 * WHY A REAL SCRIPT AND NOT AN INJECTED FIXTURE. `proc_terminate()` signals the
 * DIRECT CHILD and nothing else, so a SIGTERM-ignoring handler only exercises
 * the escalation if it is installed THERE — and whether the direct child is the
 * server at all is a claim about {@see ClaudeCodeMcpClient::connect()}'s
 * `proc_open()` call shape. It passes an ARGV. Under a shell STRING, `/bin/sh`
 * on this host is dash, which does NOT apply the `-c` exec optimisation:
 * MEASURED, the direct child's `comm` is `(sh)` and the real program is a
 * GRANDCHILD, so every signal lands on a wrapper and the server survives,
 * reparented to pid 1. Every fixture below therefore goes through the real
 * `connect()`, and {@see testTheServerIsTheDirectChildAndNotAShellWrapper()}
 * asserts that shape directly.
 */
final class ClaudeCodeMcpClientShutdownTest extends TestCase
{
    /**
     * Comfortably above the 1.0s + 1.0s escalation and far below the 7.77s the
     * bare `proc_close()` measured, so neither a loaded box nor a regression is
     * ambiguous.
     */
    private const BOUND_SECONDS = 4.0;

    private string $tempDir = '';

    /** @var list<int> pids a fixture reported for itself, killed on the way out */
    private array $reportedPids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_ccmcp_shutdown_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // ONLY pids this test's own fixtures wrote down — never a pattern sweep,
        // never a global pkill. Under a regression the fixture survives
        // disconnect(), and leaking a process out of this suite is how a sibling
        // run inherits a stranger.
        foreach ($this->reportedPids as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            }
        }
        $this->reportedPids = [];

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    public function testDisconnectReturnsBoundedWhenTheServerIgnoresSigterm(): void
    {
        $client = $this->connectedClientOver(self::STUBBORN_SERVER);

        $start = microtime(true);
        $client->disconnect();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            self::BOUND_SECONDS,
            $elapsed,
            sprintf(
                'disconnect() took %.2fs; an MCP server that ignores SIGTERM must not hold shutdown open '
                . '(the pre-fix fclose+proc_close pair measured 7.77s here, and it runs from __destruct())',
                $elapsed,
            ),
        );
    }

    /**
     * THE CONTROL that makes the bound above mean something: a server that
     * HONOURS SIGTERM is not merely bounded, it is quick — so the assertion is
     * not passing because the escalation budget happens to be short. Without
     * this, halving both grace constants would look like an improvement.
     */
    public function testDisconnectReturnsPromptlyWhenTheServerHonoursSigterm(): void
    {
        $client = $this->connectedClientOver(self::WELL_BEHAVED_SERVER);

        $start = microtime(true);
        $client->disconnect();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            1.0,
            $elapsed,
            sprintf('a well-behaved server must not pay the escalation budget; took %.2fs', $elapsed),
        );
    }

    /**
     * And the server is genuinely GONE afterwards, not merely un-waited-for. A
     * `disconnect()` that returned bounded by abandoning the process would
     * satisfy the timing assertions above and leave exactly the orphan this
     * change exists to prevent — and an abandoned child is not hypothetical
     * here: MEASURED on this host, dropping a `proc_open()` handle whose child
     * is still RUNNING takes 0.000s and leaves it in state `S`, i.e. the
     * resource destructor reaps a zombie but never waits for a live child.
     */
    public function testTheIgnoringServerIsDeadAfterDisconnectReturns(): void
    {
        $client = $this->connectedClientOver(self::STUBBORN_SERVER);
        $pid = $this->selfReportedPid($client);

        $client->disconnect();

        $this->assertFalse(
            $this->isAlive($pid),
            "pid {$pid} survived disconnect(); signal 9 must have reached the direct child",
        );
    }

    /**
     * THE ONE THAT IS ABOUT `connect()` RATHER THAN `disconnect()`. The server's
     * OWN pid must be the pid `proc_get_status()` reports for the direct child.
     * Under a shell string these are two different processes (dash and its
     * child) and every signal `disconnect()` sends lands on the wrapper, so the
     * bound above would still pass while the server kept running. Mutating
     * `connect()`'s `array_merge([$command], $args)` into an
     * `escapeshellarg()`ed string reds exactly here and nowhere else.
     */
    public function testTheServerIsTheDirectChildAndNotAShellWrapper(): void
    {
        $client = $this->connectedClientOver(self::STUBBORN_SERVER);

        $selfReported = $this->selfReportedPid($client);
        $handle = new \ReflectionProperty(ClaudeCodeMcpClient::class, 'process');
        $directChild = (int) proc_get_status($handle->getValue($client))['pid'];

        $this->assertSame(
            $selfReported,
            $directChild,
            "proc_open()'s direct child (pid {$directChild}) must BE the server (pid {$selfReported}); "
            . 'a shell wrapper in between is what leaves "disconnected" servers running',
        );

        $client->disconnect();
    }

    /**
     * `disconnect()` twice, and on a client that never connected, are both
     * no-ops rather than errors — {@see ClaudeCodeMcpClient::__destruct()} calls
     * it after any explicit call a caller already made, so a double teardown is
     * the NORMAL path and not an edge case.
     */
    public function testDisconnectIsIdempotentAndSafeUnconnected(): void
    {
        $never = new ClaudeCodeMcpClient('true');
        $never->disconnect();
        $never->disconnect();
        $this->assertFalse($never->isConnected());

        $client = $this->connectedClientOver(self::WELL_BEHAVED_SERVER);
        $client->disconnect();
        $client->disconnect();
        $this->assertFalse($client->isConnected());
    }

    /**
     * Holds stdin open forever while IGNORING SIGTERM, and writes its pid to
     * $argv[1] ONLY ONCE THE HANDLER IS INSTALLED.
     *
     * THE ORDER OF THOSE TWO LINES IS THE FIXTURE. It was the other way round
     * and the mutation table says what that cost: removing the signal-9
     * escalation from
     * {@see \SugarCraft\Crush\Support\ProcessReaper::terminateAndClose()}
     * SURVIVED, because `disconnect()` ran before the child had reached
     * `pcntl_signal()` and SIGTERM killed it at its DEFAULT disposition. The
     * bound was measuring a well-behaved child while claiming to measure a
     * stubborn one — the assertion's window, not the mutation's relevance. The
     * pid file is now the readiness handshake every fixture here waits on, and
     * the same mutation is killed.
     *
     * `pcntl_async_signals()` plus a POLLING loop rather than one long
     * `usleep()`: a signal interrupts `usleep()`, and the script would then
     * simply END — which looks exactly like a well-behaved exit and would make
     * this fixture silently useless in the other direction.
     */
    private const STUBBORN_SERVER = <<<'PHP'
        <?php
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void {});
        file_put_contents($argv[1], (string) getmypid());
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            usleep(20000);
        }
        PHP;

    /**
     * The same, but with SIGTERM left at its DEFAULT disposition — the control
     * that keeps the bound above from being satisfied by a short budget.
     */
    private const WELL_BEHAVED_SERVER = <<<'PHP'
        <?php
        file_put_contents($argv[1], (string) getmypid());
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            usleep(20000);
        }
        PHP;

    /**
     * A connected {@see ClaudeCodeMcpClient} whose direct child is $source,
     * spawned through the REAL `connect()` — see the class docblock for why no
     * fixture here is injected through reflection.
     */
    private function connectedClientOver(string $source): ClaudeCodeMcpClient
    {
        $script = $this->tempDir . '/server.php';
        file_put_contents($script, $source);
        @unlink($this->pidFile());

        $client = new ClaudeCodeMcpClient(PHP_BINARY, [$script, $this->pidFile()]);
        $client->connect();
        $this->assertTrue($client->isConnected(), 'the fixture server must have started');

        // BLOCK UNTIL THE FIXTURE IS READY, in EVERY test rather than only the
        // two that need the number. `connect()` returns as soon as
        // `proc_open()` does, which is before the child has run a line — and a
        // stubborn fixture that has not yet installed its handler is a
        // well-behaved fixture. See STUBBORN_SERVER for the mutation that
        // survived while this wait was missing.
        $this->selfReportedPid($client);

        return $client;
    }

    private function pidFile(): string
    {
        return $this->tempDir . '/server.pid';
    }

    /**
     * The pid the fixture wrote down for ITSELF, waited for rather than assumed:
     * `connect()` returns as soon as `proc_open()` does, which is before the
     * child has necessarily run a line.
     */
    private function selfReportedPid(ClaudeCodeMcpClient $client): int
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $raw = @file_get_contents($this->pidFile());
            if (is_string($raw) && ctype_digit(trim($raw))) {
                $pid = (int) trim($raw);
                if (!in_array($pid, $this->reportedPids, true)) {
                    $this->reportedPids[] = $pid;
                }

                return $pid;
            }
            usleep(20000);
        }

        $this->fail('the fixture server never reported its pid');
    }

    private function isAlive(int $pid): bool
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('ext-posix is required to observe process liveness');
        }

        // A pid that exited but has not been waited for is a zombie, and
        // `posix_kill($pid, 0)` answers TRUE for one. Reading its state is what
        // separates "still running" from "already dead and reaped by us".
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return false;
        }

        return (explode(' ', $stat)[2] ?? 'Z') !== 'Z';
    }
}
