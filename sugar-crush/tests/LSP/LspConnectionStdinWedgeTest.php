<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspConnection;

/**
 * A LANGUAGE SERVER THAT TALKS TOO MUCH ON STDERR MUST NOT WEDGE THE EDITOR, AND
 * A MESSAGE THAT DOES NOT FIT IN ONE WRITE MUST NOT BE HALF-SENT.
 *
 * {@see LspConnection::connect()} took fds 1 and 2 out of blocking mode and left
 * fd 0 in it, and {@see LspConnection::writeMessage()} was a single `@fwrite()`
 * that checked only `=== false`. Two defects:
 *
 *  1. THE DEADLOCK. A server parked in `write(2)` on a full stderr pipe is not
 *     reading its stdin either. Once the parent's own stdin buffer filled, the
 *     blocking `fwrite()` held the only thread that could have run
 *     {@see LspConnection::drainStderr()} and released the server.
 *  2. THE SILENT DESYNC. A short `fwrite()` returns an int below `strlen()`, not
 *     `false`, so a partial message was reported as sent. The header had already
 *     promised the server N bytes; `Content-Length` framing has no
 *     resynchronisation point, so every subsequent reply would be parsed against
 *     the fragment.
 *
 * ⚠️ THE THRESHOLD IS ONE DRAIN PASS, NOT ONE PIPE BUFFER, AND GETTING THAT
 * WRONG MADE THIS FILE VACUOUS ONCE ALREADY. The obvious figure is the 65536-byte
 * pipe capacity, and it is the one the NDJSON sibling
 * ({@see \SugarCraft\Crush\Tests\MCP\StdioMcpServerStderrDrainTest}) uses. It
 * is the WRONG figure here, because the two write loops drain differently:
 * `StdioMcpServer::writeLine()` SELECTS on fd 2, whereas
 * {@see LspConnection::writeMessage()} calls {@see LspConnection::drainStderr()}
 * unconditionally BEFORE each write attempt — and that one call absorbs up to
 * 16 × 8192 = 131072 bytes. A flood that fits inside a single pass is emptied
 * before the write ever starts, the child goes back to reading, and a BLOCKING
 * fd 0 completes the write anyway.
 *
 * Generator: a child that writes N bytes to stderr and then reads stdin forever;
 * a parent that performs one 16 × 8192 drain pass and then a BLOCKING write of M
 * bytes; a 5s external bound — THE GENERATOR'S OWN, not this file's
 * {@see self::BOUND_SECONDS}, which is 8.0 and bounds the suite's probes
 * instead (the failure is an `fwrite()` that never returns, so nothing inside
 * the process can observe it and the clock has to be outside). PHP 8.3.6,
 * Linux 6.8, three consecutive takes, identical every time:
 *
 *     N=100000  M=200000  -> drained 100000, wrote 200000, 0.00s  <- ONE PASS ATE IT
 *     N=200000  M=200000  -> WEDGED (bound hit)
 *     N=400000  M=200000  -> WEDGED (bound hit)
 *     N=1000000 M=200000  -> WEDGED (bound hit)
 *     N=1000000 M=1000    -> drained 131072, wrote 1000, 0.00s    <- write under capacity
 *
 * The first row is the one that matters: it is where {@see WEDGE_BYTES} used to
 * sit, and with it there the mutation that puts fd 0 back into blocking mode
 * SURVIVED the whole file. The last row is the control that says the wedge needs
 * both sides to be oversized.
 *
 * ⚠️ PHP VERSION AND KERNEL ARE PART OF THESE FIGURES. 65536 is Linux's default
 * pipe capacity and 131072 is this class's own 16-read drain bound; neither is a
 * PHP constant. The tests do not depend on the exact numbers — they depend on
 * {@see WEDGE_BYTES} being comfortably above both — but the numbers here are a
 * measurement of this host and nothing else.
 *
 * THE WEDGE ROWS ARE OBSERVED FROM OUTSIDE, in a child `php` process with this
 * process holding the clock. Unfixed, a blocking fd 0 does not fail slowly, it
 * does not return at all, so an in-process assertion would hang PHPUnit rather
 * than report anything. Same instrument the rest of the process-shaped tests in
 * this suite use — nothing installed, nothing networked.
 */
final class LspConnectionStdinWedgeTest extends TestCase
{
    /**
     * Above BOTH the 65536-byte pipe capacity and the 131072-byte single-drain
     * pass — see the class doc-block for why the second bound is the binding one
     * and what happened when this constant only cleared the first. 400000 leaves
     * three passes of headroom, so the row stays discriminating if this class's
     * 16-read drain bound is ever raised.
     */
    private const WEDGE_BYTES = 400000;

    /** Below both: the child never blocks, so the control rows pass either way. */
    private const SAFE_BYTES = 1000;

    /**
     * A request payload big enough to overfill the parent's OWN stdin pipe. An
     * LSP `textDocument/didOpen` carrying a source file reaches this routinely.
     */
    private const OVERSIZED_BYTES = 200000;

    /** Small enough to fit in one pipe buffer with the framing header. */
    private const SMALL_BYTES = 1000;

    /**
     * The request timeout handed to the fixtures. Generous next to the 0.4s the
     * drained path measures, so a row that fails is failing on the DRAIN and not
     * on a budget that was too tight to begin with.
     */
    private const REQUEST_TIMEOUT_SECONDS = 10.0;

    /**
     * The external clock, deliberately BELOW {@see REQUEST_TIMEOUT_SECONDS}: a
     * regression that leaves the deadline in place but removes the drain returns
     * an `ioError` at 10s, and a regression that also restores the blocking pipe
     * never returns at all. This bound reports both as the same failure.
     */
    private const BOUND_SECONDS = 8.0;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_lsp_stdinwedge_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    // =========================================================================
    // Defect 1 — the deadlock
    // =========================================================================

    /**
     * THE HEADLINE. An oversized request against a server already parked in
     * `write(2)` on a full stderr pipe completes, and completes INTACT.
     *
     * The fixture echoes back the byte length it received, so this row proves
     * three things at once: the write finished, the framing survived it, and the
     * server was reachable afterwards. A drain that ran but wrote a prefix would
     * satisfy "completed" and fail the length.
     */
    public function testAnOversizedRequestSurvivesAServerAlreadyBlockedOnStderr(): void
    {
        [$rc, $out, $elapsed] = $this->runProbe(self::WEDGE_BYTES, self::OVERSIZED_BYTES);

        $this->assertSame(
            0,
            $rc,
            sprintf(
                'a %d-byte request against a server flooding %d bytes of stderr did not complete '
                . '(rc=%d) after %.2fs — stdin and stderr are deadlocked against each other. '
                . 'Output: %s',
                self::OVERSIZED_BYTES,
                self::WEDGE_BYTES,
                $rc,
                $elapsed,
                trim($out) === '' ? '(none)' : trim($out),
            ),
        );
        $this->assertSame(
            self::OVERSIZED_BYTES,
            $this->reportedInt($out, 'ECHOED'),
            'the server received a different number of bytes than were sent, so the framed '
            . 'message was truncated on the way out. Output: ' . trim($out),
        );
    }

