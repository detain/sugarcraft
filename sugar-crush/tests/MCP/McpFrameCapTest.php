<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\Tests\Support\ClaudeMcpHandshakeFixture;

/**
 * THE FRAME CAP FAMILY, PRODUCT SIDE, AFTER PHASE-2a.
 *
 * `MAX_STDERR_BYTES = 65536` exists in both remaining product framers because a
 * long-lived process must not grow a buffer without limit. `$readBuffer` is the
 * same kind of state with the same lifetime — {@see ClaudeCodeMcpClient} polls
 * `readMessages()` a hundred times per `callTool()` — and a peer that emits an
 * endless stream WITH NO NEWLINE grew it without bound for the life of the
 * process. The third member of that family, the stdio transport, moved into
 * `sugarcraft/sugar-mcp` at phase-2a: its cap rows — the oversized-frame
 * refusal, the drop, and the exactly-at-cap positive half, all driven through
 * the real reader — now live in the library suite
 * in the library's own suite), pinned STRONGER than the
 * reflection rows this file used to carry.
 *
 * What remains here is the {@see ClaudeCodeMcpClient} pair (its rows drive the
 * REAL `readMessages()` against a REAL child, so they cover the call site as
 * well as the check) plus the cross-class equality row, which now derives the
 * stdio arm from the library constant instead of a crush declaration.
 *
 * ⚠️ EVERY CAP ROW COMES IN A PAIR, AND THE SECOND HALF IS THE LOAD-BEARING
 * ONE. "The buffer did not grow" is satisfied perfectly by a reader that has
 * stopped reading, and "an oversized frame is refused" is satisfied by a class
 * that refuses everything. So each cap is checked at `cap + 1` AND at exactly
 * `cap`, where the answer must be silence.
 *
 * ⚠️ AND THE CATCHES HERE ARE NARROW ON PURPOSE — the `fail()` sits OUTSIDE the
 * `try`, not inside it. `$this->fail()` raises `AssertionFailedError`, which
 * extends `PHPUnit\Framework\Exception`, which extends `RuntimeException`
 * (verified on this tree, PHP 8.3.6 / PHPUnit 10). A
 * `try { …; $this->fail(…); } catch (\RuntimeException $e)` therefore CATCHES ITS
 * OWN `fail()` and runs the assertions below against the fail message. Both rows
 * here were written that shape and both still went red — but on
 * "'a frame past the cap was accepted' does not contain 67108864", which blames
 * the message rather than the cap. That is one string coincidence away from
 * vacuous, so the shape is gone rather than annotated.
 *
 * ⚠️ AND THE FAILURE IS A NAMED THROW, NOT A TRUNCATION, WHICH IS THE PART
 * WORTH ASSERTING ON. Cutting the buffer at the cap would hand
 * {@see \SugarCraft\Mcp\McpMessage::parse()} half a line, which comes back as
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

    public function testClaudeCodeClientRefusesAFramePastTheCapAndDropsTheBuffer(): void
    {
        $client = $this->connectedClient();
        $cap = self::capOf(ClaudeCodeMcpClient::class);

        try {
            // One byte short, so the ONE byte the child writes is what crosses
            // the line. That keeps the row's subject the check inside
            // readMessages()'s read loop rather than the size of the fixture.
            $this->setBuffer($client, str_repeat('x', $cap));

            $caught = null;

            try {
                $client->readMessages();
            } catch (\RuntimeException $e) {
                $caught = $e;
            }

            $this->assertNotNull($caught, 'a frame past the cap was accepted');
            $this->assertStringContainsString(
                (string) $cap,
                $caught->getMessage(),
                'the refusal must name the cap',
            );
            $this->assertStringContainsString(
                'no newline',
                $caught->getMessage(),
                'and it must say what the server failed to send, not merely that something '
                . 'was too big',
            );
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
        $stdio = self::capOf(\SugarCraft\Mcp\StdioMcpServer::class);
        $claude = self::capOf(ClaudeCodeMcpClient::class);

        $this->assertSame(64 * 1024 * 1024, $stdio, 'the library transport\'s MAX_FRAME_BYTES moved');
        $this->assertSame($stdio, $claude, 'the product framer and the library transport disagree about the frame cap');
        $this->assertSame(
            $stdio,
            EngineBackend::MAX_FRAME_BYTES,
            'the family cap this transport inherits from the engine has drifted apart from it',
        );
        $this->assertNotSame(
            $claude,
            (new \ReflectionClass(ClaudeCodeMcpClient::class))->getConstant('MAX_STDERR_BYTES'),
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

    /**
     * A connected {@see ClaudeCodeMcpClient} whose child writes ONE byte with no
     * newline and then idles, so `readMessages()` has a live pipe with exactly
     * one byte waiting on it.
     */
    private function connectedClient(): ClaudeCodeMcpClient
    {
        $script = $this->workDir . '/one_byte.php';
        // The byte goes out AFTER the handshake connect() waits for (audit
        // MCP-3), so it is still the only thing waiting on the pipe.
        file_put_contents($script, ClaudeMcpHandshakeFixture::around(sprintf(
            "<?php\nfwrite(STDOUT, 'x');\nfflush(STDOUT);\nsleep(%d);\n",
            self::FIXTURE_LIFETIME_SECONDS,
        )));

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
