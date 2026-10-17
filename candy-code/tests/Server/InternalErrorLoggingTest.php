<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Ratchet\RFC6455\Messaging\Frame;
use React\Stream\ThroughStream;
use SugarCraft\Code\Server\ErrorLog;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Ws\Connection;
use SugarCraft\Code\Server\Ws\MessageHandler;

/**
 * ISS-057: what the swallowed failures owe the server.
 *
 * Both doors hide the cause from the CLIENT on purpose — a throwable's
 * message may name a path, quote a prompt or carry a secret — and SERVER.md
 * promises the cause is in the log instead. These pins are that promise:
 * exactly one line, carrying class, message and throw site, written to the
 * sink and nowhere onto the wire.
 */
final class InternalErrorLoggingTest extends TestCase
{
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
            ServerConfig::MAX_CLIENT_MESSAGE_BYTES,
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
        new Connection('c10', 'bearer', $toClient, $fromClient, $this->throwingHandler(new \LogicException('quiet')), ServerConfig::MAX_CLIENT_MESSAGE_BYTES);

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
}