    /**
     * CONTROL A — the same oversized request, stderr UNDER the pipe capacity.
     * The child never blocks, so this row must pass with or without the drain,
     * which is what makes the row above a statement about the drain rather than
     * about the fixture.
     */
    public function testTheOversizedRequestWasOnlyEverAtRiskBecauseOfTheFlood(): void
    {
        [$rc, $out, ] = $this->runProbe(self::SAFE_BYTES, self::OVERSIZED_BYTES);

        $this->assertSame(0, $rc, 'the probe itself is broken: ' . trim($out));
        $this->assertSame(self::OVERSIZED_BYTES, $this->reportedInt($out, 'ECHOED'));

        // The control has to be a control OF something. A fixture that stopped
        // writing stderr altogether would pass this row for a reason that says
        // nothing about the pipe capacity, so the flood is asserted exactly —
        // a flood below the cap is never truncated.
        $this->assertSame(
            self::SAFE_BYTES,
            $this->reportedInt($out, 'TAILAFTER'),
            'the quiet fixture did not write its stderr, so this row is not a control for the '
            . 'flooding one. Output: ' . trim($out),
        );
    }

    /**
     * CONTROL B — the full flood, but a request UNDER the pipe capacity. The
     * parent's write fits in one buffer and returns before the child's silence
     * can matter, so this row too must pass either way.
     *
     * It also carries the proof that the FLOOD IS REAL, which neither of the
     * rows above can give on its own: the tail is pinned to
     * {@see LspConnection}'s own cap, which can only be reached if strictly more
     * than one cap's worth of stderr actually arrived.
     */
    public function testTheFloodIsRealAndASmallRequestWasNeverAtRisk(): void
    {
        [$rc, $out, ] = $this->runProbe(self::WEDGE_BYTES, self::SMALL_BYTES);

        $this->assertSame(0, $rc, 'the probe itself is broken: ' . trim($out));
        $this->assertSame(self::SMALL_BYTES, $this->reportedInt($out, 'ECHOED'));
        $this->assertSame(
            $this->maxStderrBytes(),
            $this->reportedInt($out, 'TAILAFTER'),
            'the flood fixture did not deliver more than one cap of stderr, so the wedge row '
            . 'above is passing against a server that was never capable of blocking. Output: '
            . trim($out),
        );
    }

    /**
     * AND THE CHILD REALLY WAS STILL BLOCKED when the oversized write began —
     * without this, the headline row degrades silently into control A.
     *
     * With C the pipe capacity and B the bytes the parent had absorbed when the
     * request started, the child can only have FINISHED its flood if
     * WEDGE_BYTES - B <= C. A B below that threshold rules "already finished"
     * out.
     *
     * ⚠️ A LOW B IS ALSO WHAT A FIXTURE THAT NEVER FLOODED AT ALL WOULD REPORT,
     * so the two assertions below are a CONJUNCTION and neither is the proof
     * alone. The second pins the post-exchange tail to the cap, which is reachable
     * only if strictly more than one cap's worth of stderr actually arrived — so
     * together they say the child wrote past the capacity AND had not got through
     * it when the oversized write began, i.e. it was parked in `write(2)`,
     * ignoring its stdin, for the duration of that write.
     *
     * MEASURED, three consecutive takes, PHP 8.3.6 / Linux 6.8: B = 0 every time
     * against a threshold of 334464. (An earlier revision of this line said
     * 34464, which was `100000 - 65536` — correct while {@see WEDGE_BYTES} was
     * 100000, and left behind when it was raised to 400000. The threshold is
     * computed below from the two constants and was never wrong in code; only
     * this sentence was. Re-measured at the current constant before the number
     * was changed, rather than re-derived by arithmetic.)
     */
    public function testTheChildHadNotFinishedItsFloodWhenTheOversizedWriteBegan(): void
    {
        [$rc, $out, ] = $this->runProbe(self::WEDGE_BYTES, self::OVERSIZED_BYTES);

        $this->assertSame(0, $rc, 'the probe itself is broken: ' . trim($out));

        $threshold = self::WEDGE_BYTES - self::MEASURED_PIPE_CAPACITY_BYTES;
        $this->assertLessThan(
            $threshold,
            $this->reportedInt($out, 'TAILBEFORE'),
            sprintf(
                'the parent had already absorbed enough stderr (>= %d of the child\'s %d bytes) '
                . 'for the child to have FINISHED its flood before the oversized write began, so '
                . 'this file cannot vouch for a blocked child. Output: %s',
                $threshold,
                self::WEDGE_BYTES,
                trim($out),
            ),
        );
        $this->assertSame(
            $this->maxStderrBytes(),
            $this->reportedInt($out, 'TAILAFTER'),
            'the exchange absorbed less than one cap of stderr, so the low TAILBEFORE above is '
            . 'reporting a fixture that never flooded rather than a child that was still '
            . 'blocked. Output: ' . trim($out),
        );
    }

    /**
     * The pipe capacity measured in this class's doc-block, as a number the
     * assertion above can do arithmetic with rather than a figure in prose. A
     * host with a LARGER pipe makes that proof unsound, and reds there rather
     * than passing on reasoning that no longer holds.
     */
    private const MEASURED_PIPE_CAPACITY_BYTES = 65536;

    /**
     * FD 0 IS NON-BLOCKING, asserted structurally as well as behaviourally.
     *
     * The behavioural rows above are the real evidence, but they run out of
     * process and take seconds; this one is instant and names the exact line. It
     * is not redundant with them either: it fails on a `connect()` that never set
     * the mode, whereas they fail on the CONSEQUENCE, and a future refactor could
     * plausibly break one without the other.
     */
    public function testConnectTakesAllThreePipesOutOfBlockingMode(): void
    {
        $connection = $this->connectionTo($this->deafServerScript(), timeout: 0.2);

        try {
            $pipes = $this->pipesOf($connection);

            foreach ([0, 1, 2] as $fd) {
                $this->assertFalse(
                    stream_get_meta_data($pipes[$fd])['blocked'],
                    "fd $fd is still in blocking mode — a blocking fd 0 is the stdin half of the "
                    . 'stderr deadlock, and a blocking fd 1 or 2 re-creates the read half',
                );
            }
        } finally {
            $connection->disconnect();
        }
    }

    // =========================================================================
    // Defect 2 — the silent desync
    // =========================================================================

