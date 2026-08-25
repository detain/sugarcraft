<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * THE TWO NDJSON FRAMERS KEPT A PERSISTENT READ BUFFER AND CAPPED NEITHER OF
 * THEM, WHILE CAPPING THEIR STDERR TAILS IN THE SAME FILE FOR THE SAME REASON.
 *
 * `MAX_STDERR_BYTES = 65536` exists in both classes because a long-lived process
 * must not grow a buffer without limit. `$readBuffer` is the same kind of state
 * with the same lifetime — {@see StdioMcpServer} holds it across every
 * `request()`/`readResponse()` round trip, and {@see ClaudeCodeMcpClient} polls
 * `readMessages()` a hundred times per `callTool()` — and a peer that emits an
 * endless stream WITH NO NEWLINE grew both without bound for the life of the
 * process. The asymmetry was inside one file in each case.
 *
 * ⚠️ EVERY ROW HERE COMES IN A PAIR, AND THE SECOND HALF IS THE LOAD-BEARING
 * ONE. "The buffer did not grow" is satisfied perfectly by a reader that has
 * stopped reading, and "an oversized frame is refused" is satisfied by a class
 * that refuses everything. So each cap is checked at `cap + 1` AND at exactly
 * `cap`, where the answer must be silence.
 *
 * ⚠️ SCOPE, SAID PLAINLY. The {@see ClaudeCodeMcpClient} rows drive the REAL
 * `readMessages()` against a REAL child, so they cover the call site as well as
 * the check. The {@see StdioMcpServer} rows invoke its private
 * `refuseAnOversizedFrame()` directly, so they cover the CHECK and not the one
 * line in `readLine()` that calls it: deleting that call is a mutation these
 * rows do not kill. It survives on purpose rather than by oversight — reaching
 * it needs a child that writes 64 MiB into a pipe with no newline, which is
 * minutes of throughput for a property the sibling class already pins end to
 * end. The call site is instead held by the `readLine()` doc-block and by this
 * sentence; if that trade stops looking right, the row to add is a fixture
 * child, not another reflection call.
 *
 * ⚠️ AND THE FAILURE IS A NAMED THROW, NOT A TRUNCATION, WHICH IS THE PART
 * WORTH ASSERTING ON. Cutting the buffer at the cap would hand
 * {@see \SugarCraft\Crush\McpMessage::parse()} half a line, which comes back as
 * a malformed message — so the diagnostic would blame the PEER for what is in
 * fact this side refusing to hold more. Each row therefore asserts that the
 * message names the cap and that the buffer was DROPPED rather than kept.
 */
final class McpFrameCapTest extends TestCase
{
    /**
     * Deliberately far inside `phpunit.xml`'s `defaultTimeLimit` — see E505. The
     * fixture child here exists only to give the client a live pipe; it must
     * outlive the row and nothing more.
     */
    private const FIXTURE_LIFETIME_SECONDS = 5;

    private string $workDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/r57b_mcp_framecap_' . getmypid() . '_' . bin2hex(random_bytes(6));
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

    public function testStdioServerRefusesAFramePastTheCapAndDropsTheBuffer(): void
    {
        $server = new StdioMcpServer('capped', PHP_BINARY, [], []);
        $cap = self::capOf(StdioMcpServer::class);

        $this->setBuffer($server, str_repeat('x', $cap + 1));

        try {
            $this->refuse($server);
            $this->fail('a frame past the cap was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                (string) $cap,
                $e->getMessage(),
                'the refusal must name the cap, or the reader cannot tell "this side refused" '
                . 'from "the server sent garbage"',
            );
            $this->assertStringContainsString('capped', $e->getMessage(), 'and it must name the server');
        }

        $this->assertSame(
            '',
            $this->buffer($server),
            'the buffer was kept. A live object still holding 64 MiB it can never parse is '
            . 'the leak this cap exists to close, not merely a diagnostic nicety.',
        );
    }

    /**
     * THE POSITIVE HALF. Exactly at the cap is silence — otherwise the row above
     * is satisfied by a guard that refuses every frame, and by a `>=` where a
     * `>` belongs.
     */
    public function testStdioServerAcceptsAFrameExactlyAtTheCap(): void
    {
        $server = new StdioMcpServer('capped', PHP_BINARY, [], []);
        $cap = self::capOf(StdioMcpServer::class);

        $this->setBuffer($server, str_repeat('x', $cap));
        $this->refuse($server);

        $this->assertSame(
            $cap,
            strlen($this->buffer($server)),
            'a frame of exactly the cap must be kept whole — an off-by-one here truncates '
            . 'the largest legitimate payload the transport allows',
        );
    }

