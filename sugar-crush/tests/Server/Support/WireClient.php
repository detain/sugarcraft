<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server\Support;

use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\Stream\ThroughStream;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\Ws\Connection;
use SugarCraft\Crush\Server\Ws\MessageHandler;

/**
 * One protocol client wired straight to a {@see MessageHandler}, without a
 * socket: a real {@see Connection} over in-memory streams, so every message
 * the server sends is a real RFC 6455 frame, decoded here by ratchet's own
 * buffer in client mode. Requests skip the client-side framing and go to the
 * handler directly — the transport's framing has its own test
 * (`WsUpgradeTest`); this is for what the messages MEAN.
 */
final class WireClient
{
    public readonly Connection $connection;

    public readonly ThroughStream $toClient;

    /** @var list<array<string, mixed>> every message received, in order */
    private array $received = [];

    /** @var list<int> close codes received */
    private array $closes = [];

    private int $nextId = 0;

    private function __construct(private readonly MessageHandler $handler, string $id)
    {
        $this->toClient = new ThroughStream();
        $fromClient = new ThroughStream();
        // A browser's view of close codes: ratchet's checker predates the
        // IANA registry's 1012-1014, and would turn the server's 1013 ("try
        // again later") into a 1002 of its own.
        $checker = new class () extends CloseFrameChecker {
            public function __invoke(int $val): bool
            {
                return ($val >= 1000 && $val <= 1014 && !\in_array($val, [1004, 1005, 1006], true)) || parent::__invoke($val);
            }
        };
        $buffer = new MessageBuffer(
            $checker,
            function (MessageInterface $message): void {
                $decoded = \json_decode($message->getPayload(), true);
                $this->received[] = \is_array($decoded) ? $decoded : ['raw' => $message->getPayload()];
            },
            function (FrameInterface $frame): void {
                if ($frame->getOpcode() === 8) {
                    $payload = $frame->getPayload();
                    $this->closes[] = \strlen($payload) >= 2 ? (int) \unpack('n', \substr($payload, 0, 2))[1] : 1005;
                }
            },
            false,
        );
        $this->toClient->on('data', static fn (string $bytes) => $buffer->onData($bytes));
        $this->connection = new Connection($id, 'bearer', $this->toClient, $fromClient, $handler, ServerConfig::MAX_CLIENT_MESSAGE_BYTES);
    }

    /** Open a client on $handler (what the upgrade does). */
    public static function open(MessageHandler $handler, string $id = 'c1'): self
    {
        $client = new self($handler, $id);
        $handler->onOpen($client->connection);

        return $client;
    }

    /** Send raw text, as one WebSocket message. */
    public function raw(string $text): void
    {
        $this->handler->onMessage($this->connection, $text);
    }

    /**
     * Send a request and return its response (result or error object).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function request(string $method, array $params = []): array
    {
        $id = 'r' . ++$this->nextId;
        $this->raw((string) \json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params]));

        return $this->response($id) ?? ['missing' => true];
    }

    /**
     * The `result` of a request that must succeed.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $response = $this->request($method, $params);
        if (!\array_key_exists('result', $response)) {
            throw new \LogicException($method . ' failed: ' . \json_encode($response));
        }

        return (array) $response['result'];
    }

    /**
     * `server.hello` with v1 defaults.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function hello(array $params = []): array
    {
        return $this->call('server.hello', [
            'minProtocol' => 1,
            'maxProtocol' => 1,
            'client' => ['name' => 'test', 'version' => '0'],
            ...$params,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function response(string|int $id): ?array
    {
        foreach ($this->received as $message) {
            if (\array_key_exists('id', $message) && $message['id'] === $id) {
                return $message;
            }
        }

        return null;
    }

    /**
     * Every event envelope received, in order (optionally of $type only).
     *
     * @return list<array<string, mixed>>
     */
    public function events(?string $type = null): array
    {
        $events = [];
        foreach ($this->received as $message) {
            if (($message['method'] ?? null) === 'event' && ($type === null || ($message['params']['type'] ?? null) === $type)) {
                $events[] = $message['params'];
            }
        }

        return $events;
    }

    /** @return list<string> the types of every event received, in order */
    public function eventTypes(): array
    {
        return \array_map(static fn (array $event): string => (string) $event['type'], $this->events());
    }

    /** @return list<array<string, mixed>> */
    public function received(): array
    {
        return $this->received;
    }

    /** Forget everything received so far. */
    public function clear(): void
    {
        $this->received = [];
    }

    /** @return list<int> */
    public function closeCodes(): array
    {
        return $this->closes;
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
