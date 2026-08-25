<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\McpMessage;

/**
 * THE THIRD MEMBER OF THE FAMILY: a message that does not fit in one write must
 * not be half-sent, and a reply that does not arrive in one read must not be
 * thrown away.
 *
 * {@see \SugarCraft\Crush\MCP\StdioMcpServer::writeLine()} and
 * {@see \SugarCraft\Crush\LSP\LspConnection::writeMessage()} were fixed in
 * earlier rounds. {@see ClaudeCodeMcpClient::sendMessage()} was the site nobody
 * owned, and its symptom is different from both because
 * {@see ClaudeCodeMcpClient::connect()} puts fd 0 in NON-BLOCKING mode: it never
 * hung. It short-wrote and threw, with the prefix already in the child's pipe.
 *
 * Generator for the pre-fix reading, PHP 8.3.6 / Linux 6.8, three consecutive
 * takes, identical every time — a `tools/call` carrying 200000 bytes of
 * arguments against a child that reads NDJSON lines and reports each line's
 * length:
 *
 *     payload 200068 bytes  ->  fwrite() returned 65536, the pipe capacity
 *     the child then read ONE 65578-byte line, unparseable
 *
 * 65578 rather than 65536 is the finding. The NEXT message this client sent
 * supplied the newline that terminated the fragment, so it was swallowed INTO
 * the malformed line: one short write costs TWO messages. That is why the fix
 * carries a resynchronisation step and not only a loop.
 *
 * AND THE READ SIDE WAS WORSE, because it needed no oversized anything.
 * {@see ClaudeCodeMcpClient::readMessages()} kept its line accumulator as a
 * LOCAL, so a reply whose line had not arrived whole by the time the method
 * returned went out with the stack frame. Same host, ONE fixture generator with
 * two arms differing only in whether the child's line crosses a poll boundary,
 * three consecutive takes each:
 *
 *     whole line + newline in one write  ->  1 message seen
 *     half, 400ms pause, rest + newline  ->  0 messages seen (LOST)
 *
 * Both arms poll for 1.8s, the shape {@see ClaudeCodeMcpClient::callTool()}
 * drives. A stdio server has no obligation to flush a response in one
 * `write(2)`, so the second arm is the ordinary case for any reply larger than
 * what the child happens to flush — and the symptom was
 * `RuntimeException: No response received`, which reads as a dead server.
 *
 * ⚠️ THE WEDGE ROWS ARE OBSERVED FROM OUTSIDE, in a child `php` process with
 * this process holding the clock. Today's fd 0 is non-blocking and the loop is
 * idle-bounded, so an in-process assertion would be safe — but the regression
 * this file exists to catch is precisely the one that puts a BLOCKING write
 * back, and that does not fail slowly, it does not return. Same instrument the
 * `LspConnection` and `StdioMcpServer` siblings use.
 */
final class ClaudeCodeMcpClientStdinWedgeTest extends TestCase
{
    /**
     * Above BOTH the 65536-byte pipe capacity and the 16 x 8192 = 131072-byte
     * single-drain pass {@see ClaudeCodeMcpClient} performs.
     *
     * THE SECOND BOUND IS THE BINDING ONE and the sibling file records what
     * happens when a constant only clears the first: a flood that fits inside
     * one drain pass is emptied before the write starts, the child goes back to
     * reading, and the row passes with the fix removed. 400000 leaves three
     * passes of headroom.
     */
    private const WEDGE_BYTES = 400000;

    /** Below both, so the child never blocks: the control rows pass either way. */
    private const SAFE_BYTES = 1000;

    /**
     * A `tools/call` payload big enough to overfill the parent's OWN stdin pipe.
     * An MCP tool call whose arguments carry a file's contents reaches this
     * routinely.
     */
    private const OVERSIZED_BYTES = 200000;

    /** Small enough to fit in one pipe buffer with the framing. */
    private const SMALL_BYTES = 1000;

