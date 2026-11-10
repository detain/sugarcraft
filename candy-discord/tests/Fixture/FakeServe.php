<?php

/**
 * The self-check fixture: a miniature `candy-code.v1` endpoint that speaks
 * exactly the frames the bridge client must parse — the 101 upgrade with the
 * echoed subprotocol, the pre-hello gate, id-matched answers, the `event`
 * notification envelope, cursor replay, and a mid-run socket drop that
 * forces the reconnect ladder to re-follow from the persisted seq.
 *
 * It is NOT the real server. Every branch names the product behaviour it
 * mirrors (Dispatcher step 1, SessionFeed::subscribe/replayPage, the
 * AuthMiddleware credential doors). Where the bridge's parse logic could
 * drift from the real `serve`, selfcheck also dials a REAL serve when the
 * sandbox can run one — and the report says which of the two proved it.
 */

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
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Server\ServerConfig;

final class FakeServe
{
    /** @var array<int, MessageBuffer> */
    private array $framing = [];

    /** @var array<int, bool> */
    private array $helloed = [];

    /** @var array<int, ConnectionInterface> */
    private array $clients = [];

    public int $upgradeSeen = 0;

    public string $subprotocolEchoed = '';

    public bool $sawBearerHeader = false;

    public bool $sawAuthSubprotocol = false;

    public int $helloSeen = 0;

    public int $subscribeSeen = 0;

    public int $sendSeen = 0;

    public int $respondSeen = 0;

    public int $createSeen = 0;

    public ?string $createName = null;

    public ?int $lastAfterSeq = null;

    public ?string $lastIdempotencyKey = null;

    public string $seenText = '';

    public int $connectionCount = 0;

    public string $lastDelivery = '';

    public bool $lastHasMedia = false;

    public bool $refuseMediaOnce = false;

    public int $cancelSeen = 0;

    public int $execSeen = 0;

    public ?string $lastReply = null;

    public ?string $lastNote = null;

