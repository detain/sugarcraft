<?php

/**
 * The in-process serve fixture (PL-5d): a miniature host that answers the calls a channel
 * makes, with the answer shapes candy-code's serve gives. It implements the HostServices
 * contract directly, so the bundle runs against it the way it runs against the real in-process
 * door, with no socket. The counters and dials are the ones the router tests read.
 *
 * It is NOT the real server. Each answer names the product behaviour it mirrors.
 */

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Fixture;

use CandyCode\Bundles\Discord\Candy\ServeError;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Plugins\Bridge\HostServicesServeClient;
use SugarCraft\Plugins\Host\HostServices;

use function React\Promise\reject;
use function React\Promise\resolve;

final class FakeServe implements HostServices
{
    /** @var list<\Closure(array<string,mixed>): void> */
    private array $listeners = [];

    /** @var array<string, true> sessions the client follows */
    private array $followed = [];

    private bool $connected = false;

    public int $helloSeen = 0;

    public int $subscribeSeen = 0;

    public int $sendSeen = 0;

    public int $respondSeen = 0;

    public int $createSeen = 0;

    public int $createDelayMs = 0;

    public bool $createRefuses = false;

    public ?string $createName = null;

    public ?int $lastAfterSeq = null;

    /** Reset answer on subscribe: the snapshot window with the missed seqs never streamed. */
    public bool $resetOnSubscribe = false;

    /** With resetOnSubscribe: the reset answer carries no snapshot at all. */
    public bool $discardOnly = false;

    public ?string $lastIdempotencyKey = null;

    public string $seenText = '';

    public string $lastDelivery = '';

    public bool $lastHasMedia = false;

    public bool $refuseMediaOnce = false;

    /** Number of upcoming session.send calls refused (kind not_connected). */
    public int $sendRefusals = 0;

    public int $cancelSeen = 0;

    public int $execSeen = 0;

    public ?string $lastReply = null;

    public ?string $lastNote = null;

    /** Refuse the hello, so connect() rejects. */
    public bool $refuseHello = false;

    public function __construct(
        private readonly LoopInterface $loop,
        bool $refuseCreates = false,
    ) {
        $this->createRefuses = $refuseCreates;
    }

    /** The router's client over this host, with the bundle's own refusal mapping. */
    public function client(): HostServicesServeClient
    {
        return new HostServicesServeClient(
            $this,
            static fn (string $method, \Throwable $e): \Throwable => ServeError::fromHost($method, $e),
        );
    }

    /** Deliver an event envelope to every subscribed listener, as the host's event feed does. */
    public function pushEvent(array $envelope): void
    {
        foreach ($this->listeners as $listener) {
            $listener($envelope);
        }
    }

    public function connect(): PromiseInterface
    {
        return $this->hello()->then(static fn (): null => null);
    }

    public function disconnect(): PromiseInterface
    {
        $this->connected = false;
        $this->followed = [];

        return resolve(null);
    }

    public function isConnected(): PromiseInterface
    {
        return resolve($this->connected);
    }

    public function hello(): PromiseInterface
    {
        $this->helloSeen++;
        if ($this->refuseHello) {
            return reject(new FakeRefusal('fixture_refused', 'fixture refuses hello'));
        }
        $this->connected = true;

        return resolve([
            'protocol' => ['major' => 1, 'minor' => 0],
            'server' => ['version' => 'fixture', 'root' => '/fixture', 'name' => 'fake-serve'],
            'limits' => ['tickIntervalMs' => 15000],
        ]);
    }

    public function createSession(?string $name, ?string $permissionMode): PromiseInterface
    {
        $this->createSeen++;
        $this->createName = $name;
        if ($this->createRefuses) {
            return reject(new FakeRefusal('fixture_refused', 'fixture refuses create'));
        }
        $answer = ['id' => 'sess_fixture_1', 'name' => $name, 'status' => 'idle'];
        if ($this->createDelayMs > 0) {
            $deferred = new Deferred();
            $this->loop->addTimer($this->createDelayMs / 1000.0, static fn () => $deferred->resolve($answer));

            return $deferred->promise();
        }

        return resolve($answer);
    }

