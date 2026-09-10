<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspConnection;
use SugarCraft\Crush\LSP\LspResponse;

/**
 * E475, CLOSED ON THE ONLY MECHANISM THIS ARCHITECTURE CAN RUN SAFELY: the idle
 * stderr drain is OWNER-PUMPED, never scheduled by the class.
 *
 * What was broken: fd 2 was read only from inside an exchange — every pass of
 * {@see LspConnection::writeMessage()}, every {@see LspConnection::refill()},
 * and once in `stopProcess()`. A server that logs while the parent IDLES fills
 * the 65536-byte pipe and blocks in `write(2)` until the next exchange's first
 * pass frees it (E503 measured the self-heal at ~20 ms into that exchange).
 *
 * What was claimed as the fix, and why it is NOT what shipped: "fd 2 on the
 * ReactPHP loop". E537 measured that against this tree's dispatch and found it
 * worse than the stall — `Chat` forks one child per tool call, so a loop-mounted
 * reader in the parent races a forked child's in-exchange drains over ONE pipe,
 * and `read(2)` is destructive: each process's {@see LspConnection::stderrTail()}
 * silently loses whichever lines the other consumed.
 *
 * The shipped cadence is therefore, and only: (1) the existing per-pass drains,
 * (2) a per-ITERATION drain in `readResponse()` so a pump pass answered entirely
 * from {@see LspConnection::$readBuffer} still reads fd 2, and (3)
 * {@see LspConnection::pumpStderr()} — one bounded, non-blocking drain the owner
 * process calls between exchanges, which is E537's own prescription ("a safe
 * drain has to happen while no forked call is in flight") given its class-side
 * seam; the WHEN stays the dispatch layer's judgement.
 *
 * ⚠️ THESE ROWS PIN THE SEAM, NOT A SCHEDULER. Nothing in `src/` calls
 * `pumpStderr()` yet — wiring the parent's between-turns tick is a `Chat` /
 * `Runtime` change in a different lane's files, and until it lands the idle gap
 * remains open BY DESIGN. {@see LspConnectionStdinWedgeTest}'s E475 severity row
 * still asserts the stall on the un-pumped path, and stays green precisely
 * because this class never drains on its own initiative.
 *
 * FIXTURE SHAPE, and why it is handshake-driven rather than sleep-driven: the
 * child announces it has ENTERED its stderr write with one marker file and that
 * the write has COMPLETED with another; the parent waits on the files, never on
 * the clock (only the flood row's "is it really blocked?" negative check uses a
 * bounded settle). Fixture lifetime is 20s — strictly below PHPUnit's 60s
 * `defaultTimeLimit` and strictly above every bound in this file, per E505: a
 * row here must be able to FAIL BY ASSERTION, not abort.
 */
final class LspConnectionStderrIdleDrainTest extends TestCase
{
    /** First bytes the fixture writes to fd 2. */
    private const MARKER = "LSP-IDLE-STDERR-MARKER\n";

    /** Above the 65536 pipe capacity, inside ONE drain pass (16 × 8192). */
    private const FLOOD_BYTES = 100000;

    /** The ring cap in LspConnection (MAX_STDERR_BYTES is private there). */
    private const TAIL_CAP = 65536;

    /** Seconds the fixture outlives every assertion in this file. */
    private const FIXTURE_LIFETIME_SECONDS = 20.0;

