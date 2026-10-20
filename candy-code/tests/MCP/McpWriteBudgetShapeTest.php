<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\ClaudeCodeMcpClient;
use SugarCraft\McpClient\McpMessage;
use SugarCraft\Code\Tests\Support\ClaudeMcpHandshakeFixture;

/**
 * THE FIFTEEN-SECOND WRITE BOUND IS A DEAF-SERVER COST, NOT A BIG-MESSAGE COST,
 * AND THE FINDING THAT RAISED IT SAID THE OPPOSITE.
 *
 * E508 recorded that `WRITE_IDLE_SECONDS = 15.0` means "a large `tools/call`
 * from the TUI ... can now park the calling thread for fifteen seconds",
 * attributing the cost to PAYLOAD SIZE. MEASURED on this host (PHP 8.3.6, Linux
 * 6.8), three consecutive takes, `sendMessage()` against two children:
 *
 *     a child that READS  10485853 bytes  ->  0.028s / 0.026s / 0.401s
 *                          1048669 bytes  ->  0.036s / 0.035s / 0.037s
 *     a child that DOES NOT  65629 bytes  ->  15.006s / 15.006s / 15.009s
 *
 * Payload size does not matter. Ten mebibytes to a live reader never approaches
 * the bound, and sixty-five kilobytes to a deaf one pays it in full — 65629
 * being barely one 65536-byte pipe buffer over, i.e. the SMALLEST message that
 * can cost the whole fifteen seconds.
 *
 * ⚠️ THIS FILE PINS THE FIRST HALF ONLY, AND SAYS SO. The deaf-child half is
 * already held in both polarities by `Tests\ClaudeCodeMcpClientStdinWedgeTest`,
 * which owns the idle clock. What was NOT pinned anywhere is that an
 * arbitrarily large payload to a healthy child costs nothing — and that is the
 * half the open question turns on, because it is what says shortening the bound
 * would not break large tool calls. Without it, "15 seconds is too long" and
 * "15 seconds is what a 10 MiB write needs" are indistinguishable.
 *
 * ⚠️ IT ASSERTS A RATIO-TRACKED ORDER OF MAGNITUDE, NOT A STOPWATCH READING.
 * The third take in the table above is 0.401s against 0.026s for the identical
 * payload — a 15x spread on an IDLE box, and worse under load: the closeout at
 * 47e8a1414 measured this row reddening at 4.08s against its then-3.0s flat
 * allowance with 24 shard workers live on the box. So the budget is CALIBRATED:
 * the same seam writes a 100x smaller payload first, timed, and the big write
 * gets `calibration × ratio × slack`, floored at the historic 0.2-of-bound and
 * capped at 0.8-of-bound so no amount of load can make the row accept a write
 * that actually PAID the idle bound. Load on the numerator is load on the
 * denominator; the claim "a healthy write is cheap RELATIVE to the bound"
 * survives — the raw stopwatch never did.
 */
final class McpWriteBudgetShapeTest extends TestCase
{
    /**
     * The largest payload this row pushes. Chosen because it is 160 pipe buffers
     * — comfortably past any single `fwrite()` — and because it is the size the
     * measurement above used, so the row and the doc-block are the same
     * experiment.
     */
    private const LARGE_PAYLOAD_BYTES = 10_000_000;

    /**
     * The calibration write: same seam, same healthy child, 100x smaller. Its
     * measured time scales the big write's allowance by the wire-byte ratio, so
     * a loaded box (which slows BOTH measurements) cannot redden the row for the
     * reason the flat allowance did at the 47e8a1414 closeout (4.08s > 3.0s).
     */
    private const CALIBRATION_PAYLOAD_BYTES = 100_000;

    /** Headroom over pure linear extrapolation of the calibration time. */
    private const CALIBRATION_SLACK = 1.5;

    /**
     * A healthy write must finish inside this FRACTION of
     * `ClaudeCodeMcpClient::WRITE_IDLE_SECONDS` at MINIMUM — the historic flat
     * budget survives as the FLOOR of the calibrated allowance, so on an idle
     * box (where the calibration term lands below it) the row is byte-for-byte
     * as strict as it ever was, and it tracks the bound it is about.
     *
     * ⚠️ THE FLOOR PROTECTS ONE DIRECTION ONLY, and the doc-block used to
     * claim both. WHAT IT SAID: "moving the bound cannot silently make this row
     * vacuous". WHAT IS TRUE NOW: SHRINKING the bound tightens this row, but
     * RAISING it loosens the floor in exact proportion — the CAP below is what
     * keeps a raised bound from making the row vacuous in practice. WHY THIS
     * STILL EARNS ITS PLACE: the direction that matters here is downward,
     * because the finding this row pins is that the write bound is far LONGER
     * than the read loop under it. A raise past a few seconds is a deliberate
     * act that should bring its own measurement.
     */
    private const HEALTHY_WRITE_BUDGET_FRACTION = 0.2;