    private ?SocketServer $server = null;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly string $token,
    ) {
    }

    public function listen(): int
    {
        $server = new SocketServer('127.0.0.1:0', [], $this->loop);
        $this->server = $server;
        $server->on('connection', function (ConnectionInterface $c): void {
            $this->onConnection($c);
        });

        $address = (string) $server->getAddress();
        $parts = \parse_url($address);

        return (int) ($parts['port'] ?? 0);
    }

    public function pushEvent(array $envelope): void
    {
        foreach ($this->framing as $framing) {
            $framing->sendMessage((string) \json_encode(['jsonrpc' => '2.0', 'method' => 'event', 'params' => $envelope], \JSON_THROW_ON_ERROR));
        }
    }

    /** Close every client socket WITHOUT the server going away: a drop. */
    public function dropClients(): void
    {
        foreach ($this->clients as $connection) {
            $connection->close();
        }
        $this->clients = [];
        $this->framing = [];
        $this->helloed = [];
    }

    public function stop(): void
    {
        $this->dropClients();
        $this->server?->close();
    }

    private function onConnection(ConnectionInterface $connection): void
    {
        $this->connectionCount++;
        $id = \spl_object_id($connection);
        $token = $this->token;
        $buffer = '';
        $negotiator = new ServerNegotiator(new RequestVerifier(), new \GuzzleHttp\Psr7\HttpFactory());
        $negotiator->setSupportedSubProtocols([ServerConfig::SUBPROTOCOL]);
        $negotiator->setStrictSubProtocolCheck(true);

        $onData = function (string $chunk) use (&$buffer, $connection, $negotiator, $token, $id, &$onData): void {
            $buffer .= $chunk;
            $end = \strpos($buffer, "\r\n\r\n");
            if ($end === false) {
                return;
            }
            $connection->removeListener('data', $onData);
            $rest = \substr($buffer, $end + 4);

            $request = \GuzzleHttp\Psr7\Message::parseRequest(\substr($buffer, 0, $end + 4));
            $offered = \array_map('trim', \explode(',', $request->getHeaderLine('Sec-WebSocket-Protocol')));
            if ($offered !== ['']) {
                $this->upgradeSeen++;
            }
            $this->sawAuthSubprotocol = \in_array(ServerConfig::AUTH_SUBPROTOCOL_PREFIX . $token, $offered, true);
            $this->sawBearerHeader = $request->getHeaderLine('Authorization') === 'Bearer ' . $token;

            // The two doors AuthMiddleware reads: bearer header and the
            // `candy-code.auth.<token>` subprotocol spelling.
            $authenticated = false;
            foreach ($offered as $offeredProtocol) {
                if (\str_starts_with($offeredProtocol, ServerConfig::AUTH_SUBPROTOCOL_PREFIX)) {
                    $authenticated = $authenticated || \substr($offeredProtocol, \strlen(ServerConfig::AUTH_SUBPROTOCOL_PREFIX)) === $token;
                }
            }
            if ($this->sawBearerHeader) {
                $authenticated = true;
            }
            if (!$authenticated) {
                $connection->write("HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                $connection->close();

                return;
            }

            $handshake = $negotiator->handshake($request);
            if ($handshake->getStatusCode() !== 101) {
                $connection->close();

                return;
            }
            $this->subprotocolEchoed = $handshake->getHeaderLine('Sec-WebSocket-Protocol');
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
                    $this->onMessage($connection, $message->getPayload());
                },
                function ($frame) use ($connection): void {
                    if ($frame->getOpcode() === Frame::OP_CLOSE) {
                        $connection->close();
                    }
                },
                true,
                null,
                Dispatcher::MAX_SERVER_FRAME_BYTES,
                Dispatcher::MAX_SERVER_FRAME_BYTES,
                function (string $bytes) use ($connection): void {
                    $connection->write($bytes);
                },
            );
            $this->clients[$id] = $connection;
            $connection->on('data', function (string $data) use ($id): void {
                $this->framing[$id]?->onData($data);
            });
            $connection->on('close', function () use ($id): void {
                unset($this->clients[$id], $this->framing[$id], $this->helloed[$id]);
            });
            if ($rest !== '') {
                $this->framing[$id]->onData($rest);
            }
        };
        $connection->on('data', $onData);
    }

    /** @param string $payload */
    private function onMessage(ConnectionInterface $connection, string $payload): void
    {
        $id = \spl_object_id($connection);
        $message = \json_decode($payload, true);
        if (!\is_array($message) || !isset($message['id'], $message['method'])) {
            return;
        }
        $rpcId = $message['id'];
        $method = (string) $message['method'];
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        // The pre-hello gate (Dispatcher step 1): any request but the hello
        // is answered -32002 not_initialized until the hello lands.
        if ($method !== 'server.hello' && ($this->helloed[$id] ?? false) !== true) {
            $this->emit($id, [
                'jsonrpc' => '2.0', 'id' => $rpcId,
                'error' => ['code' => -32002, 'message' => 'not_initialized', 'data' => (object) ['kind' => 'not_initialized']],
            ]);

            return;
        }

        switch ($method) {
            case 'server.hello':
                $this->helloed[$id] = true;
                $this->helloSeen++;
                $this->emit($id, [
                    'jsonrpc' => '2.0', 'id' => $rpcId,
                    'result' => (object) [
                        'protocol' => (object) ['major' => 1, 'minor' => 0],
                        'server' => (object) ['version' => 'fixture', 'root' => '/fixture', 'name' => 'fake-serve'],
                        'limits' => (object) ['tickIntervalMs' => 15000, 'maxClientFrameBytes' => Dispatcher::MAX_SERVER_FRAME_BYTES],
                    ],
                ]);
                break;
            case 'session.create':
                $this->createSeen++;
                $this->createName = isset($params['name']) ? (string) $params['name'] : null;
                $this->emit($id, [
                    'jsonrpc' => '2.0', 'id' => $rpcId,
                    'result' => (object) ['id' => 'sess_fixture_1', 'name' => $params['name'] ?? null, 'status' => 'idle'],
                ]);
                break;
            case 'session.subscribe':
                $this->subscribeSeen++;
                $after = $params['afterSeq'] ?? null;
                $this->lastAfterSeq = $after === null ? null : (int) $after;
                if ($after === null) {
                    // Snapshot path (SessionFeed::subscribe, reset branch).
                    $this->emit($id, [
                        'jsonrpc' => '2.0', 'id' => $rpcId,
                        'result' => (object) [
                            'reset' => true,
                            'throughSeq' => 3,
                            'snapshot' => (object) ['messages' => [], 'status' => 'idle', 'pendingAsks' => []],
                            'pendingAsks' => [],
                        ],
                    ]);
                    break;
                }
                // Replay path: answer the cursor, then stream the missed
                // seqs as event notifications (what replayPage does, one
                // page per tick).
                $this->emit($id, [
                    'jsonrpc' => '2.0', 'id' => $rpcId,
                    'result' => (object) ['fromSeq' => (int) $after + 1, 'throughSeq' => 7, 'pendingAsks' => []],
                ]);
                for ($seq = (int) $after + 1; $seq <= 7; $seq++) {
                    $this->pushEvent([
                        'sessionId' => 'sess_fixture_1', 'seq' => $seq, 'type' => 'tool.started',
                        'ts' => 1, 'durable' => true, 'data' => (object) ['tool' => 'Bash', 'replayed' => true],
                    ]);
                }
                break;
            case 'session.send':
                $this->sendSeen++;
                $this->seenText = (string) ($params['text'] ?? '');
                $this->lastIdempotencyKey = isset($params['idempotencyKey']) ? (string) $params['idempotencyKey'] : null;
                $this->lastDelivery = (string) ($params['delivery'] ?? '');
                $this->lastHasMedia = isset($params['images']) || isset($params['documents']);
                if ($this->lastHasMedia && $this->refuseMediaOnce) {
                    $this->refuseMediaOnce = false;
                    $this->emit($id, [
                        'jsonrpc' => '2.0', 'id' => $rpcId,
                        'error' => ['code' => -32602, 'message' => 'invalid params', 'data' => (object) ['kind' => 'image_mime_mismatch']],
                    ]);
                    break;
                }
                $this->emit($id, [
                    'jsonrpc' => '2.0', 'id' => $rpcId,
                    'result' => (object) ['admitted' => 'started', 'turnId' => 'turn_fixture_1'],
                ]);
                break;
            case 'session.cancel':
                $this->cancelSeen++;
                $this->emit($id, ['jsonrpc' => '2.0', 'id' => $rpcId, 'result' => (object) ['cancelled' => true]]);
                break;
            case 'command.exec':
                $this->execSeen++;
                $this->emit($id, [
                    'jsonrpc' => '2.0', 'id' => $rpcId,
                    'result' => (object) ['rows' => [['role' => 'system', 'content' => 'switched', 'uiOnly' => false]], 'effects' => []],
                ]);
                break;
            case 'permission.respond':
                $this->respondSeen++;
                $this->lastReply = isset($params['reply']) ? (string) $params['reply'] : null;
                $this->lastNote = isset($params['note']) ? (string) $params['note'] : null;
                $this->emit($id, [
                    'jsonrpc' => '2.0', 'id' => $rpcId,
                    'result' => (object) ['answered' => true, 'askId' => $params['askId'] ?? null, 'reply' => $params['reply'] ?? null],
                ]);
                break;
            case 'session.unsubscribe':
                $this->emit($id, ['jsonrpc' => '2.0', 'id' => $rpcId, 'result' => (object) ['unsubscribed' => true]]);
                break;
            default:
                $this->emit($id, [
                    'jsonrpc' => '2.0', 'id' => $rpcId,
                    'error' => ['code' => -32601, 'message' => "method not found: {$method}", 'data' => (object) ['kind' => 'not_found']],
                ]);
        }
    }

    /** @param array<string,mixed> $message */
    private function emit(int $connectionId, array $message): void
    {
        $this->framing[$connectionId]?->sendMessage((string) \json_encode($message, \JSON_THROW_ON_ERROR));
    }
}