    /**
     * The external clock. Comfortably above the 0.10s the drained path measures
     * and far below anything a regression produces, since a regression to a
     * blocking fd 0 does not return at all.
     */
    private const BOUND_SECONDS = 20.0;

    /**
     * The idle bound the fast rows drive
     * {@see ClaudeCodeMcpClient::writeAll()} with.
     *
     * Deliberately NOT {@see ClaudeCodeMcpClient::WRITE_IDLE_SECONDS}, which is
     * 15.0: those rows are about the CLOCK's shape — what resets it and what
     * does not — and observing that at the production value would cost the suite
     * thirty seconds to learn nothing extra.
     * {@see testSendMessageDrivesTheLoopWithTheProductionIdleBound()} is the row
     * that ties the shape back to the number.
     */
    private const TEST_IDLE_SECONDS = 0.5;

    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_ccmcp_wedge_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    // =========================================================================
    // Defect 1 — the short write
    // =========================================================================

    /**
     * THE HEADLINE. An oversized `tools/call` against a server already parked in
     * `write(2)` on a full stderr pipe goes out, and goes out INTACT.
     *
     * The fixture reports the byte length of every line it read and whether that
     * line PARSED, so this row proves three things at once: the write finished,
     * the framing survived it, and the server could still answer. A loop that
     * wrote a prefix would satisfy "completed" and fail the length.
     */
    public function testAnOversizedMessageSurvivesAServerAlreadyBlockedOnStderr(): void
    {
        [$rc, $out] = $this->runProbe(self::WEDGE_BYTES, self::OVERSIZED_BYTES);

        $this->assertSame(0, $rc, 'the probe did not complete. Output: ' . trim($out));
        $this->assertSame(
            'ok',
            $this->reported($out, 'SENT'),
            'sendMessage() did not put the whole message on the wire against a server flooding '
            . self::WEDGE_BYTES . ' bytes of stderr. Output: ' . trim($out),
        );
        $this->assertSame(
            $this->reportedInt($out, 'PAYLOAD'),
            $this->reportedInt($out, 'ECHOEDLEN'),
            'the server received a different number of bytes than were sent, so the framed '
            . 'message was truncated on the way out. Output: ' . trim($out),
        );
        $this->assertSame(
            'yes',
            $this->reported($out, 'ECHOEDPARSED'),
            'the server received the right NUMBER of bytes and could not parse them, so the '
            . 'line it read was not the line that was sent. Output: ' . trim($out),
        );
    }

    /**
     * CONTROL A — the same oversized message, stderr UNDER the pipe capacity.
     * The child never blocks, so this row must pass with or without the drain,
     * which is what makes the row above a statement about the drain rather than
     * about the fixture or the framing.
     */
    public function testTheOversizedMessageWasOnlyEverAtRiskBecauseOfTheFlood(): void
    {
        [$rc, $out] = $this->runProbe(self::SAFE_BYTES, self::OVERSIZED_BYTES);

        $this->assertSame(0, $rc, 'the probe itself is broken: ' . trim($out));
        $this->assertSame('ok', $this->reported($out, 'SENT'), trim($out));
        $this->assertSame($this->reportedInt($out, 'PAYLOAD'), $this->reportedInt($out, 'ECHOEDLEN'), trim($out));

        // The control has to be a control OF something. A fixture that stopped
        // writing stderr altogether would pass this row for a reason that says
        // nothing about the pipe capacity, so the quiet flood is asserted
        // exactly — under the cap it is never truncated.
        $this->assertSame(
            self::SAFE_BYTES,
            $this->reportedInt($out, 'STDERRTAIL'),
            'the quiet fixture did not write its stderr, so this row is not a control for the '
            . 'flooding one. Output: ' . trim($out),
        );
    }