    /**
     * The allowance NEVER reaches the bound itself, however loaded the box
     * measures the calibration to be: a write that pays the full idle bound is
     * exactly the pathology ("the idle clock became a total one") every row
     * here exists to refuse.
     */
    private const BUDGET_CAP_FRACTION = 0.8;

    private string $workDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/r57b_write_shape_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);

        parent::tearDown();
    }

    public function testATenMegabytePayloadToAReadingChildCostsFarLessThanTheIdleBound(): void
    {
        $bound = self::writeIdleSeconds();

        $receipt = $this->workDir . '/timed_child_received.txt';
        $client = $this->clientReading($receipt);

        try {
            $large = McpMessage::request('1', 'tools/call', [
                'name' => 'x',
                'arguments' => ['t' => str_repeat('x', self::LARGE_PAYLOAD_BYTES)],
            ]);
            $calibration = McpMessage::request('2', 'tools/call', [
                'name' => 'x',
                'arguments' => ['t' => str_repeat('x', self::CALIBRATION_PAYLOAD_BYTES)],
            ]);

            // THE INSTRUMENT, and the discovery that forced it (47e8a1414
            // closeout): timing the parent-side sendMessage() return measures
            // NOTHING about the child — with the 8 KB pipe full, a healthy
            // fwrite parks in write(2) and RETURNS AT THE FIRST PROGRESS, so
            // every sample lands at ~1 ms no matter how starved the reader is
            // (measured: cal 0.001s while the same child took seconds to
            // actually drain 10 MiB). What the row means is
            // RECEIPT-LIMITED THROUGHPUT, so both legs are timed from
            // first-byte-sent until the receipt shows the child's first read,
            // with the pipe provably empty at each start (the settle polls,
            // exact totals). And the calibration is deliberately taken as the
            // FIRST read-burst the child serves: if the big write still pays
            // the warm-up the calibration did not (SIGSTOP under sustained
            // load), it has already proven throughput above the cold sample
            // and the floor carries it. A warm calibration under-reports and
            // reddens a healthy seam under load — measured the hard way.
            $this->assertGreaterThan(
                65536,
                strlen($large->toJson()) + 1,
                'the payload no longer exceeds one pipe buffer, so this row is not testing a '
                . 'multi-write at all and would pass against any bound whatsoever',
            );
            $this->assertSame(
                0,
                $this->countReceipt($receipt),
                'the child counted bytes before the test sent any — the receipt is not a clean '
                . 'baseline and every timing below is meaningless',
            );

            $calibrated = $this->measureReceived($client, $calibration, $receipt);
            $elapsed = $this->measureReceived($client, $large, $receipt);
            $allowance = $this->allowance($bound, $calibrated->seconds, $elapsed->bytes, $calibrated->bytes);

            $this->assertLessThan(
                $allowance,
                $elapsed->seconds,
                sprintf(
                    'a %d-byte message to a child that IS reading took %.3fs to fully arrive, past '
                    . 'the calibrated allowance %.3fs (floor %.3fs = %.0f%% of the %.1fs bound, cap %.3fs, '
                    . 'extrapolation %.3fs = the %.3fs %d-byte calibration write x wire ratio %.1f x %.1f slack). '
                    . 'Either the write loop stopped resetting its clock on progress — which would '
                    . 'turn the idle bound into a total one, the thing the standing rule on '
                    . 'LLM-adjacent timeouts forbids — or this box is beyond even ratio-tracking '
                    . 'and the row needs a quieter home.',
                    $elapsed->bytes,
                    $elapsed->seconds,
                    $allowance,
                    $bound * self::HEALTHY_WRITE_BUDGET_FRACTION,
                    self::HEALTHY_WRITE_BUDGET_FRACTION * 100,
                    $bound,
                    $bound * self::BUDGET_CAP_FRACTION,
                    $calibrated->seconds * ($elapsed->bytes / $calibrated->bytes) * self::CALIBRATION_SLACK,
                    $calibrated->seconds,
                    $calibrated->bytes,
                    $elapsed->bytes / $calibrated->bytes,
                    self::CALIBRATION_SLACK,
                ),
            );
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Time ONE message end-to-end: from just before the first byte is handed to
     * `sendMessage()` until the child's receipt shows ALL wire bytes received,
     * with the pipe provably drained at the start (the previous leg settled to
     * its own exact total). This is the cost the finding under test is about —
     * how long a large tool call keeps the pipeline busy — not the parent-side
     * fwrite return, which parks and resumes invisibly (see the row comment).
     */
    private function measureReceived(ClaudeCodeMcpClient $client, McpMessage $message, string $receipt): object
    {
        $bytes = strlen($message->toJson()) + 1;   // sendMessage() adds the newline
        $before = $this->countReceipt($receipt);
        $started = microtime(true);
        $client->sendMessage($message);
        $this->countAfterItSettles($receipt, $before + $bytes);

        return (object) ['seconds' => microtime(true) - $started, 'bytes' => $bytes];
    }

    private function countReceipt(string $receipt): int
    {
        return is_file($receipt) ? (int) trim((string) file_get_contents($receipt)) : 0;
    }

    /**
     * load-aware budget: linear extrapolation of the calibration write with
     * slack, floored at the historic 0.2-of-bound and capped at 0.8-of-bound so
     * the row can never be talked into accepting a write that paid the bound.
     */
    private function allowance(float $bound, float $calSeconds, int $largeBytes, int $calBytes): float
    {
        $extrapolated = $calSeconds * ($largeBytes / $calBytes) * self::CALIBRATION_SLACK;

        return min(
            $bound * self::BUDGET_CAP_FRACTION,
            max($bound * self::HEALTHY_WRITE_BUDGET_FRACTION, $extrapolated),
        );
    }

    /**
     * THE CONTROL, and without it the row above is satisfied by a client that
     * writes nothing at all in no time at all: the child has to have RECEIVED
     * what was sent.
     */
    public function testTheReadingChildActuallyReceivedTheWholePayload(): void
    {
        $receipt = $this->workDir . '/received.txt';
        $client = $this->clientReading($receipt);

        try {
            $message = McpMessage::request('1', 'tools/call', [
                'name' => 'x',
                'arguments' => ['t' => str_repeat('x', self::LARGE_PAYLOAD_BYTES)],
            ]);
            $expected = strlen($message->toJson()) + 1;   // sendMessage() adds the newline

            // THE BASELINE IS ZERO NOW, and that is a consequence of audit
            // MCP-3 rather than an assumption. The handshake used to be one
            // unanswered notification the counter saw (129 bytes); it is now a
            // request the child must ANSWER, which the fixture prelude does
            // before the counting loop starts. So `connect()` returning at all
            // is the proof this child reads its stdin — the role the old
            // non-zero baseline played — and every byte counted is this row's.
            $before = 0;

            $client->sendMessage($message);

            // POLLED, not read once after an EOF: disconnect() escalates to a
            // signal, so a child that only reports at EOF may never report at
            // all. The child rewrites its running total after every read, so the
            // count converges while the connection is still up.
            $seen = $this->countAfterItSettles($receipt, $before + $expected);

            $this->assertSame(
                $expected,
                $seen - $before,
                'the child received a different number of bytes than sendMessage() put on the '
                . 'wire, so the timing row above measured a short write rather than a whole one',
            );
        } finally {
            $client->disconnect();
        }
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * The child's running byte count once it has reached `$target`, or whatever
     * it reached inside five seconds. Polled rather than read after an EOF:
     * {@see ClaudeCodeMcpClient::disconnect()} escalates to a signal, so a child
     * that only reports at EOF may never report at all.
     */
    private function countAfterItSettles(string $receipt, int $target): int
    {
        $deadline = microtime(true) + 5.0;
        $seen = 0;

        while (microtime(true) < $deadline) {
            $seen = file_exists($receipt) ? (int) trim((string) file_get_contents($receipt)) : 0;
            if ($seen >= $target && $seen > 0) {
                break;
            }
            usleep(5000);
        }

        return $seen;
    }

    private static function writeIdleSeconds(): float
    {
        /** @var float $bound */
        $bound = (new \ReflectionClass(ClaudeCodeMcpClient::class))->getConstant('WRITE_IDLE_SECONDS');

        return $bound;
    }

    /**
     * A connected client whose child reads its stdin as fast as it arrives and,
     * on EOF, writes the total byte count to `$receipt` if one is named.
     */
    private function clientReading(?string $receipt = null): ClaudeCodeMcpClient
    {
        $script = $this->workDir . '/reader_' . bin2hex(random_bytes(6)) . '.php';
        // STDIN, not a second `php://stdin` handle: the handshake prelude reads
        // through STDIN, and any bytes it buffered ahead must reach the count.
        file_put_contents($script, ClaudeMcpHandshakeFixture::around(sprintf(
            "<?php\n\$in = STDIN;\n\$n = 0;\n"
            . "while (!feof(\$in)) {\n"
            . "    \$c = fread(\$in, 65536);\n"
            . "    if (\$c === false) { break; }\n"
            . "    \$n += strlen(\$c);\n"
            . "    %s\n"
            . "    if (\$c === '') { usleep(1000); }\n"
            . "}\n",
            $receipt === null ? '' : sprintf('file_put_contents(%s, (string) $n);', var_export($receipt, true)),
        )));

        $client = new ClaudeCodeMcpClient(PHP_BINARY, [$script]);
        $client->connect();

        return $client;
    }
}
