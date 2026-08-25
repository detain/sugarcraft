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

    /**
     * Under one pipe buffer (64 KiB on this host). The CONTROL volume: a server
     * writing this much is heard whether or not fd 2 is drained.
     */
    private const QUIET_STDERR_BYTES = 1000;

    /**
     * Over one pipe buffer, with margin. MEASURED on this host: 60000 bytes of
     * undrained stderr still lets the reply through in 0.04s, 100000 never
     * does — so the boundary is between them and this sits clear of it.
     */
    private const FLOODING_STDERR_BYTES = 200000;

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
        $pid = $this->selfReportedPid();

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

        $selfReported = $this->selfReportedPid();
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
     * A SERVER THAT LOGS MORE THAN ONE PIPE BUFFER IS STILL HEARD.
     *
     * {@see ClaudeCodeMcpClient::connect()} gives the child fd 2 as a
     * `['pipe','w']` and nothing here read it. A pipe nobody reads holds one
     * kernel buffer and then blocks the WRITER in `write(2)`, so a server that
     * logged enough never wrote its next JSON-RPC line and could not exit.
     * stderr is the CONVENTIONAL place for a stdio-transport MCP server to log,
     * because stdout is the protocol — this is the ordinary case.
     *
     * ⚠️ THE SYMPTOM IS NOT A HANG. {@see ClaudeCodeMcpClient::readMessages()}
     * returns what it has and moves on, so the caller gets an empty list ON
     * TIME while the server is stuck forever and `isConnected()` still answers
     * true. So this asserts a MESSAGE ARRIVED, never that a call was quick — a
     * timing bound is satisfied by the broken version.
     *
     * THE QUIET ROW IS THE CONTROL (rule 15): it passes with or without the
     * drain, and exists so a red on the loud row is unambiguously about VOLUME
     * rather than about the fixture, the framing or the request id. Both rows
     * red means the defect is in this test.
     */
    public function testAServerThatFloodsStderrIsStillHeard(): void
    {
        $this->assertSame(
            ['served' => true],
            $this->replyOverNoisyServer(self::QUIET_STDERR_BYTES),
            'the CONTROL row failed, so this test is not measuring what it claims: a server '
            . 'writing well under one pipe buffer must be heard whether or not fd 2 is drained'
        );

        $this->assertSame(
            ['served' => true],
            $this->replyOverNoisyServer(self::FLOODING_STDERR_BYTES),
            'a server that wrote ' . self::FLOODING_STDERR_BYTES . ' bytes to stderr was never '
            . 'heard from. fd 2 is an undrained pipe, so it is wedged in write(2) — note the '
            . 'call itself returned on time, which is why a timing bound would not catch this'
        );
    }

    /**
     * The flooding server's stderr is RETAINED and BOUNDED.
     *
     * Separate from the test above because they fail independently, and the
     * difference matters: draining to nowhere would satisfy liveness while
     * discarding the only diagnostic a wedged MCP server produces. This is also
     * the positive component that stops {@see ClaudeCodeMcpClient::stderrTail()}
     * being pinned only by an empty string.
     */
    public function testTheFloodingServersStderrIsRetainedUpToOneBufferAndNoMore(): void
    {
        $client = $this->connectedClientOverNoisy(self::FLOODING_STDERR_BYTES);

        try {
            $client->readMessages();
            $tail = $client->stderrTail();

            $this->assertNotSame(
                '',
                $tail,
                'nothing was retained, so fd 2 is being discarded rather than read — the server '
                . 'stays alive but its only diagnostic is gone'
            );
            $this->assertSame(
                str_repeat('E', strlen($tail)),
                $tail,
                'the retained stderr is not what the fixture wrote'
            );
            $this->assertLessThanOrEqual(
                65536,
                strlen($tail),
                'the retained stderr is unbounded; a server in a warning loop would then be an '
                . 'unbounded allocation in a long-lived TUI'
            );
        } finally {
            $client->disconnect();
        }
    }

    /**
     * The `result` of the first reply a noisy server sends, or null if it never
     * sent one within the fixture's own lifetime.
     *
     * WHAT THIS SAID: that the fixture answers with an ARRAY result rather than
     * a scalar because {@see \SugarCraft\Crush\McpMessage} typed `$result` as
     * `?array` and raised a `TypeError` on anything else — a real robustness gap
     * against a real server, recorded separately.
     *
     * WHAT IS TRUE NOW: that gap is closed. `$result` is `mixed`, every JSON
     * value parses, and a legal `"result": null` is told apart from an absent
     * one by the paired `resultSet` sentinel. A scalar-answering fixture would
     * work here today.
     *
     * WHY THE ARRAY STILL EARNS ITS PLACE: this row asserts `['served' => true]`,
     * i.e. that a SPECIFIC reply came back from a specific request, and an array
     * is what carries a discriminator. A scalar result would still be a reply,
     * but it would not distinguish this server's answer from any other's — and
     * the defect under test (an undrained fd 2 wedging the child) shows up as NO
     * reply, so the assertion has to be able to tell "the right one" from
     * "something".
     */
    private function replyOverNoisyServer(int $stderrBytes): mixed
    {
        $client = $this->connectedClientOverNoisy($stderrBytes);

        try {
            // `callTool()` does its own bounded polling and THROWS when the
            // reply never comes, which is exactly the wedged case. Translated
            // to null here so the caller's assertion message is the one the
            // reader sees, rather than a bare RuntimeException.
            return $client->callTool('anything')->result;
        } catch (\RuntimeException) {
            return null;
        } finally {
            $client->disconnect();
        }
    }

    private function connectedClientOverNoisy(int $stderrBytes): ClaudeCodeMcpClient
    {
        $script = $this->tempDir . '/noisy.php';
        file_put_contents($script, self::NOISY_STDERR_SERVER);
        @unlink($this->pidFile());

        $client = new ClaudeCodeMcpClient(
            PHP_BINARY,
            [$script, $this->pidFile(), (string) $stderrBytes]
        );
        $client->connect();
        $this->assertTrue($client->isConnected(), 'the noisy fixture server must have started');
        $this->selfReportedPid();

        return $client;
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
     * Speaks the protocol, but writes `$argv[2]` bytes to fd 2 first.
     *
     * THE PID FILE IS WRITTEN BEFORE THE STORM, deliberately: if the child
     * wedged before reporting itself the red would land in
     * {@see selfReportedPid()} as "never reported its pid", pointing at the
     * handshake rather than at the pipe.
     */
    private const NOISY_STDERR_SERVER = <<<'PHP'
        <?php
        file_put_contents($argv[1], (string) getmypid());
        fwrite(STDERR, str_repeat('E', (int) $argv[2]));
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            $line = fgets(STDIN);
            if ($line === false) {
                usleep(20000);
                continue;
            }
            $message = json_decode(trim($line), true);
            if (!is_array($message)) {
                continue;
            }
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => $message['id'] ?? 0,
                'result' => ['served' => true],
            ]), "\n";
            flush();
        }
        PHP;

    /**
     * {@see STUBBORN_SERVER} with SIGTERM left at its DEFAULT disposition — the
     * control that keeps that fixture's teardown bound from being satisfied by
     * a short budget rather than by the signal-9 escalation.
     *
     * IT SAID "the bound above" AND IT NO LONGER CAN. This block was stacked
     * two declarations away from the constant it describes, where PHP attached
     * it to nothing (E507); "above" pointed at whatever the file happened to
     * hold in between. The reference is to a named symbol now, which a rename
     * reds on rather than silently re-aims.
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
        $this->selfReportedPid();

        return $client;
    }

    private function pidFile(): string
    {
        return $this->tempDir . '/fixture.pid';
    }

    /**
     * The pid the fixture wrote down for ITSELF, waited for rather than assumed:
     * `connect()` returns as soon as `proc_open()` does, which is before the
     * child has necessarily run a line.
     *
     * WHAT THIS TOOK, AND WHY IT NO LONGER DOES. It took the
     * {@see ClaudeCodeMcpClient} whose child it was waiting on, which read as
     * documentation of the association. It never USED it — the pid arrives
     * through the file system, not through the client — and
     * {@see \SugarCraft\Crush\Tests\Support\DuplicatedTestHelperDriftTest}
     * caught the cost: three suites in this package now carry a byte-identical
     * copy of this helper, and a parameter list that differs while the body
     * does not is invisible to every other check in that guard. The association
     * is documented in this sentence instead, where it cannot drift.
     */
    private function selfReportedPid(): int
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

        $this->fail('the fixture child never reported its pid');
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
