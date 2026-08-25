<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspConnection;
use SugarCraft\Crush\LSP\LspProtocolException;

/**
 * A `Content-Length` FRAMER TRUSTED THE NUMBER THE PEER SENT IT, IN BOTH
 * DIRECTIONS, AND NEITHER END HAD A BOUND.
 *
 * {@see LspConnection::readMessage()} took the header's declared length, then
 * looped `while (strlen($readBuffer) < $pendingContentLength)` refilling from
 * the pipe. Two failures fell out of that, and the second is not the one the
 * backlog entry was about:
 *
 *  1. TOO LARGE. `Content-Length: 999999999999` the peer never satisfies leaves
 *     {@see LspConnection} accumulating for the life of the connection. The read
 *     paths are deadline-bounded, so the CALLER returns on time while the buffer
 *     keeps growing across later calls — there is no symptom until the process
 *     dies. This is the sharpest of the three framing classes precisely because
 *     the peer NAMES the size it is about to send.
 *  2. NEGATIVE. MEASURED on this host (PHP 8.3.6) against the arithmetic the
 *     body phase uses, with a buffer of "HELLOWORLD":
 *
 *         Content-Length: -5   ->  body 'HELLO', remainder 'WORLD'
 *
 *     `strlen($buffer) < -5` is false, so nothing ever waits, and
 *     `substr($buffer, 0, -5)` means "all but the last five bytes". The peer
 *     named a length and this side consumed a different one — silently. And
 *     `Content-Length` framing has NO resynchronisation point, so that does not
 *     corrupt one message, it desynchronises every message after it.
 *
 * ⚠️ ROW 2's KNOWN-POSITIVE IS THE ARITHMETIC ITSELF, and it is here rather
 * than in prose because a guard that refuses `-5` looks identical whether the
 * unguarded behaviour was "silent misparse" or "harmless no-op". The row
 * re-derives what `substr()` actually does before asserting that the guard stops
 * it happening.
 */
final class LspConnectionFrameCapTest extends TestCase
{
    /**
     * Well inside `defaultTimeLimit`, and deliberately so — see E505. Every
     * fixture here answers or dies within a second; the bound exists to turn a
     * wedge into a named failure rather than a 60-second abort with no reason
     * attached to it.
     */
    private const FIXTURE_LIFETIME_SECONDS = 5;

    private string $workDir = '';

