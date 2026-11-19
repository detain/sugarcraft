<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Host\InProcessHostServices;
use SugarCraft\Code\Host\InProcessPeer;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Protocol\Client;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\JsonRpc;
use SugarCraft\Code\Protocol\Methods\ServerMethods;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\SessionFeed;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * PL-5c: the in-process HostServices against a real serve host (no socket), checked
 * against the WebSocket path on the same fixture. Each case runs the call on both doors
 * and asserts the same result shape, or the same refusal (code and kind). Ids and
 * timestamps differ between doors, so results are compared by shape, not value.
 */
final class InProcessHostServicesTest extends TestCase
{
    private ProtocolFixture $fixture;

    private WireClient $wire;

    private InProcessHostServices $host;

    /** The probe dispatcher a test built, stopped in tearDown() so its ticks leave the shared loop. */
    private ?Dispatcher $probe = null;

    /** @var list<string> the server log lines, in order */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new(log: function (string $line): void {
            $this->logs[] = $line;
        });
        $this->wire = $this->fixture->client('wire');
        $this->host = InProcessHostServices::new($this->fixture->dispatcher, Loop::get());
    }

    protected function tearDown(): void
    {
        $this->call($this->host->disconnect());
        $this->probe?->stop('test over');
        $this->fixture->tearDown();
    }

    public function testHelloAnswersTheWireShapeAndOpensTheClient(): void
    {
        $wireHello = WireClient::open($this->fixture->dispatcher, 'wire-hello')->hello();

        $hello = $this->call($this->host->hello());

        self::assertSame(self::shape($wireHello), self::shape($hello));
        self::assertTrue($this->call($this->host->isConnected()));
    }

    public function testConnectIsIdempotentAndASecondHelloIsTheWireConflict(): void
    {
        self::assertNull($this->fixture->await($this->host->connect()));
        self::assertNull($this->fixture->await($this->host->connect()));

        $wireAgain = $this->wire->request('server.hello', ['minProtocol' => 1, 'maxProtocol' => 1, 'client' => ['name' => 'test', 'version' => '0']]);
        $inAgain = $this->refusalOf(fn () => $this->call($this->host->hello()));

        $this->assertSameRefusal($inAgain, $wireAgain);
        self::assertSame('already_initialized', $inAgain->kind);
    }

    public function testCallsBeforeConnectAreTheWireNotInitializedRefusal(): void
    {
        $wireId = $this->wire->call('session.create', [])['id'];
        $unhelloed = WireClient::open($this->fixture->dispatcher, 'unhello');

        $wireSend = $unhelloed->request('session.send', ['sessionId' => $wireId, 'text' => 'hi', 'delivery' => 'queue']);
        $inSend = $this->refusalOf(fn () => $this->call($this->host->sendTurn($wireId, 'hi', 'queue', null, [], [])));

        $this->assertSameRefusal($inSend, $wireSend);
        self::assertSame(ErrorCode::NotInitialized, $inSend->errorCode);
    }

    /**
     * The wire refuses a frame whose text is not UTF-8 as parse_error, before any gate runs,
     * so a turn with a lone invalid byte is refused there. The in-process door refuses the
     * same text the same way instead of substituting the bytes and running the turn.
     */
    public function testNonUtf8TextIsTheWireParseErrorOnBothDoors(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->call($this->host->createSession(null, null))['id'];
        $this->wire->clear();

        $this->wire->raw('{"jsonrpc":"2.0","id":"bad","method":"session.send","params":{"sessionId":"' . $wireId . '","text":"' . "\xFF" . '","delivery":"queue"}}');
        $wireResponse = null;
        foreach ($this->wire->received() as $message) {
            if (\array_key_exists('error', $message)) {
                $wireResponse = $message;
            }
        }
        self::assertNotNull($wireResponse, 'the wire answered the frame with an error');

        $inProcess = $this->refusalOf(fn () => $this->call($this->host->sendTurn($inId, "\xFF", 'queue', null, [], [])));

        $this->assertSameRefusal($inProcess, $wireResponse);
        self::assertSame(ErrorCode::ParseError, $inProcess->errorCode);
        self::assertSame(-32700, $wireResponse['error']['code']);
    }

    /** The UTF-8 gate walks every string the params carry, keys included, not only the prompt. */
    public function testNonUtf8InsideAnAttachmentIsRefusedInProcessToo(): void
    {
        $this->connect();
        $inId = $this->call($this->host->createSession(null, null))['id'];
        $badDocument = ['name' => "\xC3\x28.pdf", 'mime' => 'application/pdf', 'data' => 'AAAA'];

        $inProcess = $this->refusalOf(fn () => $this->call($this->host->sendTurn($inId, '/help', 'queue', null, [], [$badDocument])));

        self::assertSame(ErrorCode::ParseError, $inProcess->errorCode);
        self::assertSame('parse_error', $inProcess->kind);
    }

    /**
     * The wire substitutes invalid UTF-8 in an answer (JsonRpc::encode), so a bundle
     * that json-encodes a result must get the same text on both doors, not the raw bytes.
     */
    public function testAnInvalidUtf8ResultIsSubstitutedOnBothDoors(): void
    {
        $dispatcher = $this->probeDispatcher(static fn (): array => ['text' => "ok\xFF", 'lines' => ["\xC3\x28"]]);
        $wire = WireClient::open($dispatcher, 'probe-wire');
        $wire->hello();

        $wireResult = $wire->call('probe.raw');
        $inResult = $this->probeInProcess($dispatcher);

        self::assertSame($wireResult, $inResult);
        self::assertSame(['text' => "ok\u{FFFD}", 'lines' => ["\u{FFFD}("]], $inResult);
        self::assertNotFalse(\json_encode($inResult, \JSON_THROW_ON_ERROR));
    }

    /**
     * The wire answers a result past the frame ceiling with `too_large` (Dispatcher::answer);
     * the in-process door refuses it the same way, rather than handing back the full array.
     */
    public function testAResultPastTheFrameCeilingIsTheWireTooLargeRefusalOnBothDoors(): void
    {
        $dispatcher = $this->probeDispatcher(static fn (): array => ['blob' => \str_repeat('x', Dispatcher::MAX_SERVER_FRAME_BYTES)]);
        $wire = WireClient::open($dispatcher, 'probe-big');
        $wire->hello();

        $wireResponse = $wire->request('probe.raw');
        $inProcess = $this->refusalOf(fn () => $this->probeInProcess($dispatcher));

        $this->assertSameRefusal($inProcess, $wireResponse);
        self::assertSame(ErrorCode::Internal, $inProcess->errorCode);
        self::assertSame('too_large', $inProcess->kind);
    }

    /**
     * The door measures a result as the frame the wire would send with a null id, without
     * building that frame: a result whose frame is exactly the ceiling is answered, and one
     * byte more is the wire's `too_large` refusal. Pins the envelope overhead the measure adds.
     */
    public function testTheFrameCeilingIsMeasuredWithoutBuildingAFrame(): void
    {
        $fits = Dispatcher::MAX_SERVER_FRAME_BYTES - \strlen(JsonRpc::result(null, ['blob' => '']));
        $size = $fits;
        $dispatcher = $this->probeDispatcher(static function () use (&$size): array {
            return ['blob' => \str_repeat('x', $size)];
        });

        $answer = $this->probeInProcess($dispatcher);
        self::assertSame(Dispatcher::MAX_SERVER_FRAME_BYTES, \strlen(JsonRpc::result(null, $answer)), 'the answer sits exactly at the ceiling');

        $size = $fits + 1;
        $refusal = $this->refusalOf(fn () => $this->probeInProcess($dispatcher));
        self::assertSame(ErrorCode::Internal, $refusal->errorCode);
        self::assertSame('too_large', $refusal->kind);
    }

    /**
     * The bundle's wire client decodes a frame at JsonRpc::MAX_DEPTH and drops a deeper one
     * undecoded, so the in-process door refuses an answer nested 63 levels or more as
     * `too_deep` and hands back one nested 62 levels, the deepest the wire client reads.
     */
    public function testAnAnswerDeeperThanTheWireClientReadsIsATooDeepRefusal(): void
    {
        $levels = 62;
        $dispatcher = $this->probeDispatcher(static function () use (&$levels): array {
            return self::nested($levels);
        });

        $deepest = $this->probeInProcess($dispatcher);
        self::assertSame(self::nested(62), $deepest);
        self::assertNotNull(\json_decode(JsonRpc::result(null, $deepest), true, JsonRpc::MAX_DEPTH), 'the wire client decodes the deepest answer');

        $levels = 63;
        $refusal = $this->refusalOf(fn () => $this->probeInProcess($dispatcher));

        self::assertSame(ErrorCode::Internal, $refusal->errorCode);
        self::assertSame('too_deep', $refusal->kind);
        self::assertNull(\json_decode(JsonRpc::result(null, self::nested(63)), true, JsonRpc::MAX_DEPTH), 'the wire client cannot decode a 63-level answer');
    }

    /**
     * An answer nested 512 levels or more does not encode under the wire's flags at all, so the
     * in-process door refuses it as it refuses any unexpected failure: `internal` with that code's
     * own kind, never `too_deep`. The wire encodes its answers under the same flags, so it fails alike.
     */
    public function testAnAnswerNestedPastTheValueEncoderIsAnInternalRefusalNotTooDeep(): void
    {
        $dispatcher = $this->probeDispatcher(static fn (): array => self::nested(512));

        $refusal = $this->refusalOf(fn () => $this->probeInProcess($dispatcher));

        self::assertSame(ErrorCode::Internal, $refusal->errorCode);
        self::assertSame(ErrorCode::Internal->kind(), $refusal->kind);
        self::assertNotSame('too_deep', $refusal->kind);
    }

    /**
     * A host that cannot open the client (here the dispatcher's timers cannot be armed while
     * the client opens) rejects connect() with the failure. A synchronous throw would skip
     * the bundle's start() retry and give-up handling, which read the rejection.
     */
    public function testAHostThatCannotOpenTheClientRejectsConnect(): void
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->method('addPeriodicTimer')->willThrowException(new \RuntimeException('boot failed'));
        $broken = ProtocolFixture::new(loop: $loop);
        try {
            $host = InProcessHostServices::new($broken->dispatcher, Loop::get());

            $rejection = $broken->await($host->connect());

            self::assertInstanceOf(\RuntimeException::class, $rejection);
            self::assertSame('boot failed', $rejection->getMessage());
            self::assertFalse($this->call($host->isConnected()));
        } finally {
            $broken->tearDown();
        }
    }

    public function testCreateAndSubscribeAnswerTheWireShape(): void
    {
        $this->connect();

        $wireSession = $this->wire->call('session.create', []);
        $inSession = $this->call($this->host->createSession(null, null));
        self::assertSame(self::shape($wireSession), self::shape($inSession));

        $wireSub = $this->wire->call('session.subscribe', ['sessionId' => $wireSession['id'], 'snapshotLimit' => InProcessHostServices::SNAPSHOT_LIMIT]);
        $inSub = $this->call($this->host->subscribeSession($inSession['id'], null, null));
        self::assertSame(self::shape($wireSub), self::shape($inSub));
    }

    public function testATurnAnswersTheWireShapeAndTheEventsMatchInOrder(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->call($this->host->createSession(null, null))['id'];

        $heard = [];
        $this->call($this->host->subscribeEvents(function (EventEnvelope $envelope) use (&$heard, $inId): void {
            if ($envelope->sessionId === $inId) {
                $heard[] = $envelope;
            }
        }));
        $this->wire->call('session.subscribe', ['sessionId' => $wireId]);
        $this->call($this->host->subscribeSession($inId, null, null));
        $this->fixture->run();

        // One turn runs at a time (server.maxConcurrentTurns), so each door's turn
        // is settled before the other starts.
        $wireSend = $this->wire->call('session.send', ['sessionId' => $wireId, 'text' => 'tidy up', 'delivery' => 'queue', 'idempotencyKey' => 'k1']);
        $this->fixture->run(0.08);
        $this->fixture->backend->settle(Message::assistant('done'));
        $this->fixture->run(0.08);

        // The owner bucket is shared by every owner door, so the in-process turn needs its own key.
        $inSend = $this->call($this->host->sendTurn($inId, 'tidy up', 'queue', 'k2', [], []));
        self::assertSame(self::shape($wireSend), self::shape($inSend));
        $this->fixture->run(0.08);
        $this->fixture->backend->settle(Message::assistant('done'));
        $this->fixture->run(0.08);

        $wireTypes = \array_values(\array_map(
            static fn (array $event): string => (string) $event['type'],
            \array_filter($this->wire->events(), static fn (array $event): bool => ($event['sessionId'] ?? null) === $wireId),
        ));
        self::assertNotSame([], $heard, 'the in-process listener heard the turn');
        self::assertContainsOnlyInstancesOf(EventEnvelope::class, $heard);
        self::assertSame($wireTypes, \array_map(static fn (EventEnvelope $e): string => $e->type, $heard));

        $seqs = \array_values(\array_filter(\array_map(static fn (EventEnvelope $e): ?int => $e->seq, $heard), static fn (?int $seq): bool => $seq !== null));
        for ($i = 1; $i < \count($seqs); $i++) {
            self::assertGreaterThan($seqs[$i - 1], $seqs[$i], 'per-session seq order, nothing reordered');
        }
    }

    public function testAThrowingListenerIsLoggedAndTheOthersStillHear(): void
    {
        $this->connect();
        $inId = $this->call($this->host->createSession(null, null))['id'];
        $this->call($this->host->subscribeSession($inId, null, null));
        $this->call($this->host->subscribeEvents(static function (): void {
            throw new \RuntimeException('listener broke');
        }));
        $heard = 0;
        $this->call($this->host->subscribeEvents(function (EventEnvelope $envelope) use (&$heard, $inId): void {
            if ($envelope->sessionId === $inId) {
                $heard++;
            }
        }));

        $this->call($this->host->sendTurn($inId, 'hello', 'queue', null, [], []));
        $this->fixture->run(0.08);

        self::assertGreaterThan(0, $heard);
        self::assertStringContainsString('in-process event listener failed', \implode("\n", $this->logs));
    }

    public function testDisconnectDeliversWhatIsOwedAndLeavesTheHost(): void
    {
        $this->connect();
        $inId = $this->call($this->host->createSession(null, null))['id'];
        $heard = [];
        $this->call($this->host->subscribeEvents(function (EventEnvelope $envelope) use (&$heard): void {
            $heard[] = $envelope;
        }));
        $this->call($this->host->subscribeSession($inId, null, null));
        $this->call($this->host->sendTurn($inId, 'hello', 'queue', null, [], []));

        $this->call($this->host->disconnect());

        self::assertNotSame([], $heard, 'events emitted before disconnect are not dropped');
        self::assertFalse($this->call($this->host->isConnected()));
        self::assertSame([], $this->call($this->host->followedSessions()));
    }

    public function testFollowedSessionsTracksSubscribeAndUnsubscribe(): void
    {
        $this->connect();
        $inId = $this->call($this->host->createSession(null, null))['id'];

        $this->call($this->host->subscribeSession($inId, null, null));
        self::assertSame([$inId], $this->call($this->host->followedSessions()));

        $this->call($this->host->unsubscribeSession($inId));
        self::assertSame([], $this->call($this->host->followedSessions()));
    }

    public function testRefusalsAreTheWireRefusals(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];

        $this->assertSameRefusal(
            $this->refusalOf(fn () => $this->call($this->host->sendTurn($wireId, 'hi', 'nope', null, [], []))),
            $this->wire->request('session.send', ['sessionId' => $wireId, 'text' => 'hi', 'delivery' => 'nope']),
        );
        $this->assertSameRefusal(
            $this->refusalOf(fn () => $this->call($this->host->sessionSnapshot('no-such-session', 10))),
            $this->wire->request('session.get', ['sessionId' => 'no-such-session', 'limit' => 10]),
        );
        $this->assertSameRefusal(
            $this->refusalOf(fn () => $this->call($this->host->respondPermission($wireId, 'no-such-ask', 'once', null))),
            $this->wire->request('permission.respond', ['sessionId' => $wireId, 'askId' => 'no-such-ask', 'reply' => 'once']),
        );
        $this->assertSameRefusal(
            $this->refusalOf(fn () => $this->call($this->host->execCommand($wireId, 'no-such-command', ''))),
            $this->wire->request('command.exec', ['sessionId' => $wireId, 'name' => 'no-such-command']),
        );
    }

    public function testReadsAnswerTheWireShape(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->call($this->host->createSession(null, null))['id'];

        self::assertSame(
            self::shape($this->wire->call('session.get', ['sessionId' => $wireId, 'limit' => 10])),
            self::shape($this->call($this->host->sessionSnapshot($inId, 10))),
        );
        self::assertSame(
            self::shape($this->wire->call('permission.pending', [])),
            self::shape($this->call($this->host->pendingPermissions())),
        );
        self::assertSame(
            self::shape($this->wire->call('session.cancel', ['sessionId' => $wireId, 'mode' => 'hard'])),
            self::shape($this->call($this->host->cancelSession($inId, 'hard'))),
        );
    }

    /**
     * The wire fires follow results only from the reconnect re-follow (ServeBridge::follow never
     * fires them), and this door never reconnects. So neither the first subscribe nor a manual
     * re-subscribe reaches a follow listener: the first answer is subscribeSession()'s return value.
     * Registering a listener, even a throwing one, never refuses and never runs.
     */
    public function testFollowResultListenersNeverFireInProcess(): void
    {
        $this->connect();
        $inId = $this->call($this->host->createSession(null, null))['id'];
        $heard = 0;
        $this->call($this->host->subscribeFollowResults(static function (): void {
            throw new \RuntimeException('follow listener must not run');
        }));
        $this->call($this->host->subscribeFollowResults(function () use (&$heard): void {
            $heard++;
        }));

        $first = $this->call($this->host->subscribeSession($inId, null, null));
        $again = $this->call($this->host->subscribeSession($inId, $first['throughSeq'] ?? null, null));

        self::assertIsArray($first);
        self::assertIsArray($again);
        self::assertSame(0, $heard, 'no follow listener fires, on the first subscribe or a re-subscribe');
        self::assertStringNotContainsString('follow-result listener', \implode("\n", $this->logs));
    }

    public function testUnsubscribeAfterDisconnectIsQuietLikeTheWireWrapper(): void
    {
        $this->connect();
        $inId = $this->call($this->host->createSession(null, null))['id'];
        $this->call($this->host->subscribeSession($inId, null, null));
        $this->call($this->host->disconnect());

        $this->call($this->host->unsubscribeSession($inId));

        self::assertFalse($this->call($this->host->isConnected()));
        self::assertSame([], $this->call($this->host->followedSessions()));
    }

    public function testADisconnectFromAListenerDoesNotReorderWhatIsStillOwed(): void
    {
        $this->connect();
        $inId = $this->call($this->host->createSession(null, null))['id'];
        $client = (new \ReflectionProperty(InProcessHostServices::class, 'client'))->getValue($this->host);
        // session.created is queued by createSession(); flush it before the listeners attach.
        $this->fixture->run();
        $first = [];
        $second = [];
        $this->call($this->host->subscribeEvents(function (EventEnvelope $envelope) use (&$first): void {
            $first[] = $envelope->type;
            if ($envelope->type === 'first') {
                $this->host->disconnect();
            }
        }));
        $this->call($this->host->subscribeEvents(function (EventEnvelope $envelope) use (&$second): void {
            $second[] = $envelope->type;
        }));

        // Two events emitted in one synchronous run: the first listener's disconnect must not
        // let the second event reach the listeners ahead of the first.
        $client->emit(EventEnvelope::ephemeral($inId, 'first', []));
        $client->emit(EventEnvelope::ephemeral($inId, 'second', []));
        $this->fixture->run(0.08);

        self::assertSame(['first', 'second'], $first);
        self::assertSame(['first', 'second'], $second, 'every listener hears the owed events in emit order');
        self::assertFalse($this->call($this->host->isConnected()));
    }

    /**
     * The routing limit (PL-5c-B): the in-process door serves the primary root only. A session
     * that lives on another root is refused session_not_found here, the refusal the primary wire
     * gives an id it does not hold, except permission.respond, which answers ask_not_found: it
     * finds no hosted session and no relayed question to answer. Step (A) of Phase 5 lifts this
     * limit; this pins it until then.
     */
    public function testANonPrimarySessionIsRefusedSessionNotFoundOnTheInProcessDoor(): void
    {
        $this->connect();
        $other = ProtocolFixture::new();
        try {
            $otherId = $other->session($other->client('other-root'));

            $inSend = $this->refusalOf(fn () => $this->call($this->host->sendTurn($otherId, 'hi', 'queue', null, [], [])));
            self::assertSame('session_not_found', $inSend->kind);
            $this->assertSameRefusal(
                $inSend,
                $this->wire->request('session.send', ['sessionId' => $otherId, 'text' => 'hi', 'delivery' => 'queue']),
            );

            $inSubscribe = $this->refusalOf(fn () => $this->call($this->host->subscribeSession($otherId, null, null)));
            self::assertSame('session_not_found', $inSubscribe->kind);
            $this->assertSameRefusal(
                $inSubscribe,
                $this->wire->request('session.subscribe', ['sessionId' => $otherId]),
            );

            $inSnapshot = $this->refusalOf(fn () => $this->call($this->host->sessionSnapshot($otherId, 10)));
            self::assertSame('session_not_found', $inSnapshot->kind);
            $this->assertSameRefusal(
                $inSnapshot,
                $this->wire->request('session.get', ['sessionId' => $otherId, 'limit' => 10]),
            );

            $inRespond = $this->refusalOf(fn () => $this->call($this->host->respondPermission($otherId, 'ask-1', 'once', null)));
            self::assertSame('ask_not_found', $inRespond->kind);
            $this->assertSameRefusal(
                $inRespond,
                $this->wire->request('permission.respond', ['sessionId' => $otherId, 'askId' => 'ask-1', 'reply' => 'once']),
            );

            self::assertSame([], $this->call($this->host->followedSessions()));
        } finally {
            $other->tearDown();
        }
    }

    public function testUnsubscribeAnswersTheWireShapeAndAnUnknownSessionIsNoRefusal(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->call($this->host->createSession(null, null))['id'];
        $this->wire->call('session.subscribe', ['sessionId' => $wireId]);
        $this->call($this->host->subscribeSession($inId, null, null));

        $wireAnswer = $this->wire->call('session.unsubscribe', ['sessionId' => $wireId]);
        $this->call($this->host->unsubscribeSession($inId));

        self::assertSame(['unsubscribed' => true], $wireAnswer);
        self::assertSame([], $this->call($this->host->followedSessions()));

        // The wire answers an unknown session with unsubscribed=false, not a refusal; so does the host.
        self::assertSame(['unsubscribed' => false], $this->wire->call('session.unsubscribe', ['sessionId' => 'no-such-session']));
        $this->call($this->host->unsubscribeSession('no-such-session'));
    }

    public function testExecCommandAnswersTheWireShapeAndTheUiOnlyRefusal(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->call($this->host->createSession(null, null))['id'];

        $wireClear = $this->wire->call('command.exec', ['sessionId' => $wireId, 'name' => 'clear']);
        $inClear = $this->call($this->host->execCommand($inId, 'clear', ''));
        self::assertSame(self::shape($wireClear), self::shape($inClear));
        self::assertSame($wireClear['effects'], $inClear['effects']);
        self::assertSame(['clear-transcript'], $inClear['effects']);

        $this->assertSameRefusal(
            $this->refusalOf(fn () => $this->call($this->host->execCommand($inId, 'theme', ''))),
            $this->wire->request('command.exec', ['sessionId' => $wireId, 'name' => 'theme']),
        );
    }

    public function testRespondPermissionAnswersTheWireShape(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $this->wire->call('session.subscribe', ['sessionId' => $wireId]);
        $this->fixture->run();
        $this->wire->call('session.send', ['sessionId' => $wireId, 'text' => 'check the log']);
        $this->fixture->run(0.08);

        // Two asks on the one running turn: the wire answers the first, the in-process host the second.
        $wireAsk = $this->fixture->backend->ask('wire-call', 'Bash', ['command' => 'ls']);
        $inAsk = $this->fixture->backend->ask('in-call', 'Bash', ['command' => 'ls']);
        $this->fixture->run(0.08);

        $wireAnswer = $this->wire->call('permission.respond', ['sessionId' => $wireId, 'askId' => $wireAsk->askId, 'reply' => 'once']);
        $inAnswer = $this->call($this->host->respondPermission($wireId, $inAsk->askId, 'once', null));

        self::assertTrue($wireAnswer['applied']);
        self::assertTrue($inAnswer['applied']);
        self::assertSame(self::shape($wireAnswer), self::shape($inAnswer));
    }

    /**
     * A negative resume point is refused by the handler, so a dropped afterSeq would answer the
     * snapshot path instead of refusing: the refusal shows the cursor reached the host.
     */
    public function testSubscribeSessionForwardsAfterSeqToTheHost(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->call($this->host->createSession(null, null))['id'];

        $wireRefusal = $this->wire->request('session.subscribe', ['sessionId' => $wireId, 'afterSeq' => -1]);
        $inRefusal = $this->refusalOf(fn () => $this->call($this->host->subscribeSession($inId, -1, null)));

        $this->assertSameRefusal($inRefusal, $wireRefusal);
        self::assertSame(ErrorCode::InvalidParams, $inRefusal->errorCode);
    }

    /**
     * A re-follow after an overflow notice passes the afterSeq the bundle first followed with (null)
     * and a cursor that reads the seq it has applied. The cursor is the resume point: the wire
     * replays from it, so the in-process door must replay too, not answer a snapshot reset.
     */
    public function testSubscribeSessionResumesFromTheCursorOnARefollow(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->call($this->host->createSession(null, null))['id'];

        $this->wire->call('session.send', ['sessionId' => $wireId, 'text' => 'tidy up', 'delivery' => 'queue', 'idempotencyKey' => 'k1']);
        $this->fixture->run(0.08);
        $this->fixture->backend->settle(Message::assistant('done'));
        $this->fixture->run(0.08);
        $this->call($this->host->sendTurn($inId, 'tidy up', 'queue', 'k2', [], []));
        $this->fixture->run(0.08);
        $this->fixture->backend->settle(Message::assistant('done'));
        $this->fixture->run(0.08);

        $wireResume = $this->wire->call('session.subscribe', ['sessionId' => $wireId, 'afterSeq' => 1]);
        $inResume = $this->call($this->host->subscribeSession($inId, null, static fn (): int => 1));

        self::assertArrayHasKey('fromSeq', $wireResume, 'the wire replays from the cursor');
        self::assertArrayNotHasKey('reset', $inResume, 'the cursor resumes the replay instead of a snapshot reset');
        self::assertSame(self::shape($wireResume), self::shape($inResume));
        self::assertSame($wireResume['fromSeq'], $inResume['fromSeq']);
    }

    /** The reserved `agent:` prefix and an unknown permission mode are refused by name, so both must reach the host. */
    public function testCreateSessionForwardsNameAndPermissionMode(): void
    {
        $this->connect();

        $wireName = $this->wire->request('session.create', ['name' => 'agent:probe']);
        $inName = $this->refusalOf(fn () => $this->call($this->host->createSession('agent:probe', null)));
        $this->assertSameRefusal($inName, $wireName);
        self::assertSame('reserved_session_name', $inName->kind);

        $wireMode = $this->wire->request('session.create', ['permissionMode' => 'nope']);
        $inMode = $this->refusalOf(fn () => $this->call($this->host->createSession(null, 'nope')));
        $this->assertSameRefusal($inMode, $wireMode);
    }

    /**
     * A /command takes no attachment, so the host refuses an image or document sent with one.
     * A dropped attachment would let /help through and answer it.
     */
    public function testSendTurnForwardsImagesAndDocuments(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->call($this->host->createSession(null, null))['id'];
        $png = ['name' => 'shot.png', 'mime' => 'image/png', 'data' => \base64_encode("\x89PNG\r\n\x1a\n" . \str_repeat("\0", 8))];
        $pdf = ['name' => 'spec.pdf', 'mime' => 'application/pdf', 'data' => \base64_encode('%PDF-1.4 ' . \str_repeat('x', 8))];

        $wireImage = $this->wire->request('session.send', ['sessionId' => $wireId, 'text' => '/help', 'delivery' => 'queue', 'images' => [$png]]);
        $inImage = $this->refusalOf(fn () => $this->call($this->host->sendTurn($inId, '/help', 'queue', null, [$png], [])));
        $this->assertSameRefusal($inImage, $wireImage);
        self::assertSame('images_with_command', $inImage->kind);

        $wireDocument = $this->wire->request('session.send', ['sessionId' => $wireId, 'text' => '/help', 'delivery' => 'queue', 'documents' => [$pdf]]);
        $inDocument = $this->refusalOf(fn () => $this->call($this->host->sendTurn($inId, '/help', 'queue', null, [], [$pdf])));
        $this->assertSameRefusal($inDocument, $wireDocument);
        self::assertSame('documents_with_command', $inDocument->kind);
    }

    /**
     * The note is capped at 2048 bytes and refused before the ask is looked up. A dropped note
     * would reach the lookup and refuse as an unknown ask instead.
     */
    public function testRespondPermissionForwardsTheNote(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->call($this->host->createSession(null, null))['id'];
        $tooLong = \str_repeat('n', 2049);

        $wireNote = $this->wire->request('permission.respond', ['sessionId' => $wireId, 'askId' => 'no-such-ask', 'reply' => 'once', 'note' => $tooLong]);
        $inNote = $this->refusalOf(fn () => $this->call($this->host->respondPermission($inId, 'no-such-ask', 'once', $tooLong)));

        $this->assertSameRefusal($inNote, $wireNote);
    }

    /**
     * `/rename` with a title renames the session; with none it opens the title editor. A dropped
     * args reaches the bare form, so the title never appears in the answer.
     */
    public function testExecCommandForwardsArgs(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->call($this->host->createSession(null, null))['id'];

        $wireNamed = $this->wire->call('command.exec', ['sessionId' => $wireId, 'name' => 'rename', 'args' => 'Refactor notes']);
        $inNamed = $this->call($this->host->execCommand($inId, 'rename', 'Refactor notes'));

        self::assertSame(self::shape($wireNamed), self::shape($inNamed));
        self::assertSame($wireNamed['effects'], $inNamed['effects']);
        self::assertContains('rename-session', $inNamed['effects']);
        self::assertStringContainsString('Refactor notes', \json_encode($inNamed, \JSON_THROW_ON_ERROR));
    }

    /**
     * Opening the in-process client starts the host's timers (the pump and the server tick),
     * with no socket client and no ServeStartup, and does so once: the start is idempotent.
     */
    public function testOpeningTheClientArmsTheHostTimersWithoutASocketOrStartup(): void
    {
        $probe = $this->probe = Dispatcher::new($this->fixture->context, Dispatcher::methods());
        $host = InProcessHostServices::new($probe, Loop::get());
        $timer = static fn (string $name): mixed => (new \ReflectionProperty(Dispatcher::class, $name))->getValue($probe);

        self::assertNull($timer('pumpTimer'), 'fixture: a dispatcher nobody started has armed no timers');
        self::assertNull($timer('tickTimer'), 'fixture: a dispatcher nobody started has armed no timers');

        $this->fixture->await($host->connect());
        $pump = $timer('pumpTimer');
        self::assertNotNull($pump, 'the pump timer is armed by opening the in-process client');
        self::assertNotNull($timer('tickTimer'), 'the server tick is armed by opening the in-process client');

        $probe->start();
        self::assertSame($pump, $timer('pumpTimer'), 'a second start keeps the timer it already armed');
        $this->call($host->disconnect());
    }

    /**
     * A subscriber still catching up hoards what it hears. Past the cap, the in-process listener
     * must hear the same `server.overflow` notice the wire sends, in order, not lose events silently.
     */
    public function testAnOverflowNoticeReachesAnInProcessListener(): void
    {
        $this->connect();
        $inId = $this->call($this->host->createSession(null, null))['id'];
        $heard = [];
        $this->call($this->host->subscribeEvents(function (EventEnvelope $envelope) use (&$heard): void {
            $heard[] = $envelope;
        }));
        $this->call($this->host->subscribeSession($inId, null, null));

        // Catch-up gets no loop tick, so everything heard meanwhile piles into the pre-live buffer.
        $session = $this->fixture->hub->get($inId);
        self::assertNotNull($session, 'fixture: the in-process session has a live host');
        $cap = (new \ReflectionClass(SessionFeed::class))->getConstant('MAX_PRELIVE_BUFFER');
        for ($i = 0; $i < $cap + 5; ++$i) {
            $session->announce(SessionEvent::new(SessionEvent::TOOL_PROGRESS, ['name' => 'Read', 'callId' => 'c' . $i], $inId, null, 1_700_000_000_000 + $i));
        }
        $this->fixture->run();

        $overflow = \array_values(\array_filter($heard, static fn (EventEnvelope $e): bool => $e->type === EventType::SERVER_OVERFLOW));
        self::assertCount(1, $overflow, 'the stale notice reaches the in-process listener exactly once');
        self::assertSame(['sessionId' => $inId, 'dropped' => $cap + 1, 'action' => 'resubscribe'], $overflow[0]->data);
        $progress = \array_filter($heard, static fn (EventEnvelope $e): bool => $e->type === 'tool.progress');
        self::assertSame([], $progress, 'the hoard is discarded, not delivered late');
    }

    /** An in-process client has no socket outbox: its events go through emit(), so asking for one is a programming error. */
    public function testAnInProcessClientHasNoOutbox(): void
    {
        $client = new Client(InProcessPeer::new(), null, Scope::owner(), static fn (): float => 0.0, static function (): void {});

        self::assertTrue($client->isInProcess());
        $this->expectException(\LogicException::class);
        $client->outbox();
    }

    /** A client is a socket (an outbox) or in-process (a deliver closure), never neither. */
    public function testAClientWithNeitherAnOutboxNorADeliverClosureIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client(InProcessPeer::new(), null, Scope::owner(), static fn (): float => 0.0, null);
    }

    private function connect(): void
    {
        $this->fixture->await($this->host->connect());
        self::assertTrue($this->call($this->host->isConnected()));
    }

    /** A dispatcher over the fixture's context whose roster adds one read method, `probe.raw`, answering $handler. */
    private function probeDispatcher(\Closure $handler): Dispatcher
    {
        $methods = Dispatcher::methods();
        $methods->add(MethodSpec::new('probe.raw', Scope::Read, 'Test probe.', $handler));

        return $this->probe = Dispatcher::new($this->fixture->context, $methods);
    }

    /** Calls `probe.raw` through the in-process door of $dispatcher, after the hello the door needs. */
    private function probeInProcess(Dispatcher $dispatcher): mixed
    {
        $client = new Client(InProcessPeer::new(), null, Scope::owner(), $this->fixture->context->clock(), static function (): void {
        });
        $this->fixture->context->addClient($client);
        $dispatcher->invoke($client, ServerMethods::HELLO, [
            'minProtocol' => Dispatcher::PROTOCOL,
            'maxProtocol' => Dispatcher::PROTOCOL,
            'client' => ['name' => 'candy-code.in-process', 'version' => $this->fixture->context->version()],
            'caps' => [],
        ]);

        return $dispatcher->invoke($client, 'probe.raw', []);
    }

    /** A list nested $levels arrays deep around the string `x`. */
    private static function nested(int $levels): array
    {
        $value = ['x'];
        for ($i = 1; $i < $levels; $i++) {
            $value = [$value];
        }

        return $value;
    }

    /**
     * The settled answer of an in-process call: its value, or the refusal it rejected with, thrown
     * the way the synchronous call threw it.
     */
    private function call(PromiseInterface $promise): mixed
    {
        $value = $this->fixture->await($promise);
        if ($value instanceof \Throwable) {
            throw $value;
        }

        return $value;
    }

    private function refusalOf(\Closure $call): RpcError
    {
        try {
            $call();
        } catch (RpcError $e) {
            return $e;
        }
        self::fail('the in-process call answered where a refusal was expected');
    }

    /**
     * The wire's error object must carry the refusal's code and kind, and the same data: the
     * in-process RpcError's toArray() is the wire's `data`, so `errors` (invalid_params) and
     * the retryable flag are compared too, not only the kind.
     */
    private function assertSameRefusal(RpcError $inProcess, array $wireResponse): void
    {
        self::assertArrayHasKey('error', $wireResponse, 'the wire answered where in-process refused: ' . $inProcess->getMessage());
        self::assertSame($inProcess->errorCode->value, $wireResponse['error']['code']);
        self::assertSame($inProcess->kind, $wireResponse['error']['data']['kind'] ?? null);
        self::assertSame($inProcess->toArray()['data'], $wireResponse['error']['data'], 'the refusal data matches the wire');
    }

    /** Results are compared by the keys and value types they carry, never by value. */
    private static function shape(mixed $value): mixed
    {
        // JSON cannot tell an empty object from an empty list: the wire decodes `{}` to stdClass.
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!\is_array($value)) {
            return \get_debug_type($value);
        }

        return \array_map(self::shape(...), $value);
    }
}
