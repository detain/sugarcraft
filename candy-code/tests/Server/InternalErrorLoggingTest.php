<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ratchet\RFC6455\Messaging\Frame;
use React\Stream\ThroughStream;
use SugarCraft\Code\Server\ErrorLog;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Ws\Connection;
use SugarCraft\Code\Server\Ws\MessageHandler;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * ISS-057: what the swallowed failures owe the server.
 *
 * Both doors hide the cause from the CLIENT on purpose — a throwable's
 * message may name a path, quote a prompt or carry a secret — and SERVER.md
 * promises the cause is in the log instead. These pins are that promise:
 * exactly one line, carrying class, message and throw site, written to the
 * sink and nowhere onto the wire.
 *
 * The third shape is a method that refuses with its OWN `RpcError` after
 * catching a cause: the dispatcher's `RpcError` arm answers without logging
 * (a refusal is not an internal failure), so the hiding caller is the one
 * that owes the cause to the server's log sink.
 */
final class InternalErrorLoggingTest extends TestCase
{
    /** @var list<ProtocolFixture> servers this test started, stopped before the scratch goes */
    private array $servers = [];

    /** @var list<string> scratch trees the `bg.spawn` rows block, removed after each test */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->tearDown();
        }
        $this->servers = [];
        foreach ($this->scratchDirs as $dir) {
            ProtocolFixture::removeTree($dir);
        }
        $this->scratchDirs = [];

        parent::tearDown();
    }

    public function testASocketThatDiesOnAMessageLogsTheCauseAndCloses1011(): void
    {
        $lines = [];
        $toClient = new ThroughStream();
        $wire = '';
        $toClient->on('data', static function (string $chunk) use (&$wire): void {
            $wire .= $chunk;
        });
        $fromClient = new ThroughStream();
        new Connection(
            'c9-err',
            'bearer',
            $toClient,
            $fromClient,
            $this->throwingHandler(new \RuntimeException('the workspace at /home/secret/owner exploded')),
            ServerConfig::MAX_CLIENT_FRAME_BYTES,
            null,
            null,
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $fromClient->write(self::maskedTextFrame('ping'));

        self::assertSame(1, \count($lines), 'one line per swallowed failure');
        self::assertStringContainsString('socket c9-err: RuntimeException: the workspace at /home/secret/owner exploded at ', $lines[0]);
        self::assertStringContainsString('InternalErrorLoggingTest.php:', $lines[0], 'the throw site rides the line');
        self::assertStringNotContainsString("\n", $lines[0], 'one line stays one line');

        // The client side of the same event: the close frame, without the cause.
        self::assertStringNotContainsString('exploded', $wire);
        self::assertSame(Frame::CLOSE_SRV_ERR, self::closeCodeOf($wire), 'the wire learns only 1011');
    }

    public function testASocketWithoutASinkStillCloses1011(): void
    {
        $toClient = new ThroughStream();
        $wire = '';
        $toClient->on('data', static function (string $chunk) use (&$wire): void {
            $wire .= $chunk;
        });
        $fromClient = new ThroughStream();
        new Connection('c10', 'bearer', $toClient, $fromClient, $this->throwingHandler(new \LogicException('quiet')), ServerConfig::MAX_CLIENT_FRAME_BYTES);

        $fromClient->write(self::maskedTextFrame('ping'));

        self::assertSame(Frame::CLOSE_SRV_ERR, self::closeCodeOf($wire), 'the absence of a sink changes nothing on the wire');
    }

    public function testErrorLogFlattensAndClipsSoOneFailureIsOneLine(): void
    {
        // The -32099 arms of the dispatcher (and every logFailure from here
        // on) render through this one helper; control bytes must not smuggle
        // extra log lines, and the message must stay bounded.
        $e = new \RuntimeException("line one\nline two\x07\x00" . \str_repeat('x', ErrorLog::MAX_MESSAGE_BYTES + 50));
        $line = ErrorLog::line($e, 'session.turn failed');

        self::assertStringStartsWith('session.turn failed: RuntimeException: line one line two ', $line);
        self::assertStringNotContainsString("\n", $line);
        $message = \explode(' at ', $line, 2)[0];
        self::assertLessThanOrEqual(\strlen('session.turn failed: RuntimeException: ') + ErrorLog::MAX_MESSAGE_BYTES, \strlen($message), 'the message is clipped to MAX_MESSAGE_BYTES');
        self::assertStringContainsString('InternalErrorLoggingTest.php:', $line);
    }

    /**
     * `bg.spawn` is the method-shaped hole: it catches the supervisor's
     * failure, answers a clean refusal so a private IPC path never reaches the
     * client, and rethrows an `RpcError` — which leaves the dispatcher through
     * the arm that logs nothing. The cause has to reach the sink at the
     * method, or SERVER.md's blanket promise is a lie for every `bg.*` refusal.
     */
    public function testABackgroundSpawnRefusalLogsTheCauseItHidFromTheClient(): void
    {
        $lines = [];
        $secret = 'home-only-' . \bin2hex(\random_bytes(4));
        $fixture = $this->fixtureOverASpawnThatCannotStart($secret, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $answer = $fixture->client()->request('bg.spawn', ['task' => 'this daemon never starts']);

        self::assertSame('bg_spawn_failed', $answer['error']['data']['kind'] ?? null, (string) \json_encode($answer));
        self::assertSame(1, \count($lines), 'one line per swallowed cause');
        self::assertStringStartsWith('bg.spawn failed: RuntimeException: ', $lines[0]);
        self::assertStringContainsString($secret, $lines[0], 'the private path the client never sees is what the log is for');
        self::assertStringContainsString('BackgroundSupervisor.php:', $lines[0], 'the throw site rides the line');
        self::assertStringNotContainsString($secret, (string) \json_encode($answer), 'and stays off the wire');
        self::assertStringNotContainsString("\n", $lines[0]);
    }

    /**
     * The absence of a sink changes the answer not at all — the refusal is the
     * contract, the log is a courtesy to the operator.
     */
    public function testABackgroundSpawnRefusalAnswersTheSameWithoutASink(): void
    {
        $fixture = $this->fixtureOverASpawnThatCannotStart('sinkless-' . \bin2hex(\random_bytes(4)), null);

        self::assertSame(
            ['kind' => 'bg_spawn_failed'],
            $fixture->client()->request('bg.spawn', ['task' => 'this daemon never starts'])['error']['data'] ?? [],
        );
    }

    /**
     * A server whose `bg.spawn` cannot get as far as a daemon: the supervisor's
     * temp root sits UNDER a regular file, so its private IPC directory can
     * never be created — `mkdir(…, true)` refuses a non-directory ancestor —
     * and the spawn throws long before a process exists. The blocking file has
     * to outlive the call: an absent path is simply created on the way in.
     */
    private function fixtureOverASpawnThatCannotStart(string $leafName, ?\Closure $log): ProtocolFixture
    {
        $dir = \sys_get_temp_dir() . '/bgspawn-cause-' . \bin2hex(\random_bytes(4));
        \mkdir($dir, 0o700);
        $this->scratchDirs[] = $dir;
        \file_put_contents($dir . '/' . $leafName, '');

        return $this->servers[] = ProtocolFixture::new(
            supervisor: new BackgroundSupervisor(tempRoot: $dir . '/' . $leafName . '/ipc'),
            log: $log,
        );
    }

    /** A handler whose onMessage always throws $boom. */
    private function throwingHandler(\Throwable $boom): MessageHandler
    {
        return new class ($boom) implements MessageHandler {
            public function __construct(private readonly \Throwable $boom)
            {
            }

            public function onOpen(Connection $connection): void
            {
            }

            public function onMessage(Connection $connection, string $payload): void
            {
                throw $this->boom;
            }

            public function onClose(Connection $connection, int $code): void
            {
            }
        };
    }

    /** One unfragmented masked text frame, as a browser would send it. */
    private static function maskedTextFrame(string $text): string
    {
        $key = \random_bytes(4);
        $masked = '';
        foreach (\str_split($text) as $i => $char) {
            $masked .= $char ^ $key[$i % 4];
        }

        return \chr(0x81) . \chr(0x80 | \strlen($text)) . $key . $masked;
    }

    /** The close code of the single unmasked server frame in $wire, else 0. */
    private static function closeCodeOf(string $wire): int
    {
        if (\strlen($wire) < 4 || (\ord($wire[0]) & 0x0f) !== Frame::OP_CLOSE) {
            return 0;
        }

        return (int) \unpack('n', \substr($wire, 2, 2))[1];
    }

    /*
     * The close handshake (CC-LOGIC-A NIT-3): what a peer's CLOSE frame is
     * allowed to drag back onto the wire. The peer's code arrives through
     * the vendor's CloseFrameChecker, whose RECEIVED set is wider than the
     * SEND set — §7.4.2 holds 3000-3999 "reserved for future libraries" —
     * and the reason is ours to send, never the peer's, so it must not
     * echo and must never split a character at the 123-byte payload cap.
     */

    public function testAReservedLibraryCloseCodeIsAnsweredWithNormalClosure(): void
    {
        [$wire, $seen] = $this->answerToPeerClose(3500);

        self::assertSame(Frame::CLOSE_NORMAL, self::closeCodeOf($wire), 'a reserved 3000-3999 code never rides back out raw');
        self::assertSame([Frame::CLOSE_NORMAL], $seen, 'the handler sees the code we actually sent');
    }

    /**
     * The codes the checker can forward and the send set covers: echoed
     * byte-stably, so the clamp costs the legitimate handshake nothing.
     *
     * @return array<string, array{int}>
     */
    public static function sendableCloseCodes(): array
    {
        $cases = [];
        foreach ([1000, 1001, 1002, 1003, 1007, 1008, 1009, 1010, 1011, 4000, 4999] as $code) {
            $cases['code ' . $code] = [$code];
        }

        return $cases;
    }

    #[DataProvider('sendableCloseCodes')]
    public function testADefinedCloseCodeIsEchoedBackByteStably(int $code): void
    {
        [$wire, $seen] = $this->answerToPeerClose($code);

        self::assertSame($code, self::closeCodeOf($wire), 'the echo carries exactly the code the peer sent');
        self::assertSame([$code], $seen);
    }

    public function testAWideCharacterCloseReasonIsCutOnACharacterBoundary(): void
    {
        // 122 ASCII bytes then a 3-byte character: a raw substr(…, 0, 123)
        // would emit half of 'あ' on the wire. mb_strcut backs off to the
        // boundary instead (the AuditHook idiom).
        $reason = \str_repeat('a', 122) . 'あい';
        $toClient = new ThroughStream();
        $wire = '';
        $toClient->on('data', static function (string $chunk) use (&$wire): void {
            $wire .= $chunk;
        });
        $seen = [];
        $connection = new Connection('nce-utf', 'bearer', $toClient, new ThroughStream(), $this->recordingHandler($seen), ServerConfig::MAX_CLIENT_FRAME_BYTES);

        $connection->close(Frame::CLOSE_POLICY, $reason);

        [, $sentReason] = self::closeFrameOf($wire);
        self::assertSame(\str_repeat('a', 122), $sentReason, 'the cut lands on a character boundary');
        self::assertTrue(\mb_check_encoding($sentReason, 'UTF-8'), 'half a character never leaves the process');
        self::assertLessThanOrEqual(123, \strlen($sentReason));
    }

    public function testACloseFrameWithAnEmptyPayloadAnswersNormalClosure(): void
    {
        [$wire, $seen] = $this->answerToPeerClose(null);

        self::assertSame(Frame::CLOSE_NORMAL, self::closeCodeOf($wire));
        self::assertSame([Frame::CLOSE_NORMAL], $seen);
    }

    public function testAPeerSuppliedCloseReasonNeverRidesTheAnswer(): void
    {
        [$wire] = $this->answerToPeerClose(1008, 'forged-by-peer');

        self::assertSame(Frame::CLOSE_POLICY, self::closeCodeOf($wire), '1008 is sendable and echoes');
        self::assertSame(4, \strlen($wire), 'the answer is header plus our code — no reason bytes at all');
        self::assertStringNotContainsString('forged', $wire, 'a peer cannot put words into our close frame');
    }

    /** Drive one client CLOSE frame over a live Connection; return [wire, codes the handler saw]. */
    private function answerToPeerClose(?int $code, string $reason = ''): array
    {
        $toClient = new ThroughStream();
        $wire = '';
        $toClient->on('data', static function (string $chunk) use (&$wire): void {
            $wire .= $chunk;
        });
        $fromClient = new ThroughStream();
        $seen = [];
        new Connection('nce-' . \bin2hex(\random_bytes(3)), 'bearer', $toClient, $fromClient, $this->recordingHandler($seen), ServerConfig::MAX_CLIENT_FRAME_BYTES);

        $fromClient->write(self::maskedCloseFrame($code, $reason));

        return [$wire, $seen];
    }

    /** A handler that records the close codes its socket ends with. */
    private function recordingHandler(array &$codes): MessageHandler
    {
        $codes = [];

        return new class ($codes) implements MessageHandler {
            /** @var list<int> */
            private array $sink;

            /** @param list<int> $codes aliased, so onClose appends reach the caller */
            public function __construct(array &$codes)
            {
                $this->sink = &$codes;
            }

            public function onOpen(Connection $connection): void
            {
            }

            public function onMessage(Connection $connection, string $payload): void
            {
            }

            public function onClose(Connection $connection, int $code): void
            {
                $this->sink[] = $code;
            }
        };
    }

    /** One unfragmented masked CLOSE frame as a browser would send it; null code means an empty payload. */
    private static function maskedCloseFrame(?int $code, string $reason = ''): string
    {
        $payload = $code === null ? '' : \pack('n', $code) . $reason;
        $key = \random_bytes(4);
        $masked = '';
        foreach (\str_split($payload) as $i => $byte) {
            $masked .= $byte ^ $key[$i % 4];
        }

        return \chr(0x80 | Frame::OP_CLOSE) . \chr(0x80 | \strlen($payload)) . $key . $masked;
    }

    /** @return array{int, string} the code and reason of the first unmasked server CLOSE frame */
    private static function closeFrameOf(string $wire): array
    {
        self::assertGreaterThan(3, \strlen($wire), 'a close answer was sent');
        self::assertSame(Frame::OP_CLOSE, \ord($wire[0]) & 0x0f);
        $payloadLength = \ord($wire[1]) & 0x7f;

        return [(int) \unpack('n', \substr($wire, 2, 2))[1], \substr($wire, 4, $payloadLength - 2)];
    }
}