    /** Upper bound for waiting on a fixture handshake file. */
    private const HANDSHAKE_BOUND_SECONDS = 3.0;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_lsp_idle_drain_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    /**
     * THE HEADLINE. A child floods fd 2 PAST THE PIPE CAPACITY WHILE NO EXCHANGE
     * IS IN FLIGHT — the exact shape E475 named unread — and ONE
     * {@see LspConnection::pumpStderr()} call consumes the pipe and releases the
     * writer.
     *
     * The release is observable, not asserted into existence: the child parked
     * its `fwrite()` at 100000 > 65536 bytes, so its DONE file can appear only
     * once ≥ 34464 bytes have been read out of fd 2. The negative control before
     * the tick pins the child really is blocked (DONE still absent after a
     * settle); the tick after it is therefore the CAUSE. One pass absorbs up to
     * 131072 bytes, so a single call must be enough — if it is not, the row goes
     * red on the timeout naming the bytes consumed, which is the regression
     * signal, not a fixture race.
     */
    public function testOnePumpStderrCallConsumesAFloodWrittenWhileNoExchangeIsInFlight(): void
    {
        $started = $this->tempDir . '/started';
        $done = $this->tempDir . '/done';
        $connection = $this->connectFixture(
            $started,
            $done,
            sprintf('$s = str_repeat("e", %d - strlen($m)); fwrite(STDERR, $m . $s);', self::FLOOD_BYTES),
        );

        try {
            $this->waitForFile($started, 'the fixture never began its stderr flood');
            $this->assertSame(
                '',
                $connection->stderrTail(),
                'fd 2 was consumed before any pump tick — nothing may drain between exchanges '
                . 'until pumpStderr() is called; a pass here means the drain became '
                . 'self-scheduled (the E537 hazard) and this assertion is the witness',
            );

            usleep(200000);
            $this->assertFileDoesNotExist(
                $done,
                sprintf(
                    'the fixture completed a %d-byte stderr write with NOTHING draining fd 2, so '
                    . 'the pipe buffer on this host exceeds the flood and this row cannot '
                    . 'discriminate — raise FLOOD_BYTES rather than trusting the tick below',
                    self::FLOOD_BYTES,
                ),
            );

            $connection->pumpStderr();

            $this->waitForFile($done, sprintf(
                'one pumpStderr() pass did not release a child blocked after %d bytes of stderr — '
                . 'the tick consumed %d bytes although one pass is bounded at 131072 and the '
                . 'flood is inside one pass; a surviving drain gap here is the E475 regression '
                . 'this row exists to catch',
                self::FLOOD_BYTES,
                strlen($connection->stderrTail()),
            ));

            $tail = $connection->stderrTail();
            $this->assertSame(
                self::TAIL_CAP,
                strlen($tail),
                'the tail is neither the cap-length ring of a fully absorbed flood nor explained '
                . 'by the drain bound — tail bytes: ' . strlen($tail),
            );
            $this->assertStringEndsWith(
                str_repeat('e', 4096),
                $tail,
                'the tick consumed the wrong bytes; flood order is marker-then-padding, so the '
                . 'kept tail must run out in padding',
            );
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * THE IDLE GAP AT SMALL SCALE, pinning ROUTING AND ORDERING: a couple of
     * idle lines sit unread in the pipe (the gap E475 named — asserted empty
     * tail BEFORE the tick), and the owner's one pump tick takes them whole, in
     * order, into the SAME {@see LspConnection::stderrTail()} every in-exchange
     * drain writes — no new destination invented.
     */
    public function testIdleStderrLinesLandInTheSameTailAsExchangeDrainedOnes(): void
    {
        $started = $this->tempDir . '/started';
        $done = $this->tempDir . '/done';
        $connection = $this->connectFixture(
            $started,
            $done,
            'fwrite(STDERR, $m); fwrite(STDERR, "second-line\n");',
        );

        try {
            // DONE, not STARTED: the handshake that matters here is "the bytes
            // have LEFT the child", so the pump below cannot race the writer.
            $this->waitForFile($done, 'the fixture never finished its idle stderr lines');
            $this->assertSame(
                '',
                $connection->stderrTail(),
                'something drained fd 2 before the pump tick — the idle gap this row pins closed '
                . 'itself, which means either the drain became self-scheduled (E537 hazard) or an '
                . 'exchange ran; either way this assertion is the witness and it must not blink',
            );

            $connection->pumpStderr();

            $this->assertStringStartsWith(
                self::MARKER . 'second-line',
                $connection->stderrTail(),
                'pumpStderr() did not take both idle lines in one tick, or took them out of order',
            );
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * THE PER-ITERATION HALF OF THE CADENCE: a `readResponse()` pass answered
     * ENTIRELY from {@see LspConnection::$readBuffer} — header and body both
     * already present, so {@see LspConnection::refill()} is never reached —
     * still drains fd 2 on that pass.
     *
     * This is the iteration the old three-site cadence skipped: the read-side
     * drain lived inside `refill()`, and a buffered message returns without one.
     * The row seeds the buffer through reflection and drives the private pump
     * against a server that will NEVER write to stdout, so if the
     * per-iteration line is removed the response still resolves cleanly while
     * the tail stays empty — red on the exact property, with no timing
     * dependence (the fixture's bytes provably left the child via the DONE
     * file, and only this class's pipe can read them).
     */
    public function testAPumpIterationSatisfiedEntirelyFromTheBufferStillDrainsStderr(): void
    {
        $started = $this->tempDir . '/started';
        $done = $this->tempDir . '/done';
        // Writes to fd 2 only; stdout stays silent for the fixture's lifetime.
        $connection = $this->connectFixture($started, $done, 'fwrite(STDERR, $m);');

        try {
            $this->waitForFile($done, 'the fixture never finished its stderr line');
            $this->assertSame('', $connection->stderrTail());

            $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => '7', 'result' => ['buffered' => true]]);
            $frame = 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;

            $buffer = new \ReflectionProperty(LspConnection::class, 'readBuffer');
            $buffer->setValue($connection, $frame);

            $readResponse = new \ReflectionMethod(LspConnection::class, 'readResponse');
            $response = $readResponse->invoke($connection, '7', microtime(true) + 5.0);
            $this->assertInstanceOf(LspResponse::class, $response);

            $this->assertFalse($response->isError);
            $this->assertFalse($response->isTimeout());
            $this->assertSame(true, $response->result['buffered'] ?? null);
            $this->assertStringContainsString(
                self::MARKER,
                $connection->stderrTail(),
                'readResponse() returned a message the buffer already held WITHOUT reading fd 2 '
                . "on that pass — the per-iteration drain is gone and the cadence rule 'every "
                . 'pump iteration drains\' is prose again',
            );
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * `pumpStderr()` must be SAFE TO CALL WHEN THERE IS NOTHING TO PUMP: the
     * dispatch layer's between-turns tick will fire on connections that never
     * connected and on ones already disconnected, and a throw on those paths
     * would put teardown races into the TUI loop. Before connect: `pipes` is
     * null. After disconnect: the pipe resources are closed, and a bare
     * `fread()`/`stream_select()` on a closed pipe resource THROWS where `@`
     * would not suppress it (see the measurement in `drainStderr()`).
     */
    public function testPumpingStderrIsANoOpBeforeConnectAndAfterDisconnect(): void
    {
        $neverConnected = new LspConnection('unset');
        $neverConnected->pumpStderr();
        $this->assertSame('', $neverConnected->stderrTail());

        $started = $this->tempDir . '/started';
        $done = $this->tempDir . '/done';
        $connection = $this->connectFixture($started, $done, 'fwrite(STDERR, $m);');
        $this->waitForFile($done, 'the fixture never finished its stderr line');
        $connection->disconnect();
        $afterTeardown = $connection->stderrTail();

        $connection->pumpStderr();
        $this->assertSame(
            $afterTeardown,
            $connection->stderrTail(),
            'pumpStderr() changed the tail after teardown — the drain guards must make it inert '
            . 'once the pipes are closed, not half-alive',
        );
    }

    // =========================================================================
    // Fixture plumbing
    // =========================================================================

    /**
     * Spawn `php <script>` through the real connection: the script announces on
     * $started, runs $stderrBody, announces on $done, then idles out its
     * lifetime. Announce-before / announce-after is what lets the flood row
     * observe a child BLOCKED mid-write while the quiet rows wait on a write
     * that has provably finished.
     */
    private function connectFixture(string $startedFile, string $doneFile, string $stderrBody): LspConnection
    {
        $script = $this->tempDir . '/fake_server.php';
        file_put_contents($script, sprintf(
            "<?php\n\$m = %s;\nfile_put_contents(%s, '1');\n%s\nfile_put_contents(%s, '1');\n"
            . 'usleep(%d);' . "\n",
            var_export(self::MARKER, true),
            var_export($startedFile, true),
            $stderrBody,
            var_export($doneFile, true),
            (int) (self::FIXTURE_LIFETIME_SECONDS * 1000000),
        ));

        $connection = new LspConnection($script, [$script]);
        $connection->connect(PHP_BINARY, [], null, 10.0);

        return $connection;
    }

    /**
     * Wait up to {@see HANDSHAKE_BOUND_SECONDS} for a fixture handshake file.
     * Timeout fails the row naming $context — the bound only decides WHO waits,
     * never WHETHER the property is asserted.
     */
    private function waitForFile(string $path, string $context): void
    {
        $bound = microtime(true) + self::HANDSHAKE_BOUND_SECONDS;
        while (!file_exists($path)) {
            if (microtime(true) >= $bound) {
                $this->fail(sprintf(
                    'timed out after %.1fs waiting for the fixture file %s: %s',
                    self::HANDSHAKE_BOUND_SECONDS,
                    basename($path),
                    $context,
                ));
            }
            usleep(20000);
        }
    }
}
