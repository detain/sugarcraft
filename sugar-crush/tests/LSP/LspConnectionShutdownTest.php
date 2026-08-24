<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspConnection;

/**
 * {@see LspConnection} must not hold a caller open, and must not leave a
 * language server running — the two halves of E366 for this class, which
 * shipped with one of each.
 *
 * WHICH FAILURE MODE, ESTABLISHED BY MEASUREMENT RATHER THAN CHOSEN. The brief
 * for this work asked whether `proc_terminate()` immediately followed by
 * `proc_close()` orphans the server or blocks indefinitely, and the answer is
 * that BOTH happen, on different paths:
 *
 *  - CALLED: it BLOCKS. On this host (PHP 8.3.6, Linux 6.8), against a direct
 *    child that installs a no-op SIGTERM handler and then loops for eight
 *    seconds, the pair returned after **7.77s** with the child dead.
 *    `proc_close()` waits, so the old `stopProcess()` handed the caller's
 *    deadline to a third party's language server.
 *  - NOT CALLED: it ORPHANS. This class had no `__destruct()`, and MEASURED
 *    with the same instrument, dropping a `proc_open()` handle whose child is
 *    still RUNNING takes **0.000s** and leaves it in state `S`. The resource
 *    destructor reaps an already-exited child — a zombie goes to `GONE`
 *    instantly — but never waits for a live one. A connection that went out of
 *    scope therefore left a server running under pid 1, holding every inherited
 *    descriptor above 2.
 *
 * The second is the more dangerous of the two and the one no reader expects,
 * because "PHP closes the resource for you" is true and irrelevant: closing the
 * handle is not waiting for the process.
 *
 * EVERY FIXTURE GOES THROUGH THE REAL `connect()`. `proc_terminate()` signals
 * the DIRECT CHILD and nothing else, so a SIGTERM-ignoring handler only
 * exercises the escalation if it is installed there — and whether the direct
 * child is the server at all is a claim about `connect()`'s `proc_open()` call
 * shape, which no injected fixture can test. See
 * {@see testTheServerIsTheDirectChildAndNotAShellWrapper()}.
 */
final class LspConnectionShutdownTest extends TestCase
{
    /**
     * Comfortably above the 1.0s + 1.0s escalation and far below the 7.77s the
     * old pair measured, so neither a loaded box nor a regression is ambiguous.
     */
    private const BOUND_SECONDS = 4.0;

    /**
     * Tighter than {@see BOUND_SECONDS}, because {@see __destruct()} has no
     * protocol phase to pay for: it is the ~1.0s SIGTERM grace plus the reap,
     * and nothing else. Sized to sit between that and the 8.0s request timeout
     * {@see testDroppingTheLastReferenceKillsTheServer()} configures precisely
     * so a destructor that sends `shutdown` cannot slip under it.
     */
    private const DESTRUCTOR_BOUND_SECONDS = 3.0;

    /**
     * Under one pipe buffer (64 KiB on this host). The control volume: a server
     * writing this much is answered whether or not fd 2 is drained.
     */
    private const QUIET_STDERR_BYTES = 1000;

    /**
     * Over one pipe buffer, with margin. MEASURED: 60000 bytes still drains in
     * 0.04s undrained, 100000 never completes — so the boundary is between
     * them and this sits clear of it on the failing side.
     */
    private const FLOODING_STDERR_BYTES = 200000;

    private string $tempDir = '';

    /** @var list<int> pids a fixture reported for itself, killed on the way out */
    private array $reportedPids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_lsp_shutdown_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // ONLY pids this test's own fixtures wrote down. Never a pattern sweep,
        // never a global kill — sibling suites run concurrently in this tree.
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
        $connection = $this->connectedOver(self::STUBBORN_SERVER);