    /** @var list<string> */
    private array $scripts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/r57b_lsp_framecap_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->scripts as $script) {
            @unlink($script);
        }
        @rmdir($this->workDir);

        parent::tearDown();
    }

    public function testADeclaredContentLengthPastTheCapIsRefusedByName(): void
    {
        $conn = $this->connectedTo("Content-Length: 999999999999\r\n\r\n");

        try {
            $this->expectException(LspProtocolException::class);
            $this->expectExceptionMessageMatches('/outside 1\.\./');
            $conn->sendRequest('textDocument/definition', []);
        } finally {
            $conn->disconnect();
        }
    }

    public function testANegativeContentLengthIsRefusedRatherThanSplittingTheStream(): void
    {
        // ---- The known-positive: what the UNGUARDED arithmetic does. If this
        // block ever stops holding, the guard below is protecting against
        // nothing and the row should be re-derived, not deleted.
        $buffer = 'HELLOWORLD';
        $declared = (int) trim('-5');
        $this->assertFalse(
            strlen($buffer) < $declared,
            'a negative declared length does not make the body phase wait, which is why '
            . 'nothing ever timed out on this shape',
        );
        $this->assertSame(
            'HELLO',
            substr($buffer, 0, $declared),
            'substr($buffer, 0, -5) is "all but the last five bytes" — the peer named a '
            . 'length and the old code consumed a different one',
        );
        $this->assertSame(
            'WORLD',
            substr($buffer, $declared),
            'and the bytes it did not consume became the start of the NEXT frame, which is '
            . 'why this desynchronises the stream rather than corrupting one message',
        );

        // ---- The guard.
        $conn = $this->connectedTo("Content-Length: -5\r\n\r\nHELLOWORLD");

        try {
            $this->expectException(LspProtocolException::class);
            $this->expectExceptionMessageMatches('/declared Content-Length -5/');
            $conn->sendRequest('textDocument/definition', []);
        } finally {
            $conn->disconnect();
        }
    }

    /**
     * THE POSITIVE CONTROL, and without it every row above is satisfied by a
     * connection that refuses everything. A well-formed frame an order of
     * magnitude larger than any header still parses and comes back intact.
     */
    public function testAWellFormedFrameFarLargerThanAHeaderStillParses(): void
    {
        $payload = str_repeat('z', 200_000);
        $conn = $this->echoingServer($payload);

        try {
            $response = $conn->sendRequest('textDocument/definition', []);
            $this->assertFalse(
                $response->isFailure(),
                'a well-formed frame was refused: ' . (string) $response->errorMessage,
            );
            /** @var array{uri: string} $result */
            $result = $response->result;
            $this->assertSame(
                $payload,
                $result['uri'],
                'the frame came back truncated, which is the outcome the caps exist to '
                . 'prevent, not to cause',
            );
        } finally {
            $conn->disconnect();
        }
    }

    /**
     * The cap is a real number and the code reads it, rather than the rows above
     * passing because some unrelated refusal fires first.
     */
    public function testTheCapIsTheOneTheClassDeclares(): void
    {
        $cap = (new \ReflectionClass(LspConnection::class))->getConstant('MAX_FRAME_BYTES');

        $this->assertSame(64 * 1024 * 1024, $cap, 'MAX_FRAME_BYTES moved');

        $conn = $this->connectedTo("Content-Length: " . ($cap + 1) . "\r\n\r\n");

        try {
            $this->expectException(LspProtocolException::class);
            $this->expectExceptionMessageMatches('/outside 1\.\.' . $cap . '/');
            $conn->sendRequest('textDocument/definition', []);
        } finally {
            $conn->disconnect();
        }
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * A server that READS the request, takes its id, and answers with a frame
     * carrying `$payload` — id-aware, because a canned reply cannot know which
     * id {@see LspConnection::sendRequest()} will allocate and
     * {@see LspConnection::readResponse()} correctly discards a reply for
     * another id. (The malformed-header rows need no such thing: they fail while
     * parsing the frame, before any id is looked at.)
     */
    private function echoingServer(string $payload): LspConnection
    {
        $script = $this->workDir . '/echo_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($script, sprintf(
            self::ECHOING_SERVER,
            strlen($payload),
            self::FIXTURE_LIFETIME_SECONDS,
        ));
        $this->scripts[] = $script;

        $conn = new LspConnection($script, [$script]);
        $conn->connect(PHP_BINARY, [], $this->workDir, 3.0);

        return $conn;
    }

    /**
     * The id-aware fixture server, as a template taking the payload length and
     * the child's lifetime. A CONST rather than an inline heredoc so that the
     * `\r\n` sequences it needs are written once, in a nowdoc, where nothing
     * interpolates them.
     */
    private const ECHOING_SERVER = <<<'FIXTURE'
        <?php
        $payload = str_repeat('z', %d);
        $crlf = "\r\n";
        $in = fopen('php://stdin', 'rb');
        stream_set_blocking($in, false);
        $buffer = '';
        $deadline = microtime(true) + %d;
        while (microtime(true) < $deadline) {
            $chunk = fread($in, 8192);
            if ($chunk === false || $chunk === '') { usleep(1000); continue; }
            $buffer .= $chunk;
            if (($at = strpos($buffer, $crlf . $crlf)) === false) { continue; }
            $header = substr($buffer, 0, $at);
            $length = 0;
            foreach (explode($crlf, $header) as $line) {
                if (str_starts_with($line, 'Content-Length:')) { $length = (int) trim(substr($line, 15)); }
            }
            $body = substr($buffer, $at + 4, $length);
            $buffer = substr($buffer, $at + 4 + $length);
            $request = json_decode($body, true);
            if (!is_array($request) || !isset($request['id'])) { continue; }
            $reply = json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => ['uri' => $payload]]);
            fwrite(STDOUT, 'Content-Length: ' . strlen($reply) . $crlf . $crlf . $reply);
            fflush(STDOUT);
        }
        FIXTURE;

    /**
     * An {@see LspConnection} attached to a child that writes `$raw` to stdout
     * the moment it starts and then idles, so the first read this connection
     * performs sees exactly those bytes.
     */
    private function connectedTo(string $raw): LspConnection
    {
        $script = $this->workDir . '/server_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($script, sprintf(
            "<?php\nfwrite(STDOUT, %s);\nfflush(STDOUT);\nsleep(%d);\n",
            var_export($raw, true),
            self::FIXTURE_LIFETIME_SECONDS,
        ));
        $this->scripts[] = $script;

        $conn = new LspConnection($script, [$script]);
        $conn->connect(PHP_BINARY, [], $this->workDir, 3.0);

        return $conn;
    }
}