    public function subscribeSession(string $sessionId, ?int $afterSeq, ?\Closure $cursor): PromiseInterface
    {
        $this->subscribeSeen++;
        $this->lastAfterSeq = $afterSeq;
        $this->followed[$sessionId] = true;

        if ($this->resetOnSubscribe) {
            // The reset branch of SessionFeed::subscribe: the snapshot window, lastSent pinned
            // to throughSeq, and the missed seqs never streamed again.
            $window = ['reset' => true, 'throughSeq' => 40, 'pendingAsks' => []];
            if (!$this->discardOnly) {
                $window['snapshot'] = [
                    'messages' => [
                        ['role' => 'assistant', 'content' => 'lost one'],
                        ['role' => 'assistant', 'content' => 'lost two'],
                    ],
                    'status' => 'idle',
                    'pendingAsks' => [],
                ];
            }

            return resolve($window);
        }
        if ($afterSeq === null) {
            return resolve([
                'reset' => true,
                'throughSeq' => 3,
                'snapshot' => ['messages' => [], 'status' => 'idle', 'pendingAsks' => []],
                'pendingAsks' => [],
            ]);
        }
        // Replay path: answer the cursor, then deliver the missed seqs (replayPage, one page).
        $this->loop->futureTick(function () use ($afterSeq): void {
            for ($seq = $afterSeq + 1; $seq <= 7; $seq++) {
                $this->pushEvent([
                    'sessionId' => 'sess_fixture_1', 'seq' => $seq, 'type' => 'tool.started',
                    'ts' => 1, 'durable' => true, 'data' => ['tool' => 'Bash', 'replayed' => true],
                ]);
            }
        });

        return resolve(['fromSeq' => $afterSeq + 1, 'throughSeq' => 7, 'pendingAsks' => []]);
    }

    public function unsubscribeSession(string $sessionId): PromiseInterface
    {
        unset($this->followed[$sessionId]);

        return resolve(null);
    }

    public function sendTurn(string $sessionId, string $text, string $delivery, ?string $idempotencyKey, array $images, array $documents): PromiseInterface
    {
        $this->sendSeen++;
        $this->seenText = $text;
        $this->lastIdempotencyKey = $idempotencyKey;
        $this->lastDelivery = $delivery;
        $this->lastHasMedia = $images !== [] || $documents !== [];

        if ($this->lastHasMedia && $this->refuseMediaOnce) {
            $this->refuseMediaOnce = false;

            return reject(new FakeRefusal('image_mime_mismatch', 'invalid params'));
        }
        if ($this->sendRefusals > 0) {
            $this->sendRefusals--;

            return reject(new FakeRefusal('not_connected', 'not connected'));
        }

        return resolve(['admitted' => 'started', 'turnId' => 'turn_fixture_1']);
    }

    public function cancelSession(string $sessionId, string $mode): PromiseInterface
    {
        $this->cancelSeen++;

        return resolve(['cancelled' => true]);
    }

    public function respondPermission(string $sessionId, string $askId, string $reply, ?string $note): PromiseInterface
    {
        $this->respondSeen++;
        $this->lastReply = $reply;
        $this->lastNote = $note;

        return resolve(['answered' => true, 'askId' => $askId, 'reply' => $reply]);
    }

    public function sessionSnapshot(string $sessionId, int $limit): PromiseInterface
    {
        return resolve(['messages' => [], 'status' => 'idle', 'pendingAsks' => []]);
    }

    public function execCommand(string $sessionId, string $name, string $args): PromiseInterface
    {
        $this->execSeen++;

        return resolve(['rows' => [['role' => 'system', 'content' => 'switched', 'uiOnly' => false]], 'effects' => []]);
    }

    public function pendingPermissions(): PromiseInterface
    {
        return resolve(['pending' => []]);
    }

    public function subscribeEvents(\Closure $listener): PromiseInterface
    {
        $this->listeners[] = $listener;

        return resolve(null);
    }

    public function subscribeFollowResults(\Closure $listener): PromiseInterface
    {
        return resolve(null);
    }

    public function followedSessions(): PromiseInterface
    {
        return resolve(array_keys($this->followed));
    }
}