    public function testClaudeCodeClientRefusesAFramePastTheCapAndDropsTheBuffer(): void
    {
        $client = $this->connectedClient();
        $cap = self::capOf(ClaudeCodeMcpClient::class);

        try {
            // One byte short, so the ONE byte the child writes is what crosses
            // the line. That keeps the row's subject the check inside
            // readMessages()'s read loop rather than the size of the fixture.
            $this->setBuffer($client, str_repeat('x', $cap));

            try {
                $client->readMessages();
                $this->fail('a frame past the cap was accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString(
                    (string) $cap,
                    $e->getMessage(),
                    'the refusal must name the cap',
                );
                $this->assertStringContainsString(
                    'no newline',
                    $e->getMessage(),
                    'and it must say what the server failed to send, not merely that something '
                    . 'was too big',
                );
            }

            $this->assertSame('', $this->buffer($client), 'the buffer was kept');
        } finally {
            $client->disconnect();
        }
    }

    /**
     * THE POSITIVE HALF for the client: the same fixture, one byte lower, must
     * read cleanly and KEEP what it read as an unterminated tail — which is the
     * property {@see ClaudeCodeMcpClient::$readBuffer} became a property for.
     */
    public function testClaudeCodeClientKeepsAnUnterminatedTailBelowTheCap(): void
    {
        $client = $this->connectedClient();
        $cap = self::capOf(ClaudeCodeMcpClient::class);

        try {
            $this->setBuffer($client, str_repeat('x', $cap - 1));

            $this->assertSame(
                [],
                $client->readMessages(),
                'an unterminated tail is not a message',
            );
            $this->assertSame(
                $cap,
                strlen($this->buffer($client)),
                'the tail was dropped one byte below the cap, so the cap is off by one — or '
                . 'the fixture child never wrote, in which case the row above proves nothing '
                . 'either',
            );
        } finally {
            $client->disconnect();
        }
    }

    public function testBothClassesDeclareTheSameCapAndItIsTheFrameCapNotTheStderrCap(): void
    {
        $stdio = self::capOf(StdioMcpServer::class);
        $claude = self::capOf(ClaudeCodeMcpClient::class);

        $this->assertSame(64 * 1024 * 1024, $stdio, 'StdioMcpServer::MAX_FRAME_BYTES moved');
        $this->assertSame($stdio, $claude, 'the two NDJSON framers disagree about the frame cap');
        $this->assertNotSame(
            $stdio,
            (new \ReflectionClass(StdioMcpServer::class))->getConstant('MAX_STDERR_BYTES'),
            'the frame cap and the stderr cap have collapsed into one number. They answer '
            . 'different questions: 65536 is one pipe buffer, which is where an undrained '
            . 'stderr stops the child dead; the frame cap is how large a legitimate payload '
            . 'may be.',
        );
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private static function capOf(string $class): int
    {
        /** @var int $cap */
        $cap = (new \ReflectionClass($class))->getConstant('MAX_FRAME_BYTES');

        return $cap;
    }

    private function setBuffer(object $target, string $value): void
    {
        $property = new \ReflectionProperty($target, 'readBuffer');
        $property->setValue($target, $value);
    }

    private function buffer(object $target): string
    {
        /** @var string $value */
        $value = (new \ReflectionProperty($target, 'readBuffer'))->getValue($target);

        return $value;
    }

    /** Invoke StdioMcpServer's private cap check against its current buffer. */
    private function refuse(StdioMcpServer $server): void
    {
        (new \ReflectionMethod($server, 'refuseAnOversizedFrame'))->invoke($server);
    }

    /**
     * A connected {@see ClaudeCodeMcpClient} whose child writes ONE byte with no
     * newline and then idles, so `readMessages()` has a live pipe with exactly
     * one byte waiting on it.
     */
    private function connectedClient(): ClaudeCodeMcpClient
    {
        $script = $this->workDir . '/one_byte.php';
        file_put_contents($script, sprintf(
            "<?php\nfwrite(STDOUT, 'x');\nfflush(STDOUT);\nsleep(%d);\n",
            self::FIXTURE_LIFETIME_SECONDS,
        ));

        $client = new ClaudeCodeMcpClient(PHP_BINARY, [$script]);
        $client->connect();

        // The child's byte has to have ARRIVED, or both rows below measure the
        // fixture's scheduling rather than the cap. Bounded so a fixture that
        // never writes fails by assertion rather than by the suite's alarm.
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            if ($this->pipeHasData($client)) {
                return $client;
            }
            usleep(2000);
        }

        $this->fail('the fixture child wrote nothing within 3s; every row here would be vacuous');
    }

    private function pipeHasData(ClaudeCodeMcpClient $client): bool
    {
        /** @var array<int, resource>|null $pipes */
        $pipes = (new \ReflectionProperty($client, 'pipes'))->getValue($client);
        if ($pipes === null || !is_resource($pipes[1])) {
            return false;
        }

        $read = [$pipes[1]];
        $write = [];
        $except = [];

        return @stream_select($read, $write, $except, 0, 0) === 1;
    }
}
