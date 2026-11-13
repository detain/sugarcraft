<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Fixture;

use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

/**
 * A loopback stand-in for the Discord Gateway v10: it speaks the op-code
 * envelope {op,d,s,t} the client parses, and exposes counters + knobs so
 * the ladder tests (identify → READY → drop → resume, invalid-session,
 * heartbeat, disallowed-intents close) run with zero real network.
 *
 * Handshake and framing reuse the exact ratchet pieces the real client's
 * counterpart (FakeServe) uses — one less invented protocol to be wrong
 * about. Close frames are hand-encoded (server frames are unmasked).
 */
final class FakeGateway
{
    public int $hbMs = 41250;
    /** Emit the pre-v10 `heartbeat_interval_ms` HELLO spelling (fallback-arm pin). */
    public bool $legacyHelloKey = false;
    /** READY handback for `resume_gateway_url` (null = the fake's own trusted address). */
    public ?string $resumeGatewayUrl = null;
    public ?int $closeAfterIdentify = null;
    public bool $ackHeartbeats = true;

    public int $identifyCount = 0;
    public int $resumeCount = 0;
    public int $beatCount = 0;
    /** @var list<int> intents of every identify received, in order */
    public array $identifyIntents = [];
    /** @var array<string,mixed> */
    public array $lastIdentify = [];
    /** @var array<string,mixed> */
    public array $lastResume = [];
    public int $connectionCount = 0;

    private ?SocketServer $server = null;
    /** @var array<int, ConnectionInterface> */
    private array $clients = [];
    /** @var array<int, MessageBuffer> */
    private array $framing = [];

    public function __construct(private readonly LoopInterface $loop) {}

    public function listen(): int
    {
        $server = new SocketServer('127.0.0.1:0', [], $this->loop);
        $this->server = $server;
        $parts = \parse_url((string) $server->getAddress());
        $port = (int) ($parts['port'] ?? 0);
        $server->on('connection', function (ConnectionInterface $connection) use ($port): void {
            $this->onConnection($connection, $port);
        });

        return $port;
    }

    public function wsUrl(): string
    {
        $address = (string) ($this->server?->getAddress() ?? '');
        $parts = \parse_url($address);

        return 'ws://127.0.0.1:' . (int) ($parts['port'] ?? 0);
    }

    /** Close the live connection WITHOUT a close frame: a plain drop. */
    public function dropConnection(): void
    {
        foreach ($this->clients as $id => $connection) {
            $connection->close();
            unset($this->clients[$id], $this->framing[$id]);
        }
    }

    /** Send op 9 (invalid session) with the resumable flag under test. */
    public function sendInvalidSession(bool $resumable): void
    {
        $this->emit(['op' => 9, 'd' => $resumable]);
    }

    public function sendClose(int $code): void
    {
        foreach ($this->clients as $id => $connection) {
            $payload = \pack('n', $code);
            $connection->write(\chr(0x88) . \chr(\strlen($payload)) . $payload);
            $this->loop->addTimer(0.2, static function () use ($connection): void {
                $connection->close();
            });
            unset($this->framing[$id]);
        }
    }

    /** Deliver a raw payload as one TEXT message to every client (malformed-frame arms). */
    public function pushRaw(string $payload): void
    {
        foreach ($this->framing as $framing) {
            $framing->sendMessage($payload);
        }
    }

    public function stop(): void
    {
        $this->dropConnection();
        $this->server?->close();
        $this->server = null;
    }

