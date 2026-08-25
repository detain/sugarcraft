<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\McpMessage;

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
 * ⚠️ IT ASSERTS AN ORDER OF MAGNITUDE, NOT A DURATION. The third take above is
 * 0.401s against 0.026s for the identical payload — a 15x spread on an idle box,
 * which is exactly why a tight timing assertion here would be a flake generator
 * on a machine running three test lanes. The claim is "far below the bound", and
 * the threshold is stated as a fraction of the bound rather than as a stopwatch
 * reading.
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
     * A healthy write must finish inside this FRACTION of
     * `ClaudeCodeMcpClient::WRITE_IDLE_SECONDS`, rather than inside a constant
     * of its own, so that the row tracks the bound it is about.
     *
     * ⚠️ THE FRACTION PROTECTS ONE DIRECTION ONLY, and the doc-block used to
     * claim both. WHAT IT SAID: "moving the bound cannot silently make this row
     * vacuous". WHAT IS TRUE NOW: SHRINKING the bound tightens this row, but
     * RAISING it loosens it in exact proportion — at `WRITE_IDLE_SECONDS = 150.0`
     * the budget becomes 30s and a healthy write measured at 0.4s would pass
     * whatever it did. WHY THIS STILL EARNS ITS PLACE: the direction it does
     * cover is the one that matters here, because the finding this row pins is
     * that the write bound is far LONGER than the read loop under it, so the
     * pressure on that constant is downward. A raise past a few seconds is a
     * deliberate act that should bring its own measurement.
     *
     * Generous at 0.2 because the measured spread on an idle box is already 15x
     * (0.026s to 0.401s, three takes, PHP 8.3.6).
     */
    private const HEALTHY_WRITE_BUDGET_FRACTION = 0.2;

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
        $budget = $bound * self::HEALTHY_WRITE_BUDGET_FRACTION;

        $client = $this->clientReading();

        try {
            $message = McpMessage::request('1', 'tools/call', [
                'name' => 'x',
                'arguments' => ['t' => str_repeat('x', self::LARGE_PAYLOAD_BYTES)],
            ]);

            $this->assertGreaterThan(
                65536,
                strlen($message->toJson()),
                'the payload no longer exceeds one pipe buffer, so this row is not testing a '
                . 'multi-write at all and would pass against any bound whatsoever',
            );

            $started = microtime(true);
            $client->sendMessage($message);
            $elapsed = microtime(true) - $started;

            $this->assertLessThan(
                $budget,
                $elapsed,
                sprintf(
                    'a %d-byte message to a child that IS reading took %.3fs, past %.1f%% of '
                    . 'the %.1fs idle bound. Either the write loop stopped resetting its clock '
                    . 'on progress — which would turn the idle bound into a total one, the '
                    . 'thing the standing rule on LLM-adjacent timeouts forbids — or this box '
                    . 'is loaded enough that the row needs a different instrument.',
                    strlen($message->toJson()),
                    $elapsed,
                    self::HEALTHY_WRITE_BUDGET_FRACTION * 100,
                    $bound,
                ),
            );
        } finally {
            $client->disconnect();
        }
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

            // THE BASELINE IS NOT ZERO, and assuming it was is what this row
            // caught first: connect() sends the `initialize` notification
            // through the same sendMessage(), so the child has already counted
            // 129 bytes before this row writes anything. The claim is about the
            // DELTA.
            $before = $this->countAfterItSettles($receipt, 0);
            $this->assertGreaterThan(
                0,
                $before,
                'the child counted nothing for the handshake, so it is not reading at all and '
                . 'the timing row above measured a write into a pipe buffer nobody empties',
            );

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
        file_put_contents($script, sprintf(
            "<?php\n\$in = fopen('php://stdin', 'rb');\n\$n = 0;\n"
            . "while (!feof(\$in)) {\n"
            . "    \$c = fread(\$in, 65536);\n"
            . "    if (\$c === false) { break; }\n"
            . "    \$n += strlen(\$c);\n"
            . "    %s\n"
            . "    if (\$c === '') { usleep(1000); }\n"
            . "}\n",
            $receipt === null ? '' : sprintf('file_put_contents(%s, (string) $n);', var_export($receipt, true)),
        ));

        $client = new ClaudeCodeMcpClient(PHP_BINARY, [$script]);
        $client->connect();

        return $client;
    }
}