    /**
     * A WRITE THAT COULD NOT FINISH REPORTS FAILURE. The old single `fwrite()`
     * returned a short int for exactly this case and the caller read it as
     * success.
     *
     * The fixture reads nothing at all, so the parent's stdin pipe fills at one
     * buffer and never drains. With a 1s budget the write is abandoned part-way,
     * and `sendRequest()` must answer an io error rather than going on to wait
     * for a reply to a message the server never received in full.
     */
    public function testAWriteThatCannotFinishIsReportedAsAFailureRatherThanASuccess(): void
    {
        $connection = $this->connectionTo($this->deafServerScript(), timeout: 1.0);

        try {
            $started = microtime(true);
            $response = $connection->sendRequest('textDocument/didOpen', [
                'text' => str_repeat('x', self::OVERSIZED_BYTES),
            ]);
            $elapsed = microtime(true) - $started;

            $this->assertTrue($response->isError, 'a half-written message was reported as sent');
            $this->assertStringContainsString('Failed to write message', (string) $response->errorMessage);
            $this->assertLessThan(
                self::BOUND_SECONDS,
                $elapsed,
                sprintf('the write was not bounded by the request timeout (%.2fs elapsed)', $elapsed),
            );
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * ...AND THE SESSION IS MARKED UNUSABLE, because `Content-Length` framing
     * cannot resynchronise. The server has consumed a header promising bytes that
     * never arrived; there is no point in the stream at which either side can
     * agree where the next message starts.
     *
     * Pinned on the latch's OBSERVABLE consequence — the next send fails
     * immediately — rather than on the private flag, so a refactor that keeps the
     * behaviour and drops the field still passes.
     */
    public function testAPartiallyWrittenMessagePoisonsTheConnectionForGood(): void
    {
        $connection = $this->connectionTo($this->deafServerScript(), timeout: 1.0);

        try {
            $connection->sendRequest('textDocument/didOpen', [
                'text' => str_repeat('x', self::OVERSIZED_BYTES),
            ]);

            $started = microtime(true);
            $next = $connection->sendRequest('textDocument/hover', []);
            $elapsed = microtime(true) - $started;

            $this->assertTrue(
                $next->isError,
                'a later request was attempted on a stream whose framing is desynchronised, so '
                . 'its reply would be parsed against the abandoned fragment',
            );
            $this->assertStringContainsString('Failed to write message', (string) $next->errorMessage);
            $this->assertLessThan(
                0.5,
                $elapsed,
                sprintf(
                    'the later request took %.2fs, so it went to the wire and waited rather than '
                    . 'failing fast on the latch',
                    $elapsed,
                ),
            );
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * THE OTHER POLARITY, and without it the latch above is satisfied by a flag
     * that is simply always set. A write abandoned before its FIRST byte is a
     * lost message, not a broken stream: the server saw nothing, so the next
     * request must still go out normally.
     *
     * Driven by handing {@see LspConnection::writeMessage()} a deadline that has
     * already passed, which trips the loop's first check with the payload
     * untouched — the one way to reach a zero-byte abandonment deterministically.
     */
    public function testAWriteAbandonedBeforeItsFirstByteLeavesTheConnectionUsable(): void
    {
        $connection = $this->connectionTo($this->echoServerScript(self::SAFE_BYTES), timeout: self::REQUEST_TIMEOUT_SECONDS);

        try {
            $connection->initialize();

            $write = new \ReflectionMethod($connection, 'writeMessage');
            $write->setAccessible(true);

            $this->assertFalse(
                $write->invoke($connection, ['jsonrpc' => '2.0', 'method' => 'nope'], microtime(true) - 1.0),
                'an already-expired deadline must abandon the write',
            );

            $response = $connection->sendRequest('echoLength', ['text' => str_repeat('x', self::SMALL_BYTES)]);

            $this->assertFalse(
                $response->isError,
                'a write abandoned with nothing written poisoned the connection anyway, so the '
                . 'latch fires on lost messages as well as on desynchronised ones',
            );
            $this->assertSame(self::SMALL_BYTES, $response->result['length'] ?? -1);
        } finally {
            $connection->disconnect();
        }
    }

    // =========================================================================
    // The latch is what `isConnected()` answers on (E474)
    // =========================================================================

    /**
     * A LATCHED SESSION REPORTS ITSELF UNUSABLE, and the reason this is worth a
     * row of its own is that {@see \SugarCraft\Crush\LSP\LspClient} branches on
     * exactly this predicate to choose between the language server and its grep
     * fallback — and CACHES whichever answer it gets. With `isConnected()`
     * answering true for a session that can never send again, the client took the
     * `[]` that a latched `sendRequest()` produces for a real answer and wrote it
     * into the cache, so one desynchronised write turned into a permanently empty
     * result for that uri+position. The consequence is pinned one file over, in
     * `LspClientTest::testAConnectionThatReportsItselfConnectedCachesItsEmptyAnswerForGood()`.
     *
     * BOTH POLARITIES ARE IN THIS ONE ROW ON PURPOSE. The `true` before the
     * oversized write is not decoration: without it, an `isConnected()` mutated
     * to `return false;` outright would satisfy the assertion this row is named
     * for. See {@see testASessionWhoseWriteWasAbandonedBeforeItsFirstByteStaysUsable()}
     * for the third polarity — a FAILED write that must NOT latch.
     */
    public function testALatchedSessionReportsItselfUnusable(): void
    {
        $connection = $this->connectionTo($this->deafServerScript(), timeout: 1.0);

        try {
            $this->assertTrue(
                $connection->isConnected(),
                'the control: a fresh connection to a live child is usable, so a later false '
                . 'cannot be attributed to the predicate simply always answering false',
            );

            $connection->sendRequest('textDocument/didOpen', [
                'text' => str_repeat('x', self::OVERSIZED_BYTES),
            ]);

            $this->assertFalse(
                $connection->isConnected(),
                'the session reported itself usable after a partially-written Content-Length '
                . 'message desynchronised the stream, so every caller that branches on this '
                . 'predicate would keep routing work to a connection that can never send again',
            );
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * THE THIRD POLARITY. A write abandoned before its FIRST byte is a lost
     * message and not a broken stream, so the session stays usable and
     * {@see \SugarCraft\Crush\LSP\LspConnection::isConnected()} must keep saying
     * so. Without this row, moving the latch into the predicate is satisfied by
     * an `isConnected()` that goes false after any failed write at all — which
     * would send every recoverable one-off hiccup permanently down the grep
     * fallback.
     *
     * Reached the same way {@see testAWriteAbandonedBeforeItsFirstByteLeavesTheConnectionUsable()}
     * reaches it: an already-expired deadline trips the loop's first check with
     * the payload untouched.
     */
    public function testASessionWhoseWriteWasAbandonedBeforeItsFirstByteStaysUsable(): void
    {
        $connection = $this->connectionTo($this->echoServerScript(self::SAFE_BYTES), timeout: self::REQUEST_TIMEOUT_SECONDS);

        try {
            $connection->initialize();

            $write = new \ReflectionMethod($connection, 'writeMessage');
            $write->setAccessible(true);

            $this->assertFalse(
                $write->invoke($connection, ['jsonrpc' => '2.0', 'method' => 'nope'], microtime(true) - 1.0),
                'an already-expired deadline must abandon the write',
            );

            $this->assertTrue(
                $connection->isConnected(),
                'a write that never put a byte on the wire marked the whole session unusable, so '
                . 'the predicate now reports the framing as gone for a stream that is intact',
            );
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * THE CONCERN THE LATCH USED TO BE HELD BACK BY, PINNED SO IT STAYS ANSWERED.
     *
     * {@see \SugarCraft\Crush\LSP\LspConnection::abandonWrite()} used to record
     * that `isConnected()` was left alone because "a caller may still want to
     * {@see \SugarCraft\Crush\LSP\LspConnection::disconnect()} it politely". That
     * worry was aimed at the wrong method: `disconnect()` gates on the private
     * `initialized` flag and never on the predicate, so the graceful path never
     * went through it. This row is the guard that keeps that true — mutate
     * `disconnect()`'s first condition to `!$this->isConnected()` and it reds,
     * because the early-return branch calls `stopProcess()` and returns WITHOUT
     * clearing the capabilities the full branch clears.
     *
     * `capabilities()` is the observable because it is PUBLIC and only the full
     * shutdown branch nulls it. The latch is set through reflection rather than
     * through a real partial write, because this row needs a server that
     * ANSWERED `initialize` (so there are capabilities to lose) and such a server
     * by construction never wedges the write.
     */
    public function testALatchedSessionStillDisconnectsPolitely(): void
    {
        $connection = $this->connectionTo($this->echoServerScript(self::SAFE_BYTES), timeout: self::REQUEST_TIMEOUT_SECONDS);

        $connection->initialize();

        $this->assertNotNull(
            $connection->capabilities(),
            'the control: the fixture answered initialize, so there are capabilities for '
            . 'disconnect() to clear and the assertion below is not vacuous',
        );

        $latch = new \ReflectionProperty($connection, 'framingBroken');
        $latch->setAccessible(true);
        $latch->setValue($connection, true);

        $this->assertFalse($connection->isConnected(), 'the latch did not reach the predicate');

        $connection->disconnect();

        $this->assertNull(
            $connection->capabilities(),
            'disconnect() took its early-return branch on a latched session, so the LSP '
            . 'shutdown/exit sequence was skipped for a server that was alive and reachable',
        );
    }

    /**
     * OMITTING THE DEADLINE IS AN ERROR, AND SAYING `null` IS NOT (E480).
     *
     * `writeMessage(array $payload, ?float $deadline = null)` had a null DEFAULT
     * that no production caller used — both send paths pass
     * `microtime(true) + $this->requestTimeout`. The default was the whole risk:
     * the null path is not "bounded by the child's liveness" the way its
     * `@param` claimed. With no deadline, no signals and a LIVE child that has
     * stopped reading, `stream_select()` times out every `WRITE_POLL_MICROS`,
     * `$ready === 0` takes the `continue`, and no liveness check is consulted at
     * all. A caller could inherit that by writing nothing.
     *
     * THE MEASUREMENT AND ITS GENERATOR LIVE ON
     * {@see \SugarCraft\Crush\LSP\LspConnection::writeMessage()}, point (d),
     * and are named here rather than restated because an earlier draft of this
     * doc-block restated them as "MEASURED this round" over figures it had
     * inherited from the finding rather than run. The short form: a child that
     * sleeps L seconds and never reads stdin, a 200000-byte payload, an explicit
     * `null` deadline and the clock outside the process — L=60 under `timeout 12`
     * gives rc 124 three times over, and L=8 under `timeout 30` returns false at
     * 8.056 / 8.051 / 8.054s. The write ends at the child's death and at nothing
     * else.
     *
     * ⚠️ WHAT THIS ROW PINS IS A SIGNATURE, NOT A BEHAVIOUR, and it is written
     * that way on purpose because there is no behaviour to pin: re-adding `=
     * null` is a WIDENING and would red nothing anywhere in this suite. The
     * `ArgumentCountError` is the only observable the change has. Its mutation is
     * therefore the fix itself — put the default back and this row is the one
     * thing that notices.
     *
     * THE `null` ARM IS THE KNOWN-POSITIVE CONTROL, and without it this row is
     * satisfied by a `writeMessage()` that has been deleted, renamed, or made to
     * throw unconditionally — every one of which also produces an error from a
     * one-argument call. The nullable path must still be REACHABLE, because
     * {@see testAWriteWithNoDeadlineIsEndedByTheConsecutiveFailureBackstop()}
     * drives it deliberately.
     */
    public function testTheDeadlineHasToBeStatedAndNullIsStillAThingYouCanState(): void
    {
        $connection = $this->connectionTo($this->echoServerScript(self::SAFE_BYTES), timeout: self::REQUEST_TIMEOUT_SECONDS);

        try {
            $connection->initialize();

            $write = new \ReflectionMethod($connection, 'writeMessage');
            $write->setAccessible(true);

            $this->assertTrue(
                $write->invoke($connection, ['jsonrpc' => '2.0', 'method' => 'noDeadlinePlease'], null),
                'the control: an explicit null deadline still writes, so the assertion below is '
                . 'about the DEFAULT and not about the parameter having been removed',
            );

            try {
                $write->invoke($connection, ['jsonrpc' => '2.0', 'method' => 'noDeadlineAtAll']);
                $this->fail(
                    'writeMessage() accepted a call with no deadline argument, so the unbounded '
                    . 'path is reachable by omission again rather than by decision',
                );
            } catch (\ArgumentCountError $e) {
                $this->assertStringContainsString('writeMessage', $e->getMessage());
            }
        } finally {
            $connection->disconnect();
        }
    }

    // =========================================================================
    // E475 — the between-exchange stall, and why it is a STALL
    // =========================================================================

    /**
     * A SERVER THAT LOGS WHILE THE PARENT IS IDLE PARKS IN `write(2)`, AND THE
     * NEXT EXCHANGE FREES IT. That is the whole difference between E475's
     * severity and a deadlock, and it was inherited as prose until this row.
     *
     * {@see LspConnection::drainStderr()} runs from `refill()` (every read
     * pass), from the write loop (every write pass) and once in `stopProcess()`.
     * Between exchanges — the editor idle, the user typing — NOTHING reads fd 2,
     * so a `gopls`/`rust-analyzer`/`jdtls` that logs continuously fills its
     * stderr pipe and stops. E475 records that as a stall rather than a deadlock
     * because the next exchange drains it. This asserts it rather than repeating
     * it.
     *
     * MEASURED out of process first, three consecutive takes, PHP 8.3.6 /
     * Linux 6.8: a server logging 4096 bytes at a time between exchanges wrote
     * 81920 / 86016 / 81920 bytes — past the 65536-byte pipe capacity, so it
     * really was blocked — while the parent idled 3.0s, and the very next
     * request completed at 3.02s with the parent's tail at its 65536 cap.
     *
     * ⚠️ THIS IS NOT A FIX FOR E475 AND MUST NOT BE READ AS ONE. fd 2 is still
     * unread for the whole idle gap and the server is still stopped for the
     * whole of it. What this pins is the SEVERITY: the moment "it self-heals"
     * stops being true, E475 (and E440 for the sibling class) is a deadlock and
     * needs re-triaging, and nothing else in this suite would notice.
     *
     * ⚠️ AND THE PROPOSED FIX IS NOT MERELY EXPENSIVE — IT IS WRONG FOR THIS
     * TREE. REWRITTEN, because the sentence it replaces named a remedy nobody
     * had checked against the way tool calls actually execute here.
     * WHAT THIS SAID: "The honest fix is fd 2 on the ReactPHP loop, a shape
     * change to a class that is synchronous by design, recorded rather than
     * reached for."
     * WHAT IS TRUE NOW: a tool call does not run in the process that owns the
     * server's pipes. `Chat`'s parallel dispatch FORKS ONE CHILD PER CALL, and
     * the parent collects them from `Loop::get()->addPeriodicTimer(0.05, …)` —
     * verified in `Chat.php`, not inherited from a comment — so the parent's
     * loop is servicing callbacks every 50 ms while a forked child sits inside
     * `readMessage()`/`callTool()` on fds it inherited. Registering fd 2 with
     * that loop would put TWO PROCESSES on one pipe at the same moment, and
     * `read(2)` is destructive: the bytes would go to whichever won. The tail a
     * failing exchange reports would then be missing exactly the lines the
     * parent happened to consume, and each process's own EOF bookkeeping —
     * `$stderrOpen` in the sibling class — would diverge from the other's. That
     * is a worse defect than the stall, and it is silent.
     * WHY THE STALL STILL EARNS ITS PLACE AS AN OPEN ITEM: the residual cost is
     * bounded and measured — 3.02s against a 3.0s idle, i.e. ~20 ms on the
     * first exchange after the gap. A fix that is safe under fork-per-tool-call
     * has to drain while NO call is in flight, which is a question about the
     * dispatch layer rather than about these two classes. See the round-57 lane
     * b report.
     *
     * THE STDERR ASSERTIONS ARE THE POSITIVE COMPONENT. Without them a fixture
     * that quietly stopped logging would satisfy "the request was answered"
     * while exercising nothing at all.
     *
     * ⚠️ ITS MUTATION SCOPE, STATED BECAUSE IT IS NARROWER THAN IT LOOKS.
     * Removing the drain from the WRITE loop alone does not red this row, and
     * neither does removing it from `refill()` alone — the request here is
     * small, so either drain on its own frees the child and the exchange
     * completes. Both single removals leave this row green; removing BOTH reds
     * it. That is the right scope rather than a gap: the claim is "the next
     * EXCHANGE frees it", an exchange is a write and a read, and the row reds
     * exactly when no drain runs during one — which is exactly when the stall
     * becomes a deadlock.
     *
     * WHAT THE SINGLE REMOVALS DO RED, NAMED RATHER THAN COUNTED.
     *
     * WHAT THIS SAID: that between them the two single removals red "four OTHER
     * rows in this file".
     *
     * WHAT IS TRUE NOW: three, and one of the two reaches outside this file
     * altogether. Re-measured at this tree, `tests/LSP` (79 rows), PHP 8.3.6 /
     * Linux 6.8, the actual `+`/`-` lines printed for each patch:
     *
     *  - drop the WRITE-loop drain      -> 2 failures, both here:
     *      {@see testAnOversizedRequestSurvivesAServerAlreadyBlockedOnStderr()}
     *      {@see testTheChildHadNotFinishedItsFloodWhenTheOversizedWriteBegan()}
     *  - drop `refill()`'s drain        -> 2 failures, ONE here:
     *      {@see testTheFloodIsRealAndASmallRequestWasNeverAtRisk()}
     *      and `LspConnectionShutdownTest::testAServerThatFloodsStderrIsStillAnsweredAndItsStderrIsKept()`
     *  - drop BOTH                      -> 7 failures, 5 here, this row among them
     *
     * WHY THE OLD NUMBER STILL EARNS AN EXPLANATION RATHER THAN A DELETION: four
     * is a real measurement of the WRONG mutation. It is the double removal's
     * count of other in-file rows, and it includes
     * {@see testTheOversizedRequestWasOnlyEverAtRiskBecauseOfTheFlood()}, which
     * NEITHER single removal reds. Attributing it to the single removals made
     * the two drains look independently load-bearing for a row that in fact
     * needs both gone. Rows are named here instead of counted because a count
     * taken in one worktree is wrong the moment a sibling lane merges.
     */
    public function testTheBetweenExchangeStderrStallSelfHealsOnTheNextExchange(): void
    {
        $connection = $this->connectionTo($this->idleLoggingServerScript(), timeout: self::REQUEST_TIMEOUT_SECONDS);

        try {
            $connection->initialize();

            // The parent does nothing at all. Nothing reads fd 2 in this gap,
            // which is exactly the state E475 describes.
            usleep(1500000);

            $response = $connection->sendRequest('textDocument/hover', ['x' => 1]);

            $this->assertFalse(
                $response->isError,
                'the next exchange did not free a server parked on a full stderr pipe, so E475 '
                . 'is a DEADLOCK rather than a stall and its severity needs re-triaging',
            );
            $this->assertGreaterThan(
                65536,
                $response->result['stderrWritten'] ?? 0,
                'the server never got past one pipe buffer, so it was never blocked and this row '
                . 'is not about the stall at all',
            );
            $this->assertSame(
                65536,
                strlen($connection->stderrTail()),
                'the parent did not retain a full buffer of the flood, so the drain that freed '
                . 'the child is not the one this class performs',
            );
        } finally {
            $connection->disconnect();
        }
    }

    // =========================================================================
    // Fixtures and helpers
    // =========================================================================

    /**
     * ANSWERS THE HANDSHAKE, THEN LOGS BETWEEN EXCHANGES — the E475 shape.
     *
     * ⚠️ IT CANNOT USE `sc_read_framed()` FOR THE SECOND MESSAGE, and that is
     * not a style choice: that helper blocks in `fgets(STDIN)`, so a server
     * built on it is parked in a READ between exchanges and never has the
     * opportunity to write anything. The premise here is a server busy on its
     * OWN account while the parent is idle, so the wait has to be a
     * `stream_select()` on a non-blocking stdin with the logging in the timeout
     * branch. The framing helpers are still pulled in, for the handshake
     * exchange and for the framed reply.
     *
     * It reports how many bytes it managed to write, which is what lets the row
     * tell "the server was blocked and then freed" from "the server never had
     * anything to say".
     */
    private function idleLoggingServerScript(): string
    {
        $path = $this->tempDir . '/idlelogger.php';
        file_put_contents($path, $this->withFraming(self::IDLE_LOGGING_SERVER));

        return $path;
    }

    /** @return array{0: int, 1: string, 2: float} rc, stdout+stderr, elapsed */
    private function runProbe(int $floodBytes, int $requestBytes): array
    {
        $probe = $this->tempDir . '/probe_' . $floodBytes . '_' . $requestBytes . '.php';
        file_put_contents($probe, sprintf(
            self::PROBE_TEMPLATE,
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->blockedServerScript($floodBytes), true),
            self::REQUEST_TIMEOUT_SECONDS,
            $requestBytes,
        ));

        return $this->runBounded([PHP_BINARY, $probe], self::BOUND_SECONDS);
    }

    private function connectionTo(string $script, float $timeout): LspConnection
    {
        $connection = new LspConnection('unused', [$script]);
        $connection->connect(PHP_BINARY, [], null, $timeout);

        return $connection;
    }

    /**
     * A server that answers `initialize` and THEN floods stderr unprompted, so
     * it is sitting blocked in its own `write()` — not waiting for a header —
     * when the parent's next write begins.
     *
     * ⚠️ THE ORDERING IS THE WHOLE TEST, AND THE SECOND MESSAGE IS NOT AN
     * ARBITRARY CHOICE. Two earlier arrangements were VACUOUS, and the second was
     * caught only by the accounting row below:
     *
     *  - Flooding in RESPONSE to a message lets {@see LspConnection::refill()}
     *     drain it while collecting the reply, so the child is idle at the header
     *     read by the time the parent writes again.
     *  - Flooding after the FIRST message (`initialize`) is drained by the fix
     *     itself: {@see LspConnection::initialize()} then sends the `initialized`
     *     NOTIFICATION, and the write loop's own drain empties the pipe on the
     *     way past. MEASURED: the parent reported 65536 absorbed bytes BEFORE the
     *     oversized write had begun.
     *
     * The flood therefore lands after message two — the last thing `initialize()`
     * sends — so nothing runs between it and the request under test.
     */
    private function blockedServerScript(int $floodBytes): string
    {
        $path = $this->tempDir . '/blocked_' . $floodBytes . '.php';
        file_put_contents($path, $this->withFraming(sprintf(self::BLOCKED_SERVER_TEMPLATE, $floodBytes)));

        return $path;
    }

    /** A well-behaved server: answers every message, floods only on request. */
    private function echoServerScript(int $floodBytes): string
    {
        $path = $this->tempDir . '/echo_' . $floodBytes . '.php';
        file_put_contents($path, $this->withFraming(sprintf(self::ECHO_SERVER_TEMPLATE, $floodBytes)));

        return $path;
    }

    /** A server that reads NOTHING, so the parent's stdin pipe fills and stays full. */
    private function deafServerScript(): string
    {
        $path = $this->tempDir . '/deaf.php';
        file_put_contents($path, self::DEAF_SERVER);

        return $path;
    }

    // =========================================================================
    // The EINTR backstop, on the one path that has no deadline
    // =========================================================================

    /**
     * A WRITE WITH NO DEADLINE STILL ENDS, BECAUSE THE CONSECUTIVE-FAILURE COUNT
     * ENDS IT — and this row exists because the count shipped unpinned.
     *
     * MEASURED BY MUTATION before this row was written: deleting
     * `|| $consecutiveSelectFailures >= self::MAX_CONSECUTIVE_SELECT_FAILURES`
     * from {@see LspConnection::writeMessage()}'s EINTR branch left the ENTIRE
     * `tests/LSP/` directory green — 72 tests, 170 assertions, rc 0. The sibling
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer} added the identical guard in
     * the same change and pinned it; this copy had nothing.
     *
     * WHY THERE IS NO NO-STORM CONTROL, which the sibling's equivalent does have:
     * it would HANG. MEASURED, and it is not a quirk of the fixture — with no
     * deadline, no signals and a LIVE child that has stopped reading,
     * `stream_select()` simply times out every {@see LspConnection::WRITE_POLL_MICROS}
     * and the loop continues forever. `timeout 12 php probe.php` -> rc 124. The
     * backstop only counts CONSECUTIVE FAILURES, and a timeout is not a failure.
     * So the storm is not one arm of a comparison here; it is the only way to
     * reach the branch at all.
     *
     * WHAT MAKES THIS ROW ATTRIBUTE THE EXIT TO THE BACKSTOP rather than to
     * something else is an ENUMERATION, not a comparison. The loop has exactly
     * four ways out, and the probe closes three of them:
     *
     *  1. the loop-top deadline — the probe passes `null`, so there is none;
     *  2. `!childIsRunning()` in the same branch — `CHILDALIVE:true` is read
     *     AFTER the call returns, so the child was up the whole time;
     *  3. `$written === false` — a broken pipe, which needs a dead child; ruled
     *     out by the same reading. A full pipe with a live child returns 0 or a
     *     short count from `fwrite()`, never `false`;
     *  4. the backstop. Nothing else is left.
     *
     * MEASURED on this host (PHP 8.3.6, Linux 6.8), three consecutive takes,
     * identical to 10 ms: ELAPSED 7.097 / 7.099 / 7.100, RESULT false,
     * CHILDALIVE true, FRAMINGBROKEN true. That is 10000 failures in 7.1s, i.e.
     * ~1408 per second — see {@see LspConnection::MAX_CONSECUTIVE_SELECT_FAILURES},
     * whose own estimate this measurement corrected.
     *
     * FRAMINGBROKEN IS ASSERTED TOO, because abandoning a partly-written
     * `Content-Length` message is precisely the case the latch exists for, and
     * this is the only row in the suite that reaches it through the backstop.
     *
     * ⚠️ THE MUTATION IS KILLED TWICE OVER, AND BY DIFFERENT ASSERTIONS DEPENDING
     * ON THE CHILD'S LIFETIME. Both readings are recorded because between them
     * they show why the fixture is dedicated and why `CHILDALIVE` is not
     * decoration:
     *
     *  - Against the SHARED 30s {@see deafServerScript()}, deleting the backstop
     *    clause did NOT hang the loop. It ran on until the child expired and left
     *    through the LIVENESS exit, reporting `RESULT:false`, `FRAMINGBROKEN:true`
     *    and ELAPSED 29.843 — which satisfies every other assertion in this row,
     *    `ELAPSED > 1.0` included. Only `CHILDALIVE:false` caught it. A row
     *    asserting merely "it returned false, and not too quickly" would have
     *    scored that mutation a SURVIVOR.
     *  - Against the dedicated 120s child this row now spawns, the same deletion
     *    spins past {@see STORM_BOUND_SECONDS} and the probe is killed at 45.00s
     *    with rc -1 and no output at all, which is the honest shape of the defect:
     *    the loop is unbounded, and the backstop is the only thing that bounds it.
     *
     * The second is the kill this row is built to produce. The first is why
     * `CHILDALIVE` stays regardless — it is what stops any OTHER way out of the
     * loop being quietly credited to the backstop.
     */
    public function testAWriteWithNoDeadlineIsEndedByTheConsecutiveFailureBackstop(): void
    {
        [$rc, $out, $elapsed] = $this->runStormProbe();

        $this->assertSame(
            0,
            $rc,
            sprintf(
                'the storm probe did not finish (rc=%d) after %.2fs — a persistently failing '
                . 'stream_select() spins in writeMessage() with nothing to stop it. Output: %s',
                $rc,
                $elapsed,
                trim($out) === '' ? '(none)' : trim($out),
            ),
        );
        $this->assertStringContainsString(
            'RESULT:false',
            $out,
            'writeMessage() did not report the write as abandoned. Output: ' . trim($out),
        );
        $this->assertStringContainsString(
            'CHILDALIVE:true',
            $out,
            'the child died during the probe, so this row cannot attribute the exit to the '
            . 'backstop — the liveness check and a failed fwrite() both become reachable. '
            . 'Output: ' . trim($out),
        );
        $this->assertStringContainsString(
            'FRAMINGBROKEN:true',
            $out,
            'a partially written Content-Length message was abandoned without latching the '
            . 'framing as broken, which leaves the stream unrecoverable and unmarked. '
            . 'Output: ' . trim($out),
        );
        $this->assertGreaterThan(
            1.0,
            $this->reportedFloat($out, 'ELAPSED'),
            'writeMessage() returned too fast to have walked 10000 consecutive failures, so it '
            . 'exited some other way and this row is not measuring the backstop. Output: '
            . trim($out),
        );
    }

    /**
     * Splice {@see FRAMING_HELPERS} into a fixture template.
     *
     * FAILS LOUDLY on a template that carries no marker rather than writing a
     * fixture with no framing helpers in it — that fixture would exit at its
     * first `sc_read_framed()` call, the connection would see a server that says
     * nothing, and every row above would go red for a reason that has nothing to
     * do with the class under test.
     */
    private function withFraming(string $template): string
    {
        $this->assertStringContainsString(
            self::FRAMING_MARKER,
            $template,
            'the fixture template lost its framing-helper marker',
        );

        return str_replace(self::FRAMING_MARKER, self::FRAMING_HELPERS, $template);
    }

    /** @param array<int, string> $argv @return array{0: int, 1: string, 2: float} */
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
            // Signal 9 rather than SIGTERM: the point of this branch is that a
            // probe wedged in a blocking write does not get to decide when it
            // stops.
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

    /**
     * Pull one `LABEL:<int>` line out of a probe's stdout.
     *
     * FAILS rather than returning a sentinel when the label is missing or is not
     * followed by digits: a reader that answered 0 for "the probe never said"
     * would turn a broken probe into a passing blocked-child proof, which is the
     * exact shape of hole this readout exists to close.
     */
    private function reportedInt(string $out, string $label): int
    {
        $this->assertSame(
            1,
            preg_match('/^' . preg_quote($label, '/') . ':(\d+)$/m', $out, $m),
            "the probe did not report a $label:<int> line, so its accounting cannot be read at "
            . 'all. Output: ' . trim($out),
        );

        return (int) $m[1];
    }

    /** @return array<int, resource> */
    private function pipesOf(LspConnection $connection): array
    {
        $property = new \ReflectionProperty($connection, 'pipes');
        $property->setAccessible(true);

        /** @var array<int, resource> $pipes */
        $pipes = $property->getValue($connection);

        return $pipes;
    }

    /** Read off the class rather than restated, so the cap cannot drift from it. */
    private function maxStderrBytes(): int
    {
        return (int) (new \ReflectionClass(LspConnection::class))->getConstant('MAX_STDERR_BYTES');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /** Spelled once, so the templates and {@see withFraming()} cannot drift apart. */
    private const FRAMING_MARKER = 'SC_FRAMING_HELPERS';

    /**
     * The `Content-Length` reader/writer every fixture below shares. Spelled once
     * and interpolated, so a framing bug in the fixture cannot make one row pass
     * and another fail for reasons that have nothing to do with the class under
     * test.
     */
    private const FRAMING_HELPERS = <<<'PHP'
        function sc_read_framed() {
            $header = '';
            while (($line = fgets(STDIN)) !== false) {
                if ($line === "\r\n" || $line === "\n") {
                    break;
                }
                $header .= $line;
            }
            if (!preg_match('/Content-Length:\s*(\d+)/i', $header, $m)) {
                return null;
            }
            $len = (int) $m[1];
            $body = '';
            while (strlen($body) < $len) {
                $chunk = fread(STDIN, $len - strlen($body));
                if ($chunk === false || $chunk === '') {
                    return null;
                }
                $body .= $chunk;
            }
            return json_decode($body, true);
        }
        function sc_write_framed(array $msg) {
            $json = json_encode($msg);
            echo 'Content-Length: ', strlen($json), "\r\n\r\n", $json;
            flush();
        }
        function sc_reply(array $msg) {
            $method = (string) ($msg['method'] ?? '');
            if ($method === 'initialize') {
                return ['capabilities' => ['textDocumentSync' => 1]];
            }
            if ($method === 'echoLength' || $method === 'textDocument/didOpen') {
                return ['length' => strlen((string) ($msg['params']['text'] ?? ''))];
            }
            return [];
        }
        PHP;

    /** The E475 fixture body; see {@see idleLoggingServerScript()} for why it polls. */
    private const IDLE_LOGGING_SERVER = <<<'PHP'
        <?php
        SC_FRAMING_HELPERS
        $first = sc_read_framed();
        if ($first !== null && isset($first['id'])) {
            sc_write_framed(['jsonrpc' => '2.0', 'id' => $first['id'], 'result' => sc_reply($first)]);
        }
        stream_set_blocking(STDIN, false);
        $written = 0;
        $buffer = '';
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $read = [STDIN];
            $write = [];
            $except = [];
            if (@stream_select($read, $write, $except, 0, 1000) === 1) {
                $chunk = fread(STDIN, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $buffer .= $chunk;
                $separator = strpos($buffer, "\r\n\r\n");
                if ($separator === false || !preg_match('/Content-Length:\s*(\d+)/i', $buffer, $m)) {
                    continue;
                }
                $bodyAt = $separator + 4;
                $len = (int) $m[1];
                if (strlen($buffer) - $bodyAt < $len) {
                    continue;
                }
                $message = json_decode(substr($buffer, $bodyAt, $len), true);
                $buffer = substr($buffer, $bodyAt + $len);
                if (is_array($message) && isset($message['id'])) {
                    sc_write_framed([
                        'jsonrpc' => '2.0',
                        'id' => $message['id'],
                        'result' => ['stderrWritten' => $written],
                    ]);
                }
                continue;
            }
            // Nothing to read: LOG. Blocks here once fd 2 is full, which is
            // the state under test.
            fwrite(STDERR, str_repeat('e', 4096));
            $written += 4096;
        }
        PHP;

    /**
     * %d bytes of stderr, written unprompted rather than in reply — the
     * difference from {@see ECHO_SERVER_TEMPLATE}. Above the pipe capacity the
     * child parks inside that `fwrite()` and stops reading stdin, which is the
     * state the oversized write has to meet.
     *
     * ⚠️ "UNPROMPTED" IS NOT "AS EARLY AS POSSIBLE", and the `$seen === 2` in
     * the body below is the whole fixture. The flood lands after the SECOND
     * message — the `initialized` NOTIFICATION that ends the handshake, not the
     * `initialize` REQUEST that opens it. Flooding after message one was
     * MEASURED vacuous, because the notification's own write-loop drain empties
     * the pipe on the way past; see {@see blockedServerScript()} for that
     * measurement and for the other arrangement it rules out.
     */
    private const BLOCKED_SERVER_TEMPLATE = <<<'PHP'
        <?php
        SC_FRAMING_HELPERS
        $noise = str_repeat('e', %d);
        $seen = 0;
        while (($msg = sc_read_framed()) !== null) {
            $seen++;
            if (isset($msg['id'])) {
                sc_write_framed(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => sc_reply($msg)]);
            }
            if ($seen === 2) {
                fwrite(STDERR, $noise);
            }
        }
        PHP;

    /** %d bytes of stderr, written in reply to each message rather than unprompted. */
    private const ECHO_SERVER_TEMPLATE = <<<'PHP'
        <?php
        SC_FRAMING_HELPERS
        $noise = str_repeat('e', %d);
        while (($msg = sc_read_framed()) !== null) {
            if ($noise !== '') {
                fwrite(STDERR, $noise);
            }
            if (isset($msg['id'])) {
                sc_write_framed(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => sc_reply($msg)]);
            }
        }
        PHP;

    /**
     * Reads NOTHING and says nothing, so the parent's stdin pipe fills at one
     * buffer and stays full for as long as the child lives. The sleep is longer
     * than any budget in this file so the child cannot exit and turn the wedge
     * into an EPIPE by accident.
     */
    private const DEAF_SERVER = <<<'PHP'
        <?php
        sleep(30);
        PHP;

    /**
     * %s autoloader path · %s server script path · %f request timeout ·
     * %d bytes of request payload.
     *
     * Reports the absorbed stderr either side of the exchange, because the byte
     * accounting is what tells the parent whether the child was still blocked
     * when the write began.
     */
    private const PROBE_TEMPLATE = <<<'PHP'
        <?php
        require %s;
        $c = new SugarCraft\Crush\LSP\LspConnection('unused', [%s]);
        $c->connect(PHP_BINARY, [], null, %F);
        $c->initialize();
        // Let the child reach its unprompted stderr write and PARK in it. Nothing
        // in this class drains outside a write loop or a refill, so the sleep
        // cannot itself absorb the flood — TAILBEFORE stays honest.
        usleep(200000);
        echo 'TAILBEFORE:', strlen($c->stderrTail()), "\n";
        $r = $c->sendRequest('echoLength', ['text' => str_repeat('x', %d)]);
        echo 'TAILAFTER:', strlen($c->stderrTail()), "\n";
        if ($r->isError) {
            echo 'ERROR:', $r->errorMessage ?? '(none)', "\n";
            $c->disconnect();
            exit(1);
        }
        echo 'ECHOED:', $r->result['length'] ?? -1, "\n";
        $c->disconnect();
        PHP;

    /**
     * The storm row's external clock. Far above the measured 7.1s, because the
     * failure this bounds is an unbounded loop and the bound is the only thing
     * that can observe it. Deliberately NOT {@see BOUND_SECONDS}, which is 8.0
     * and would sit inside the measurement's own noise.
     */
    private const STORM_BOUND_SECONDS = 45.0;

    /**
     * The payload the storm row writes. Over the 65536-byte pipe capacity, so the
     * write cannot complete against a child that never reads and the loop is
     * still going when the signals start landing.
     */
    private const STORM_PAYLOAD_BYTES = 200000;

    private function runStormProbe(): array
    {
        $probe = $this->tempDir . '/storm.php';
        file_put_contents($probe, sprintf(
            self::STORM_PROBE_TEMPLATE,
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->longLivedDeafServerScript(), true),
            self::STORM_PAYLOAD_BYTES,
        ));

        return $this->runBounded([PHP_BINARY, $probe], self::STORM_BOUND_SECONDS);
    }

    /**
     * A deaf server for the storm row specifically, and NOT the shared
     * {@see deafServerScript()}, whose 30s is sized for rows that finish in
     * milliseconds.
     *
     * The backstop walk is a MEASURED 7.1s, so 30s is a margin of only ~4x on a
     * box that runs three test lanes at once — and if the walk ever stretched
     * past the child's lifetime the row would go red for the wrong reason
     * (`CHILDALIVE:false`, the enumeration correctly refusing to credit the
     * backstop). MEASURED BY MUTATION, and this is not hypothetical: with the
     * backstop clause deleted, the loop ran on until the 30s child EXPIRED and
     * then exited through the liveness check at ELAPSED 29.843 with
     * `RESULT:false` — every other assertion in the row passed. `CHILDALIVE` was
     * the only one that caught it, which is the enumeration doing exactly its
     * job, but it also showed how near the shared fixture's clock is. 120s puts
     * the child well outside {@see STORM_BOUND_SECONDS}, so an unbounded loop
     * now reds on the probe's own clock instead of racing the fixture's.
     */
    private function longLivedDeafServerScript(): string
    {
        $path = $this->tempDir . '/deaf-storm.php';
        file_put_contents($path, "<?php\nsleep(120);\n");

        return $path;
    }

    /**
     * Pull one `LABEL:<float>` line out of a probe's stdout. Fails rather than
     * returning a sentinel, for the reason {@see reportedInt()} gives.
     */
    private function reportedFloat(string $out, string $label): float
    {
        $this->assertSame(
            1,
            preg_match('/^' . preg_quote($label, '/') . ':([0-9.]+)$/m', $out, $m),
            "the probe did not report a $label:<float> line, so its timing cannot be read at "
            . 'all. Output: ' . trim($out),
        );

        return (float) $m[1];
    }

    /**
     * Drives {@see LspConnection::writeMessage()} through reflection, with NO
     * deadline, against a deaf child, under a SIGUSR1 storm.
     *
     * Reflection rather than `sendRequest()` because both public send paths pass
     * `microtime(true) + $this->requestTimeout` — the null-deadline shape this
     * probe needs is unreachable from outside the class. That asymmetry is
     * recorded on {@see LspConnection::writeMessage()} itself.
     *
     * The signal handler is a NO-OP and `pcntl_async_signals(false)` is
     * deliberate: the storm's job is to make `stream_select()` return `false` for
     * EINTR, not to run PHP code. 300 µs is the densest interval this box
     * sustains.
     */
    private const STORM_PROBE_TEMPLATE = <<<'PHP'
        <?php
        require %s;
        $script = %s;

        $c = new SugarCraft\Crush\LSP\LspConnection('unused', [$script]);
        $r = new ReflectionClass($c);
        $process = proc_open([PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        foreach ([0, 1, 2] as $fd) { stream_set_blocking($pipes[$fd], false); }
        foreach (['process' => $process, 'pipes' => $pipes, 'initialized' => true] as $n => $v) {
            $prop = $r->getProperty($n); $prop->setAccessible(true); $prop->setValue($c, $v);
        }
        usleep(200000);

        pcntl_async_signals(false);
        pcntl_signal(SIGUSR1, static function (): void {});
        $parent = getmypid();
        $storm = pcntl_fork();
        if ($storm === 0) {
            $t = microtime(true);
            while (microtime(true) - $t < 120.0) { posix_kill($parent, SIGUSR1); usleep(300); }
            exit(0);
        }

        $write = $r->getMethod('writeMessage'); $write->setAccessible(true);
        $t0 = microtime(true);
        $result = $write->invoke($c, ['payload' => str_repeat('x', %d)], null);
        printf("ELAPSED:%%.3f\n", microtime(true) - $t0);
        echo 'RESULT:', var_export($result, true), "\n";
        echo 'CHILDALIVE:', var_export(proc_get_status($process)['running'], true), "\n";
        $f = $r->getProperty('framingBroken'); $f->setAccessible(true);
        echo 'FRAMINGBROKEN:', var_export($f->getValue($c), true), "\n";

        if ($storm > 0) { posix_kill($storm, 9); pcntl_waitpid($storm, $st); }
        if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
        foreach ($pipes as $q) { if (is_resource($q)) fclose($q); }
        proc_close($process);
        PHP;
}