    private function onConnection(ConnectionInterface $connection, int $port): void
    {
        $this->connectionCount++;
        $id = \spl_object_id($connection);
        $buffer = '';
        $negotiator = new ServerNegotiator(new RequestVerifier(), new \GuzzleHttp\Psr7\HttpFactory());
        $onData = function (string $chunk) use (&$buffer, $connection, $negotiator, $id, $port, &$onData): void {
            $buffer .= $chunk;
            $end = \strpos($buffer, "\r\n\r\n");
            if ($end === false) {
                return;
            }
            $connection->removeListener('data', $onData);
            $rest = \substr($buffer, $end + 4);
            $request = \GuzzleHttp\Psr7\Message::parseRequest(\substr($buffer, 0, $end + 4));
            $handshake = $negotiator->handshake($request);
            if ($handshake->getStatusCode() !== 101) {
                $connection->close();

                return;
            }
            $lines = ['HTTP/1.1 101 Switching Protocols'];
            foreach ($handshake->getHeaders() as $name => $values) {
                if (\strtolower($name) === 'x-powered-by') {
                    continue;
                }
                $lines[] = $name . ': ' . \implode(', ', $values);
            }
            $connection->write(\implode("\r\n", $lines) . "\r\n\r\n");
            $this->framing[$id] = new MessageBuffer(
                new CloseFrameChecker(),
                function ($message) use ($connection): void {
                    $this->onFrame($connection, (string) $message->getPayload());
                },
                function (Frame $frame) use ($connection): void {
                    if ($frame->getOpcode() === Frame::OP_CLOSE) {
                        $connection->close();
                    }
                },
                true,
                null,
                4194304,
                4194304,
                function (string $bytes) use ($connection): void {
                    $connection->write($bytes);
                },
            );
            $this->clients[$id] = $connection;
            $connection->on('data', function (string $data) use ($id): void {
                $buffer = $this->framing[$id] ?? null;
                $buffer?->onData($data);
            });
            $connection->on('close', function () use ($id): void {
                unset($this->clients[$id], $this->framing[$id]);
            });
            // Every gateway connection opens with HELLO (op 10). v10 spells
            // the field `heartbeat_interval`; $legacyHelloKey reproduces the
            // old `_ms` spelling so the fallback arm stays pinned.
            $this->framing[$id]->sendMessage((string) \json_encode([
                'op' => 10,
                'd' => [($this->legacyHelloKey ? 'heartbeat_interval_ms' : 'heartbeat_interval') => $this->hbMs],
            ]));
            if ($rest !== '') {
                $this->framing[$id]->onData($rest);
            }
        };
        $connection->on('data', $onData);
    }

    private function onFrame(ConnectionInterface $connection, string $payload): void
    {
        $frame = \json_decode($payload, true);
        if (!\is_array($frame) || !isset($frame['op'])) {
            return;
        }
        switch ((int) $frame['op']) {
            case 2: // identify
                $this->identifyCount++;
                $this->lastIdentify = \is_array($frame['d'] ?? null) ? $frame['d'] : [];
                $this->identifyIntents[] = (int) ($this->lastIdentify['intents'] ?? 0);
                $close = $this->closeAfterIdentify;
                $this->closeAfterIdentify = null; // consume once
                if ($close !== null) {
                    $this->sendClose($close);

                    return;
                }
                $this->emit([
                    'op' => 0,
                    's' => 1,
                    't' => 'READY',
                    'd' => [
                        'v' => 10,
                        'session_id' => 'gsess',
                        'resume_gateway_url' => $this->resumeGatewayUrl ?? $this->wsUrl(),
                        'application' => ['id' => 'app-9'],
                        'user' => ['id' => 'bot-9'],
                    ],
                ]);
                break;
            case 6: // resume
                $this->resumeCount++;
                $this->lastResume = \is_array($frame['d'] ?? null) ? $frame['d'] : [];
                $this->emit(['op' => 0, 's' => 2, 't' => 'RESUMED', 'd' => new \stdClass()]);
                break;
            case 1: // heartbeat
                $this->beatCount++;
                if ($this->ackHeartbeats) {
                    $this->emit(['op' => 11]);
                }
                break;
        }
    }

    /** @param array<string,mixed> $message */
    private function emit(array $message): void
    {
        foreach ($this->framing as $framing) {
            $framing->sendMessage((string) \json_encode($message));
        }
    }
}