    /**
     * CONTROL B — the full flood, but a message UNDER the pipe capacity. The
     * write fits in one buffer and returns before the child's silence can
     * matter, so this row too must pass either way.
     *
     * It also carries the proof that THE FLOOD IS REAL, which neither of the
     * rows above can give: the retained tail is pinned to
     * {@see ClaudeCodeMcpClient}'s own 65536-byte cap, which can only be reached
     * if strictly more than one cap's worth of stderr actually arrived.
     */
    public function testTheFloodIsRealAndASmallMessageWasNeverAtRisk(): void
    {
        [$rc, $out] = $this->runProbe(self::WEDGE_BYTES, self::SMALL_BYTES);

        $this->assertSame(0, $rc, 'the probe itself is broken: ' . trim($out));
        $this->assertSame('ok', $this->reported($out, 'SENT'), trim($out));
        $this->assertSame(
            65536,
            $this->reportedInt($out, 'STDERRTAIL'),
            'the flooding fixture did not fill a pipe buffer, so the rows above are not about a '
            . 'blocked child at all. Output: ' . trim($out),
        );
    }

    // =========================================================================
    // Defect 2 — the dropped partial line
    // =========================================================================

    /**
     * A REPLY THAT ARRIVES IN TWO PIECES IS STILL A REPLY.
     *
     * The two arms share ONE fixture generator and differ only in whether the
     * child's line crosses a poll boundary, so the pair cannot be satisfied by a
     * fixture that stopped writing: the `whole` arm is the known-positive
     * control and it fails loudly if the plumbing breaks.
     */
    public function testAReplySplitAcrossPollBoundariesIsNotThrownAway(): void
    {
        $this->assertSame(
            ['1'],
            $this->idsSeenOverSplitWriter(false),
            'the CONTROL row failed: a reply written whole was not seen at all, so this test is '
            . 'not measuring what it claims',
        );

        $this->assertSame(
            ['1'],
            $this->idsSeenOverSplitWriter(true),
            'a reply whose line arrived in two pieces was dropped. readMessages() must keep the '
            . 'partial line across calls — the caller then reports "No response received", which '
            . 'reads as a dead server',
        );
    }

