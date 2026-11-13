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
 * drift from the real `serve`, the weld test additionally rides the real
 * P1 trust door — and the report says which of the two proved what.
 */

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Fixture;

use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\LoopInterface;
use SugarCraft\Code\Attachments\FileMentions;
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

    /**
     * Test-only dial: every session.subscribe answers with the `reset`
     * branch shape (throughSeq 40, a two-row snapshot window) instead of
     * the snapshot/replay defaults — and no event frames follow. The core
     * never re-streams the gap window after a reset; that is what makes a
     * discarded snapshot lossy (SessionFeed::subscribe pins lastSent to
     * throughSeq and the live gate drops seq <= lastSent).
     */
    public bool $resetOnSubscribe = false;

    /**
     * Test-only dial (pairs with resetOnSubscribe): the reset answer
     * carries NO snapshot key at all — the worst carrier-loss shape.
     */
    public bool $discardOnly = false;

    public ?string $lastIdempotencyKey = null;

    public string $seenText = '';

    public int $connectionCount = 0;

    public string $lastDelivery = '';

    public bool $lastHasMedia = false;

    /** Attachment counts of the last admitted session.send, and the mime-mismatch refusals the real serve would issue. */
    public int $lastImageCount = 0;

    public int $lastDocumentCount = 0;

    public int $mimeRefusals = 0;

    public bool $refuseMediaOnce = false;

    public int $cancelSeen = 0;

    public int $execSeen = 0;

    public ?string $lastReply = null;

    public ?string $lastNote = null;

    /** @var array<string,mixed> the session.get answer; empty = a plain idle snapshot */
    public array $sessionSnapshot = [];

    public int $getSeen = 0;

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
                if ($this->resetOnSubscribe) {
                    // The reset branch of SessionFeed::subscribe: ONE answer
                    // carrying the snapshot window, lastSent pinned to
                    // throughSeq, and the missed seqs NEVER streamed again.
                    $window = ['reset' => true, 'throughSeq' => 40, 'pendingAsks' => []];
                    if (!$this->discardOnly) {
                        $window['snapshot'] = (object) [
                            'messages' => [
                                ['role' => 'assistant', 'content' => 'lost one'],
                                ['role' => 'assistant', 'content' => 'lost two'],
                            ],
                            'status' => 'idle',
                            'pendingAsks' => [],
                        ];
                    }
                    $this->emit($id, [
                        'jsonrpc' => '2.0', 'id' => $rpcId,
                        'result' => (object) $window,
                    ]);
                    break;
                }
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
                $this->lastImageCount = \is_array($params['images'] ?? null) ? \count($params['images']) : 0;
                $this->lastDocumentCount = \is_array($params['documents'] ?? null) ? \count($params['documents']) : 0;
                // The real serve re-sniffs every attachment and refuses a declared
                // mime that differs from the sniffed one (TurnMethods); mirror it.
                $mismatch = $this->sniffMismatch((array) ($params['images'] ?? []), true)
                    ?? $this->sniffMismatch((array) ($params['documents'] ?? []), false);
                if ($mismatch !== null) {
                    $this->mimeRefusals++;
                    $this->emit($id, [
                        'jsonrpc' => '2.0', 'id' => $rpcId,
                        'error' => ['code' => -32602, 'message' => 'invalid params', 'data' => (object) ['kind' => $mismatch]],
                    ]);
                    break;
                }
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
            case 'session.get':
                $this->getSeen++;
                $snapshot = $this->sessionSnapshot + [
                    'sessionId' => (string) ($params['sessionId'] ?? ''),
                    'status' => 'idle',
                    'messages' => [],
                    'queued' => [],
                    'spentUsd' => 0.0,
                    'lastSeq' => 0,
                ];
                $this->emit($id, ['jsonrpc' => '2.0', 'id' => $rpcId, 'result' => (object) $snapshot]);
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
    /** @param list<mixed> $entries the kind of the first declared-vs-sniffed mismatch, or null */
    private function sniffMismatch(array $entries, bool $image): ?string
    {
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $bytes = (string) \base64_decode((string) ($entry['data'] ?? ''), true);
            $sniffed = $image ? FileMentions::sniffImage($bytes) : FileMentions::sniffDocument($bytes);
            if ($sniffed !== (string) ($entry['mime'] ?? '')) {
                return $image ? 'image_mime_mismatch' : 'document_mime_mismatch';
            }
        }

        return null;
    }

    private function emit(int $connectionId, array $message): void
    {
        $this->framing[$connectionId]?->sendMessage((string) \json_encode($message, \JSON_THROW_ON_ERROR));
    }
}