        $start = microtime(true);
        $connection->disconnect();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            self::BOUND_SECONDS,
            $elapsed,
            sprintf(
                'disconnect() took %.2fs; a language server that ignores SIGTERM must not hold the caller '
                . 'open (the pre-fix proc_terminate+proc_close pair measured 7.77s here)',
                $elapsed,
            ),
        );
    }

    /**
     * THE CONTROL that makes the bound above mean something: a server that
     * HONOURS SIGTERM is not merely bounded, it is quick — so the assertion is
     * not passing because the escalation budget happens to be short. Without
     * this, shrinking both grace constants would read as an improvement.
     */
    public function testDisconnectReturnsPromptlyWhenTheServerHonoursSigterm(): void
    {
        $connection = $this->connectedOver(self::WELL_BEHAVED_SERVER);

        $start = microtime(true);
        $connection->disconnect();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            1.0,
            $elapsed,
            sprintf(
                'a server that answers `shutdown` and leaves on `exit` must pay neither the request '
                . 'timeout nor the escalation budget; took %.2fs',
                $elapsed,
            ),
        );
    }

    /**
     * Bounded is not enough: the server must be GONE. A `disconnect()` that
     * returned quickly by abandoning the process would satisfy the timing
     * assertion above and leave precisely the orphan this change exists to
     * prevent.
     */
    public function testTheIgnoringServerIsDeadAfterDisconnectReturns(): void
    {
        $connection = $this->connectedOver(self::STUBBORN_SERVER);
        $pid = $this->selfReportedPid();

        $connection->disconnect();

        $this->assertFalse(
            $this->isAlive($pid),
            "pid {$pid} survived disconnect(); signal 9 must have reached the direct child",
        );
    }

    /**
     * THE DESTRUCTOR HALF, and the one that was missing entirely.
     *
     * Dropping the last reference to a connected {@see LspConnection} must kill
     * the server. Before `__destruct()` existed the resource destructor ran, took
     * 0.000s, and left the child in state `S` — measured; see the class docblock.
     * `gc_collect_cycles()` after the `unset()` because a destructor is only
     * guaranteed at refcount zero and this object is not in a cycle, but the call
     * costs nothing and removes the doubt.
     */
    public function testDroppingTheLastReferenceKillsTheServer(): void
    {
        // A DELIBERATELY LARGE REQUEST TIMEOUT, and it is the whole point of the
        // second assertion. The destructor must reach the teardown ladder
        // WITHOUT sending `shutdown` first, so the two behaviours have to be
        // separable on the clock: with this timeout, a destructor that speaks
        // the protocol against a fixture that never answers pays
        // 8s + the ~1s ladder, while the correct one pays only the ladder.
        // At the file's default of 2.0s the two were 3.0s and 1.0s, both under
        // BOUND_SECONDS — and MEASURED, a mutation swapping `stopProcess()` for
        // `disconnect()` in the destructor SURVIVED. The window was the fault,
        // not the mutation.
        $connection = $this->connectedOver(self::STUBBORN_SERVER, 8.0);
        $pid = $this->selfReportedPid();
        $this->assertTrue($this->isAlive($pid), 'the fixture server must be running before the drop');

        $start = microtime(true);
        unset($connection);
        gc_collect_cycles();
        $elapsed = microtime(true) - $start;

        $this->assertFalse(
            $this->isAlive($pid),
            "pid {$pid} survived the drop; without __destruct() the proc_open resource destructor "
            . 'abandons a RUNNING child (0.000s, state S) instead of waiting for it',
        );

        $this->assertLessThan(
            self::DESTRUCTOR_BOUND_SECONDS,
            $elapsed,
            sprintf(
                '__destruct() took %.2fs against an 8.0s request timeout; it must run the bounded '
                . 'teardown ladder directly and must NOT speak the LSP shutdown protocol, which would '
                . 'put a request timeout at whatever arbitrary point the last reference is dropped',
                $elapsed,
            ),
        );
    }

    /**
     * THE BOUND THAT WAS DEAD CODE. {@see LspConnection::sendRequest()} computes
     * `microtime(true) + $this->requestTimeout` and {@see readResponse()} loops
     * on it — and none of that ran, because `connect()` left the server's stdout
     * BLOCKING and the first `fread()` inside `readMessage()` sat in the kernel
     * until the server wrote or exited. The deadline was never re-tested.
     *
     * MEASURED before the fix, with `$timeout = 2.0` against this same
     * answers-nothing fixture: **20.02s**, i.e. exactly the fixture's own
     * lifetime, on a connection that had asked for two seconds. That is what
     * made every `disconnect()` in this file unbounded regardless of the
     * teardown ladder — the `shutdown` request ate the whole budget before
     * `stopProcess()` was reached.
     *
     * A CEILING AND A FLOOR. The ceiling catches the regression. The floor is
     * there because a `sendRequest()` that returned instantly — a broken pipe, a
     * `readMessage()` that threw the deadline away in the other direction —
     * would satisfy a ceiling-only assertion while enforcing nothing.
     */
    public function testARequestIsBoundedByItsOwnTimeoutWhenTheServerNeverAnswers(): void
    {
        $connection = $this->connectedOver(self::STUBBORN_SERVER);

        $start = microtime(true);
        $response = $connection->sendRequest('textDocument/hover', []);
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThan(
            1.5,
            $elapsed,
            sprintf('the request returned in %.2fs, well under its 2.0s budget — it is not waiting at all', $elapsed),
        );
        $this->assertLessThan(
            5.0,
            $elapsed,
            sprintf(
                'the request took %.2fs against a 2.0s timeout; a blocking stdout pipe makes the deadline '
                . 'in readResponse() unreachable (measured 20.02s before stream_set_blocking(false))',
                $elapsed,
            ),
        );
        $this->assertTrue($response->isError, 'a request that timed out must report an error');

        $connection->disconnect();
    }

    /**
     * THE ONE THAT IS ABOUT `connect()`. The server's OWN pid must be the pid
     * `proc_get_status()` reports for the direct child. Under a shell string
     * these are two different processes (dash and its child), so every signal
     * lands on the wrapper: the timing bounds above would still pass while the
     * server kept running. Mutating `connect()`'s argv into an
     * `escapeshellarg()`ed string reds here.
     */
    public function testTheServerIsTheDirectChildAndNotAShellWrapper(): void
    {
        $connection = $this->connectedOver(self::STUBBORN_SERVER);
        $selfReported = $this->selfReportedPid();

        $handle = new \ReflectionProperty(LspConnection::class, 'process');
        $directChild = (int) proc_get_status($handle->getValue($connection))['pid'];

        $this->assertSame(
            $selfReported,
            $directChild,
            "proc_open()'s direct child (pid {$directChild}) must BE the language server "
            . "(pid {$selfReported}); a shell wrapper in between is what leaves servers running",
        );

        $connection->disconnect();
    }

    /**
     * `disconnect()` on a connection that never connected, and twice over, are
     * both no-ops — {@see LspConnection::__destruct()} calls into the same
     * teardown after any explicit call, so a double teardown is the NORMAL path.
     */
    /**
     * A SERVER THAT LOGS MORE THAN ONE PIPE BUFFER STILL GETS ANSWERED.
     *
     * {@see LspConnection::connect()} gives the child fd 2 as a `['pipe','w']`,
     * and nothing in the class read it. A pipe nobody reads holds one kernel
     * buffer and then blocks the WRITER in `write(2)` — so a server that logged
     * enough never got to write its next response and could not exit either.
     *
     * ⚠️ THE SYMPTOM IS NOT A HANG ON THIS SIDE, WHICH IS HOW IT SURVIVED
     * REVIEW. Every read path here is deadline-bounded, so the CALLER returns
     * on time with nothing while the SERVER is stuck forever, and
     * `isConnected()` keeps answering true. This test therefore asserts the
     * ANSWER ARRIVED, not that the call returned quickly — a bound alone is
     * satisfied by the broken version.
     *
     * MEASURED with this exact descriptor spec (PHP 8.3.6, Linux 6.8, 64 KiB
     * pipe buffer), fd 1 non-blocking, fd 2 never read, 5.0s deadline / 5ms
     * poll, three consecutive takes: 1000 and 60000 bytes of stderr both
     * deliver the header in 0.04s, 100000 never delivers it at all.
     *
     * THE QUIET CASE IS THE CONTROL (rule 15). {@see QUIET_STDERR_BYTES} sits
     * under one buffer and passes with or without the drain; it is here so that
     * a failure of the loud case is unambiguously about VOLUME and not about
     * the fixture, the framing, or the request id. If both rows go red, the
     * defect is in this test.
     */
    public function testAServerThatFloodsStderrIsStillAnsweredAndItsStderrIsKept(): void
    {
        // Comfortably under one pipe buffer: passes either way, by design.
        $quiet = $this->requestOverNoisyServer(self::QUIET_STDERR_BYTES);
        $this->assertSame(
            'SERVED',
            $quiet->result,
            'the CONTROL row failed, so this test is not measuring what it claims: a server '
            . 'writing well under one pipe buffer must be answered whether or not fd 2 is drained'
        );

        // Comfortably over it. Without the drain the server blocks in write(2)
        // before it ever reads the request.
        $loud = $this->requestOverNoisyServer(self::FLOODING_STDERR_BYTES);
        $this->assertSame(
            'SERVED',
            $loud->result,
            'a server that wrote ' . self::FLOODING_STDERR_BYTES . ' bytes to stderr never '
            . 'answered. fd 2 is an undrained pipe, so the server is wedged in write(2) — note '
            . 'that the request itself returned on time, which is why a timing bound would not '
            . 'have caught this'
        );
    }

    /**
     * The stderr a flooding server wrote is RETAINED, and BOUNDED.
     *
     * Separate from the test above because they can fail independently and the
     * distinction matters: draining to `/dev/null` would satisfy the liveness
     * claim while throwing away the only diagnostic a wedged language server
     * ever produces. This is also the positive component that stops
     * {@see LspConnection::stderrTail()} being pinned only by an empty string.
     */
    public function testTheFloodingServersStderrIsRetainedUpToOneBufferAndNoMore(): void
    {
        $connection = $this->connectedOverNoisy(self::FLOODING_STDERR_BYTES);

        try {
            $connection->sendRequest('textDocument/definition', []);
            $tail = $connection->stderrTail();

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
            $connection->disconnect();
        }
    }

    /** One request against a server that wrote $stderrBytes to fd 2 first. */
    private function requestOverNoisyServer(int $stderrBytes): \SugarCraft\Crush\LSP\LspResponse
    {
        $connection = $this->connectedOverNoisy($stderrBytes);

        try {
            return $connection->sendRequest('textDocument/definition', []);
        } finally {
            $connection->disconnect();
        }
    }

    private function connectedOverNoisy(int $stderrBytes): LspConnection
    {
        $script = $this->tempDir . '/noisy.php';
        file_put_contents($script, self::NOISY_STDERR_SERVER);
        @unlink($this->pidFile());

        $connection = new LspConnection($script, [$script, $this->pidFile(), (string) $stderrBytes]);
        // Generous relative to the 0.04s a healthy answer takes, and far below
        // the fixture's own 20s lifetime, so a timeout here means wedged.
        $connection->connect(PHP_BINARY, [], null, 6.0);
        $this->assertTrue($connection->isConnected(), 'the noisy fixture server must have started');
        $this->selfReportedPid();

        return $connection;
    }

    public function testDisconnectIsIdempotentAndSafeUnconnected(): void
    {
        $never = new LspConnection('/nonexistent-lsp');
        $never->disconnect();
        $never->disconnect();
        $this->assertFalse($never->isConnected());

        $connection = $this->connectedOver(self::WELL_BEHAVED_SERVER);
        $connection->disconnect();
        $connection->disconnect();
        $this->assertFalse($connection->isConnected());
    }

    /**
     * Ignores SIGTERM, and reports its pid ONLY ONCE THE HANDLER IS INSTALLED.
     *
     * THE ORDER OF THOSE LINES IS THE FIXTURE. Written the other way round, the
     * sibling suite's equivalent let a "remove the signal-9 escalation" mutation
     * SURVIVE: teardown ran before the child reached `pcntl_signal()`, so SIGTERM
     * killed it at its DEFAULT disposition and the bound was quietly measuring a
     * well-behaved server. The pid file is the readiness handshake, and
     * {@see connectedOver()} waits on it before returning.
     *
     * A POLLING loop rather than one long `usleep()`: a signal interrupts
     * `usleep()` and the script would then simply END, which is indistinguishable
     * from a well-behaved exit and would make the fixture useless in the other
     * direction.
     *
     * It never answers a single LSP message, which is deliberate — nothing here
     * is about the protocol, and a fixture that spoke it would be able to fail
     * for protocol reasons.
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
     * A COOPERATIVE server: it speaks enough LSP to answer `shutdown` and to
     * leave on `exit`, and it does not touch SIGTERM.
     *
     * WHY IT HAS TO ANSWER, and what the first version of it measured instead.
     * {@see LspConnection::disconnect()} sends a `shutdown` REQUEST before it
     * reaches the teardown ladder, and that request is bounded by the
     * connection's own `requestTimeout`. A fixture that merely sat there — which
     * is what this constant used to be — made the "prompt" control take 2.01s,
     * the whole request timeout, and the assertion was then measuring the
     * PROTOCOL wait while claiming to measure the escalation budget. Two
     * different clocks, one number.
     *
     * Answering makes the control say what it is for: against a server that
     * behaves, `disconnect()` completes the handshake, the server exits on its
     * own, and
     * {@see \SugarCraft\Crush\Support\ProcessReaper::terminateAndClose()}
     * finds a process that is already gone — so no signal is sent and NO part of
     * the escalation budget is paid. That is the claim the bound below is
     * allowed to make.
     */
    /**
     * Well-behaved on stdout, LOUD on stderr: writes `$argv[2]` bytes to fd 2
     * before serving a single request.
     *
     * THE PID FILE IS WRITTEN FIRST, DELIBERATELY. If the stderr storm came
     * first the server would wedge before reporting itself and the failure
     * would surface in {@see selfReportedPid()} as "never reported its pid" —
     * true, but pointing at the handshake rather than at the pipe. Reporting
     * first puts the red where the defect is: on the request.
     */
    private const NOISY_STDERR_SERVER = <<<'PHP'
        <?php
        file_put_contents($argv[1], (string) getmypid());
        fwrite(STDERR, str_repeat('E', (int) $argv[2]));
        $buffer = '';
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            $chunk = fread(STDIN, 8192);
            if ($chunk === false || ($chunk === '' && feof(STDIN))) {
                break;
            }
            $buffer .= $chunk;
            while (($sep = strpos($buffer, "\r\n\r\n")) !== false) {
                $headers = substr($buffer, 0, $sep);
                $length = 0;
                foreach (explode("\r\n", $headers) as $header) {
                    if (str_starts_with($header, 'Content-Length:')) {
                        $length = (int) trim(substr($header, 15));
                    }
                }
                if (strlen($buffer) < $sep + 4 + $length) {
                    break;
                }
                $body = substr($buffer, $sep + 4, $length);
                $buffer = substr($buffer, $sep + 4 + $length);
                $message = json_decode($body, true);
                if (($message['method'] ?? '') === 'exit') {
                    exit(0);
                }
                if (isset($message['id'])) {
                    $reply = json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => 'SERVED']);
                    fwrite(STDOUT, "Content-Length: " . strlen($reply) . "\r\n\r\n" . $reply);
                    fflush(STDOUT);
                }
            }
        }
        PHP;

    private const WELL_BEHAVED_SERVER = <<<'PHP'
        <?php
        file_put_contents($argv[1], (string) getmypid());
        $buffer = '';
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            $chunk = fread(STDIN, 8192);
            if ($chunk === false || ($chunk === '' && feof(STDIN))) {
                break;
            }
            $buffer .= $chunk;
            while (($sep = strpos($buffer, "\r\n\r\n")) !== false) {
                $headers = substr($buffer, 0, $sep);
                $length = 0;
                foreach (explode("\r\n", $headers) as $header) {
                    if (str_starts_with($header, 'Content-Length:')) {
                        $length = (int) trim(substr($header, 15));
                    }
                }
                if (strlen($buffer) < $sep + 4 + $length) {
                    break;
                }
                $body = substr($buffer, $sep + 4, $length);
                $buffer = substr($buffer, $sep + 4 + $length);
                $message = json_decode($body, true);
                if (($message['method'] ?? '') === 'exit') {
                    exit(0);
                }
                if (isset($message['id'])) {
                    $reply = json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => null]);
                    fwrite(STDOUT, "Content-Length: " . strlen($reply) . "\r\n\r\n" . $reply);
                    fflush(STDOUT);
                }
            }
        }
        PHP;

    /**
     * A connected {@see LspConnection} whose direct child is $source, spawned
     * through the REAL `connect()`, and RETURNED ONLY ONCE THE CHILD IS READY.
     *
     * `initialize()` is deliberately never called: it would block for the full
     * request timeout against a fixture that answers nothing, and
     * `connect()` already sets the `initialized` flag that `disconnect()`
     * branches on — so the path under test is reached without it.
     */
    private function connectedOver(string $source, float $requestTimeout = 2.0): LspConnection
    {
        $script = $this->tempDir . '/server.php';
        file_put_contents($script, $source);
        @unlink($this->pidFile());

        $connection = new LspConnection($script, [$script, $this->pidFile()]);
        $connection->connect(PHP_BINARY, [], null, $requestTimeout);
        $this->assertTrue($connection->isConnected(), 'the fixture server must have started');

        $this->selfReportedPid();

        return $connection;
    }

    private function pidFile(): string
    {
        return $this->tempDir . '/fixture.pid';
    }

    /**
     * The pid the fixture wrote down for ITSELF, waited for rather than assumed:
     * `connect()` returns as soon as `proc_open()` does, which is before the
     * child has necessarily run a line.
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

        // A pid that exited but has not been waited for is a ZOMBIE, and
        // `posix_kill($pid, 0)` answers true for one. Reading the state field is
        // what separates "still running" from "dead and already reaped".
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return false;
        }

        return (explode(' ', $stat)[2] ?? 'Z') !== 'Z';
    }
}