    /**
     * And the buffer does not OUTLIVE the session it belongs to. A reconnect that
     * inherited a half-line would put a stranger's bytes at the head of the next
     * server's first message, which is the same desynchronisation one pipe over.
     */
    public function testTheHalfLineDoesNotSurviveADisconnect(): void
    {
        $client = $this->connectedClientOver($this->splitWriterScript(true));

        try {
            $buffer = new \ReflectionProperty(ClaudeCodeMcpClient::class, 'readBuffer');

            // POLLED, not read once: the fixture's first write races `connect()`'s
            // own drain, so a single call proves nothing about either outcome.
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline && $buffer->getValue($client) === '') {
                $client->readMessages();
                usleep(20000);
            }

            $this->assertNotSame(
                '',
                $buffer->getValue($client),
                'the control: the fixture leaves a partial line pending, so the assertion below '
                . 'is about disconnect() clearing it and not about it never being set',
            );

            $client->disconnect();

            $this->assertSame('', $buffer->getValue($client), 'disconnect() left a half-line behind');
        } finally {
            $client->disconnect();
        }
    }

    // =========================================================================
    // The idle bound, and what does NOT reset it
    // =========================================================================

    /**
     * A WRITE TO A LIVE CHILD THAT HAS STOPPED READING ENDS, and it ends on the
     * IDLE clock rather than on the child's lifetime.
     *
     * This is the shape E480 measured one class over and found unbounded there:
     * with no deadline, `stream_select()` times out every poll, `$ready === 0`
     * continues, and no liveness check is consulted — so the loop ran until the
     * child died. `CHILDALIVE` is asserted for exactly that reason: without it,
     * a row saying "it returned false, and not too quickly" scores a fixture
     * that simply expired as a pass.
     */
    public function testAWriteToALiveChildThatStoppedReadingEndsOnTheIdleClock(): void
    {
        $client = $this->connectedClientOver($this->deafServerScript());

        try {
            $write = new \ReflectionMethod($client, 'writeAll');
            $start = microtime(true);
            $sent = $write->invoke($client, str_repeat('x', self::OVERSIZED_BYTES), self::TEST_IDLE_SECONDS);
            $elapsed = microtime(true) - $start;

            $this->assertTrue($this->childIsAlive($client), 'the child expired, so this row measured its lifetime and not the idle bound');
            $this->assertLessThan(
                self::OVERSIZED_BYTES,
                $sent,
                'a child that reads nothing accepted the whole message, so the fixture is not deaf',
            );
            $this->assertGreaterThanOrEqual(
                self::TEST_IDLE_SECONDS,
                $elapsed,
                'the write gave up before the idle bound, so something other than the clock ended it',
            );
            $this->assertLessThan(
                self::TEST_IDLE_SECONDS + 5.0,
                $elapsed,
                sprintf('the write ran %.2fs past a %.2fs idle bound', $elapsed, self::TEST_IDLE_SECONDS),
            );
        } finally {
            $client->disconnect();
        }
    }

    /**
     * ⚠️ STDERR TRAFFIC IS NOT PROGRESS, and this is the row that says so.
     *
     * The loop drains fd 2 every pass, because a child parked in `write(2)` on a
     * full stderr pipe is not reading stdin either. The tempting next step is to
     * treat a successful drain as a sign of life and reset the idle clock — and
     * it is wrong: a server that logs forever while never reading is the "live,
     * chatty, never answering" shape
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer::start()} documents, and it
     * would hold this loop open for as long as it kept talking.
     *
     * The fixture writes stderr continuously and reads NOTHING, so the only
     * thing that could reset the clock is the drain.
     */
    public function testAChildThatOnlyEverTalksOnStderrDoesNotHoldTheWriteOpen(): void
    {
        $client = $this->connectedClientOver($this->chattyDeafServerScript());

        try {
            $write = new \ReflectionMethod($client, 'writeAll');
            $start = microtime(true);
            $write->invoke($client, str_repeat('x', self::OVERSIZED_BYTES), self::TEST_IDLE_SECONDS);
            $elapsed = microtime(true) - $start;

            $this->assertTrue($this->childIsAlive($client), 'the chatty fixture expired, so this row measured its lifetime');
            $this->assertNotSame('', $client->stderrTail(), 'the fixture wrote no stderr, so the drain was never exercised and this row is vacuous');
            $this->assertLessThan(
                self::TEST_IDLE_SECONDS + 5.0,
                $elapsed,
                sprintf(
                    'the write ran %.2fs against a %.2fs idle bound while the child did nothing but '
                    . 'write to stderr, so draining fd 2 is resetting the progress clock',
                    $elapsed,
                    self::TEST_IDLE_SECONDS,
                ),
            );
        } finally {
            $client->disconnect();
        }
    }

    /**
     * THE OTHER POLARITY, and without it the two rows above are satisfied by a
     * loop that gives up after the bound NO MATTER WHAT. A child that keeps
     * taking bytes must never be abandoned, however long the message is and
     * however far past the idle bound the whole write runs.
     *
     * The fixture reads 8 KiB at a time with a pause between reads, so the total
     * write necessarily exceeds {@see TEST_IDLE_SECONDS} several times over
     * while no single gap does.
     */
    public function testAChildThatKeepsTakingBytesIsNeverAbandoned(): void
    {
        $client = $this->connectedClientOver($this->slowReaderScript());

        try {
            $write = new \ReflectionMethod($client, 'writeAll');
            $payload = str_repeat('x', self::OVERSIZED_BYTES) . "\n";
            $start = microtime(true);
            $sent = $write->invoke($client, $payload, self::TEST_IDLE_SECONDS);
            $elapsed = microtime(true) - $start;

            $this->assertSame(
                strlen($payload),
                $sent,
                'a child that was reading throughout had its message abandoned, so the bound is '
                . 'behaving as a total budget rather than an idle one',
            );
            $this->assertGreaterThan(
                self::TEST_IDLE_SECONDS,
                $elapsed,
                sprintf(
                    'the whole write finished in %.2fs, inside the %.2fs bound — so this row would '
                    . 'pass against a TOTAL budget too and proves nothing about idleness',
                    $elapsed,
                    self::TEST_IDLE_SECONDS,
                ),
            );
        } finally {
            $client->disconnect();
        }
    }

    /**
     * THE BOUND HAS TO BE STATED, AND THE PRODUCTION CALLER STATES THE
     * PRODUCTION ONE.
     *
     * Two halves, because either alone is satisfiable by the wrong thing. The
     * `ArgumentCountError` half pins that `writeAll()` grew no default — E480's
     * lesson, that a bound with a default is a bound a caller inherits without
     * choosing. The reflection half pins that the one production call site
     * passes {@see ClaudeCodeMcpClient::WRITE_IDLE_SECONDS} and not some other
     * literal, which is what stops the constant becoming decoration.
     */
    public function testSendMessageDrivesTheLoopWithTheProductionIdleBound(): void
    {
        $client = $this->connectedClientOver($this->slowReaderScript());

        try {
            $write = new \ReflectionMethod($client, 'writeAll');

            $this->assertSame(
                2,
                $write->getNumberOfRequiredParameters(),
                'writeAll() grew a default for its idle bound, so a caller can inherit the '
                . 'unbounded path by writing nothing — which is the whole of E480',
            );

            try {
                $write->invoke($client, "x\n");
                $this->fail('writeAll() accepted a call with no idle bound');
            } catch (\ArgumentCountError $e) {
                $this->assertStringContainsString('writeAll', $e->getMessage());
            }

            // The control for the row above: the two-argument call still works,
            // so the assertion is about the DEFAULT and not about the method
            // having been deleted, renamed or made to throw unconditionally.
            $this->assertSame(2, $write->invoke($client, "x\n", self::TEST_IDLE_SECONDS));

            $source = (string) file_get_contents(
                (string) (new \ReflectionClass(ClaudeCodeMcpClient::class))->getFileName(),
            );
            $this->assertStringContainsString(
                '$this->writeAll($payload, self::WRITE_IDLE_SECONDS)',
                $source,
                'sendMessage() no longer drives the loop with WRITE_IDLE_SECONDS, so the constant '
                . 'and its doc-block describe a bound nothing uses',
            );
        } finally {
            $client->disconnect();
        }
    }

    // =========================================================================
    // The NDJSON resynchronisation
    // =========================================================================

    /**
     * A FRAGMENT LEFT IN THE CHILD'S PIPE IS TERMINATED BY THE NEXT SEND, so it
     * costs the child one malformed line rather than one malformed line PLUS the
     * message that would have supplied its newline.
     *
     * The latch is set through reflection rather than by wedging a real write,
     * because reaching the partial state for real needs a child that stops
     * reading mid-message and then starts again — a fixture whose timing would
     * be the thing under test. What is under test here is what the flag DOES.
     *
     * Both polarities in one row: with the flag clear the child sees exactly one
     * line and it parses; with it set the child sees an empty line first. Without
     * the clear arm, a `sendMessage()` that unconditionally prefixed a newline
     * would pass.
     */
    public function testAPendingFragmentIsTerminatedBeforeTheNextMessage(): void
    {
        // The first `ok` is `connect()`'s own `initialize` notification, which
        // every session sends before this row does anything. Asserting the whole
        // sequence rather than a suffix is deliberate: it is what makes the
        // inserted `blank` an INSERTION at a known position rather than an
        // unexplained extra line.
        $this->assertSame(
            ['ok', 'ok'],
            $this->linesSeenAfterSending(false),
            'the control: with no fragment pending the child must see the handshake line and '
            . 'then the message, both parseable and nothing between them',
        );
        $this->assertSame(
            ['ok', 'blank', 'ok'],
            $this->linesSeenAfterSending(true),
            'a pending fragment was not terminated, so the next message supplies the newline '
            . 'that closes it and is lost with it',
        );
    }

    /**
     * AND THE FLAG IS SET BY A SHORT WRITE, not only honoured once set.
     *
     * Driven with an idle bound of zero against a deaf child, which abandons the
     * write after the kernel has taken one pipe buffer and before it can take
     * any more — the partial case, deterministically. The exception's text is
     * asserted too, because "0 of N bytes" and "65536 of N bytes" are different
     * events with different repairs and the message is where a caller learns
     * which one happened.
     */
    public function testAShortWriteReportsItselfAsPartialAndArmsTheResynchronisation(): void
    {
        $client = $this->connectedClientOver($this->deafServerScript());
        $flag = new \ReflectionProperty(ClaudeCodeMcpClient::class, 'stdinFragmentPending');

        try {
            $this->assertFalse($flag->getValue($client), 'the control: nothing is pending on a fresh session');

            try {
                $client->sendMessage(McpMessage::request('1', 'tools/call', [
                    'name' => 'x',
                    'arguments' => ['t' => str_repeat('x', self::OVERSIZED_BYTES)],
                ]));
                $this->fail('a deaf child accepted an oversized message in full');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('resynchronise', $e->getMessage(), $e->getMessage());
                $this->assertStringNotContainsString('0 of ', $e->getMessage(), 'a partial write reported itself as a total loss: ' . $e->getMessage());
            }

            $this->assertTrue(
                $flag->getValue($client),
                'a write that put a prefix in the child pipe did not arm the resynchronisation, so '
                . 'the next message will be swallowed closing the fragment',
            );
        } finally {
            $client->disconnect();
        }
    }

    // =========================================================================
    // Fixtures and helpers
    // =========================================================================

    /** @return array{0: int, 1: string} rc, stdout+stderr */
    private function runProbe(int $floodBytes, int $messageBytes): array
    {
        $server = $this->tempDir . '/blocked_' . $floodBytes . '.php';
        file_put_contents($server, sprintf(self::BLOCKED_SERVER_TEMPLATE, $floodBytes));

        $probe = $this->tempDir . '/probe_' . $floodBytes . '_' . $messageBytes . '.php';
        file_put_contents($probe, sprintf(
            self::PROBE_TEMPLATE,
            var_export(\dirname(__DIR__) . '/vendor/autoload.php', true),
            var_export($server, true),
            $messageBytes,
        ));

        $handle = proc_open(
            [PHP_BINARY, $probe],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($handle);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + self::BOUND_SECONDS;
        $out = '';
        while (microtime(true) < $deadline) {
            foreach ([1, 2] as $fd) {
                $chunk = fread($pipes[$fd], 8192);
                if (\is_string($chunk)) {
                    $out .= $chunk;
                }
            }
            if (!proc_get_status($handle)['running']) {
                break;
            }
            usleep(20000);
        }
        foreach ([1, 2] as $fd) {
            $chunk = fread($pipes[$fd], 65536);
            if (\is_string($chunk)) {
                $out .= $chunk;
            }
            fclose($pipes[$fd]);
        }

        $status = proc_get_status($handle);
        if ($status['running']) {
            proc_terminate($handle, 9);
            proc_close($handle);

            return [-1, $out];
        }

        return [proc_close($handle), $out];
    }

    private function reported(string $out, string $key): string
    {
        return preg_match('/^' . preg_quote($key, '/') . ':(.*)$/m', $out, $m) === 1 ? trim($m[1]) : '';
    }

    private function reportedInt(string $out, string $key): int
    {
        return (int) $this->reported($out, $key);
    }

    private function connectedClientOver(string $script): ClaudeCodeMcpClient
    {
        $client = new ClaudeCodeMcpClient(PHP_BINARY, [$script]);
        $client->connect();

        return $client;
    }

    private function childIsAlive(ClaudeCodeMcpClient $client): bool
    {
        $handle = (new \ReflectionProperty(ClaudeCodeMcpClient::class, 'process'))->getValue($client);

        return \is_resource($handle) && (bool) proc_get_status($handle)['running'];
    }

    /** @return list<string> the ids readMessages() surfaced within 1.8s */
    private function idsSeenOverSplitWriter(bool $split): array
    {
        $client = $this->connectedClientOver($this->splitWriterScript($split));

        try {
            $seen = [];
            for ($i = 0; $i < 60; $i++) {
                foreach ($client->readMessages() as $message) {
                    $seen[] = (string) $message->id;
                }
                usleep(30000);
            }

            return $seen;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @return list<string> 'blank' for an empty line, 'ok' for a parseable one,
     *         'bad' for anything else — as reported by a child that reads NDJSON
     */
    private function linesSeenAfterSending(bool $fragmentPending): array
    {
        $client = $this->connectedClientOver($this->lineReporterScript());

        try {
            if ($fragmentPending) {
                (new \ReflectionProperty(ClaudeCodeMcpClient::class, 'stdinFragmentPending'))->setValue($client, true);
            }

            $client->sendMessage(McpMessage::request('1', 'ping', null));

            $seen = [];
            for ($i = 0; $i < 60 && $seen === []; $i++) {
                foreach ($client->readMessages() as $message) {
                    /** @var array<string, mixed> $result */
                    $result = \is_array($message->result) ? $message->result : [];
                    $seen = array_map('strval', array_values((array) ($result['lines'] ?? [])));
                }
                usleep(30000);
            }

            return $seen;
        } finally {
            $client->disconnect();
        }
    }

    private function script(string $name, string $body): string
    {
        $path = $this->tempDir . '/' . $name . '.php';
        file_put_contents($path, $body);

        return $path;
    }

    /** Answers nothing and reads nothing. Alive for 60s, far past any bound here. */
    private function deafServerScript(): string
    {
        return $this->script('deaf', '<?php $e = microtime(true) + 60; while (microtime(true) < $e) { usleep(50000); }');
    }

    /** Reads nothing, writes stderr continuously. The only thing that could reset an idle clock is the drain. */
    private function chattyDeafServerScript(): string
    {
        return $this->script(
            'chattydeaf',
            '<?php $e = microtime(true) + 60; while (microtime(true) < $e) { fwrite(STDERR, str_repeat("e", 4096)); usleep(5000); }',
        );
    }

    /** Reads 8 KiB at a time with a gap between reads: always progressing, never fast. */
    private function slowReaderScript(): string
    {
        return $this->script(
            'slowreader',
            '<?php $in = fopen("php://stdin", "rb"); stream_set_blocking($in, false);'
            . ' $e = microtime(true) + 60;'
            . ' while (microtime(true) < $e) { $c = fread($in, 4096); if ($c === "" || $c === false) { usleep(5000); continue; } usleep(40000); }',
        );
    }

    /** Writes one JSON-RPC line, optionally in two pieces 400ms apart. */
    private function splitWriterScript(bool $split): string
    {
        $emit = $split
            ? '$h = (int) (strlen($line) / 2); fwrite(STDOUT, substr($line, 0, $h)); fflush(STDOUT); usleep(400000);'
            . ' fwrite(STDOUT, substr($line, $h) . "\n"); fflush(STDOUT);'
            : 'fwrite(STDOUT, $line . "\n"); fflush(STDOUT);';

        return $this->script(
            'splitwriter_' . ($split ? 'split' : 'whole'),
            '<?php $line = json_encode(["jsonrpc" => "2.0", "id" => "1", "result" => ["ok" => true, "pad" => str_repeat("p", 200)]]); '
            . $emit . ' $e = microtime(true) + 20; while (microtime(true) < $e) { usleep(50000); }',
        );
    }

    /**
     * Reads NDJSON lines and reports what it saw, classified — 'blank' for an
     * empty line, 'ok' for one that parsed, 'bad' otherwise. It answers after
     * the SECOND line so the caller sees both the terminator and the message.
     */
    private function lineReporterScript(): string
    {
        return $this->script(
            'linereporter',
            '<?php $in = fopen("php://stdin", "rb"); $seen = []; $deadline = microtime(true) + 6;'
            . ' stream_set_blocking($in, false); $buf = "";'
            . ' while (microtime(true) < $deadline) {'
            . '   $c = fread($in, 8192); if ($c === "" || $c === false) { usleep(10000); }'
            . '   else { $buf .= $c; }'
            . '   while (($n = strpos($buf, "\n")) !== false) {'
            . '     $line = substr($buf, 0, $n); $buf = substr($buf, $n + 1);'
            . '     $seen[] = $line === "" ? "blank" : (json_decode($line, true) === null ? "bad" : "ok");'
            . '   }'
            . '   if ($seen !== [] && microtime(true) > $deadline - 5.4) { break; }'
            . ' }'
            . ' usleep(300000);'
            . ' $c = fread($in, 8192); if (is_string($c)) { $buf .= $c; }'
            . ' while (($n = strpos($buf, "\n")) !== false) {'
            . '   $line = substr($buf, 0, $n); $buf = substr($buf, $n + 1);'
            . '   $seen[] = $line === "" ? "blank" : (json_decode($line, true) === null ? "bad" : "ok");'
            . ' }'
            . ' fwrite(STDOUT, json_encode(["jsonrpc" => "2.0", "id" => "r", "result" => ["lines" => $seen]]) . "\n");'
            . ' fflush(STDOUT); usleep(1500000);',
        );
    }

    /**
     * A server that answers the handshake and THEN floods stderr unprompted, so
     * it is sitting blocked in its own `write(2)` — not waiting for a line —
     * when the parent's next write begins.
     *
     * ⚠️ THE ORDERING IS THE WHOLE FIXTURE. Flooding in RESPONSE to a message
     * lets {@see ClaudeCodeMcpClient::readMessages()} drain it while collecting
     * the reply, so the child would be idle at the read by the time the parent
     * writes again — and the row would pass with the fix removed.
     */
    private const BLOCKED_SERVER_TEMPLATE = <<<'PHP'
<?php
$in = fopen('php://stdin', 'rb');
fgets($in);
fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => 'h', 'result' => ['ready' => true]]) . "\n");
fflush(STDOUT);
fwrite(STDERR, str_repeat('e', %d));
while (($line = fgets($in)) !== false) {
    $line = rtrim($line, "\n");
    $decoded = json_decode($line, true);
    fwrite(STDOUT, json_encode([
        'jsonrpc' => '2.0',
        'id' => is_array($decoded) ? ($decoded['id'] ?? '?') : '?',
        'result' => ['len' => strlen($line), 'parsed' => is_array($decoded)],
    ]) . "\n");
    fflush(STDOUT);
}
PHP;

    /**
     * The out-of-process half. Reports PAYLOAD / SENT / ECHOEDLEN /
     * ECHOEDPARSED / STDERRTAIL on stdout; the caller holds the clock, because
     * the regression this catches does not return.
     */
    private const PROBE_TEMPLATE = <<<'PHP'
<?php
declare(strict_types=1);
require %s;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\McpMessage;

$client = new ClaudeCodeMcpClient(PHP_BINARY, [%s]);
$client->connect();

$request = McpMessage::request('1', 'tools/call', ['name' => 'x', 'arguments' => ['t' => str_repeat('x', %d)]]);
// The child rtrim()s the newline before measuring, so this is the same
// quantity from the other side. An off-by-one here would read as a
// truncated message, which is the defect the row is looking for.
printf("PAYLOAD:%%d\n", strlen($request->toJson()));

try {
    $client->sendMessage($request);
    echo "SENT:ok\n";
} catch (\Throwable $e) {
    echo "SENT:threw ", $e->getMessage(), "\n";
}

$deadline = microtime(true) + 8.0;
while (microtime(true) < $deadline) {
    foreach ($client->readMessages() as $message) {
        if ($message->id === '1' && is_array($message->result)) {
            printf("ECHOEDLEN:%%d\nECHOEDPARSED:%%s\n", $message->result['len'], $message->result['parsed'] ? 'yes' : 'no');
            $deadline = 0.0;
            break;
        }
    }
    usleep(20000);
}
printf("STDERRTAIL:%%d\n", strlen($client->stderrTail()));
$client->disconnect();
PHP;
}
