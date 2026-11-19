<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Host\InProcessHostServices;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\RpcError;
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
        $this->host->disconnect();
        $this->fixture->tearDown();
    }

    public function testHelloAnswersTheWireShapeAndOpensTheClient(): void
    {
        $wireHello = WireClient::open($this->fixture->dispatcher, 'wire-hello')->hello();

        $hello = $this->host->hello();

        self::assertSame(self::shape($wireHello), self::shape($hello));
        self::assertTrue($this->host->isConnected());
    }

    public function testConnectIsIdempotentAndASecondHelloIsTheWireConflict(): void
    {
        self::assertNull($this->fixture->await($this->host->connect()));
        self::assertNull($this->fixture->await($this->host->connect()));

        $wireAgain = $this->wire->request('server.hello', ['minProtocol' => 1, 'maxProtocol' => 1, 'client' => ['name' => 'test', 'version' => '0']]);
        $inAgain = $this->refusalOf(fn () => $this->host->hello());

        $this->assertSameRefusal($inAgain, $wireAgain);
        self::assertSame('already_initialized', $inAgain->kind);
    }

    public function testCallsBeforeConnectAreTheWireNotInitializedRefusal(): void
    {
        $wireId = $this->wire->call('session.create', [])['id'];
        $unhelloed = WireClient::open($this->fixture->dispatcher, 'unhello');

        $wireSend = $unhelloed->request('session.send', ['sessionId' => $wireId, 'text' => 'hi', 'delivery' => 'queue']);
        $inSend = $this->refusalOf(fn () => $this->host->sendTurn($wireId, 'hi', 'queue', null, [], []));

        $this->assertSameRefusal($inSend, $wireSend);
        self::assertSame(ErrorCode::NotInitialized, $inSend->errorCode);
    }

    public function testCreateAndSubscribeAnswerTheWireShape(): void
    {
        $this->connect();

        $wireSession = $this->wire->call('session.create', []);
        $inSession = $this->host->createSession(null, null);
        self::assertSame(self::shape($wireSession), self::shape($inSession));

        $wireSub = $this->wire->call('session.subscribe', ['sessionId' => $wireSession['id'], 'snapshotLimit' => InProcessHostServices::SNAPSHOT_LIMIT]);
        $inSub = $this->host->subscribeSession($inSession['id'], null, null);
        self::assertSame(self::shape($wireSub), self::shape($inSub));
    }

    public function testATurnAnswersTheWireShapeAndTheEventsMatchInOrder(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->host->createSession(null, null)['id'];

        $heard = [];
        $this->host->subscribeEvents(function (EventEnvelope $envelope) use (&$heard, $inId): void {
            if ($envelope->sessionId === $inId) {
                $heard[] = $envelope;
            }
        });
        $this->wire->call('session.subscribe', ['sessionId' => $wireId]);
        $this->host->subscribeSession($inId, null, null);
        $this->fixture->run();

        // One turn runs at a time (server.maxConcurrentTurns), so each door's turn
        // is settled before the other starts.
        $wireSend = $this->wire->call('session.send', ['sessionId' => $wireId, 'text' => 'tidy up', 'delivery' => 'queue', 'idempotencyKey' => 'k1']);
        $this->fixture->run(0.08);
        $this->fixture->backend->settle(Message::assistant('done'));
        $this->fixture->run(0.08);

        // The owner bucket is shared by every owner door, so the in-process turn needs its own key.
        $inSend = $this->host->sendTurn($inId, 'tidy up', 'queue', 'k2', [], []);
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
        $inId = $this->host->createSession(null, null)['id'];
        $this->host->subscribeSession($inId, null, null);
        $this->host->subscribeEvents(static function (): void {
            throw new \RuntimeException('listener broke');
        });
        $heard = 0;
        $this->host->subscribeEvents(function (EventEnvelope $envelope) use (&$heard, $inId): void {
            if ($envelope->sessionId === $inId) {
                $heard++;
            }
        });

        $this->host->sendTurn($inId, 'hello', 'queue', null, [], []);
        $this->fixture->run(0.08);

        self::assertGreaterThan(0, $heard);
        self::assertStringContainsString('in-process event listener failed', \implode("\n", $this->logs));
    }

    public function testDisconnectDeliversWhatIsOwedAndLeavesTheHost(): void
    {
        $this->connect();
        $inId = $this->host->createSession(null, null)['id'];
        $heard = [];
        $this->host->subscribeEvents(function (EventEnvelope $envelope) use (&$heard): void {
            $heard[] = $envelope;
        });
        $this->host->subscribeSession($inId, null, null);
        $this->host->sendTurn($inId, 'hello', 'queue', null, [], []);

        $this->host->disconnect();

        self::assertNotSame([], $heard, 'events emitted before disconnect are not dropped');
        self::assertFalse($this->host->isConnected());
        self::assertSame([], $this->host->followedSessions());
    }

    public function testFollowedSessionsTracksSubscribeAndUnsubscribe(): void
    {
        $this->connect();
        $inId = $this->host->createSession(null, null)['id'];

        $this->host->subscribeSession($inId, null, null);
        self::assertSame([$inId], $this->host->followedSessions());

        $this->host->unsubscribeSession($inId);
        self::assertSame([], $this->host->followedSessions());
    }

    public function testRefusalsAreTheWireRefusals(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];

        $this->assertSameRefusal(
            $this->refusalOf(fn () => $this->host->sendTurn($wireId, 'hi', 'nope', null, [], [])),
            $this->wire->request('session.send', ['sessionId' => $wireId, 'text' => 'hi', 'delivery' => 'nope']),
        );
        $this->assertSameRefusal(
            $this->refusalOf(fn () => $this->host->sessionSnapshot('no-such-session', 10)),
            $this->wire->request('session.get', ['sessionId' => 'no-such-session', 'limit' => 10]),
        );
        $this->assertSameRefusal(
            $this->refusalOf(fn () => $this->host->respondPermission($wireId, 'no-such-ask', 'once', null)),
            $this->wire->request('permission.respond', ['sessionId' => $wireId, 'askId' => 'no-such-ask', 'reply' => 'once']),
        );
        $this->assertSameRefusal(
            $this->refusalOf(fn () => $this->host->execCommand($wireId, 'no-such-command', '')),
            $this->wire->request('command.exec', ['sessionId' => $wireId, 'name' => 'no-such-command']),
        );
    }

    public function testReadsAnswerTheWireShape(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->host->createSession(null, null)['id'];

        self::assertSame(
            self::shape($this->wire->call('session.get', ['sessionId' => $wireId, 'limit' => 10])),
            self::shape($this->host->sessionSnapshot($inId, 10)),
        );
        self::assertSame(
            self::shape($this->wire->call('permission.pending', [])),
            self::shape($this->host->pendingPermissions()),
        );
        self::assertSame(
            self::shape($this->wire->call('session.cancel', ['sessionId' => $wireId, 'mode' => 'hard'])),
            self::shape($this->host->cancelSession($inId, 'hard')),
        );
    }

    public function testFollowResultsReachTheListenerWithEachSubscribeAnswer(): void
    {
        $this->connect();
        $inId = $this->host->createSession(null, null)['id'];
        $results = [];
        $this->host->subscribeFollowResults(function (array $result) use (&$results): void {
            $results[] = $result;
        });

        $answer = $this->host->subscribeSession($inId, null, null);

        self::assertSame([$answer], $results, 'the follow result is the subscribe answer, delivered to the listener');
    }

    public function testAThrowingFollowResultListenerIsLoggedAndTheOthersStillHear(): void
    {
        $this->connect();
        $inId = $this->host->createSession(null, null)['id'];
        $this->host->subscribeFollowResults(static function (): void {
            throw new \RuntimeException('follow listener broke');
        });
        $heard = 0;
        $this->host->subscribeFollowResults(function () use (&$heard): void {
            $heard++;
        });

        $answer = $this->host->subscribeSession($inId, null, null);

        self::assertSame(1, $heard);
        self::assertIsArray($answer);
        self::assertStringContainsString('in-process follow-result listener failed', \implode("\n", $this->logs));
    }

    public function testADisconnectFromAListenerDoesNotReorderWhatIsStillOwed(): void
    {
        $this->connect();
        $inId = $this->host->createSession(null, null)['id'];
        $client = (new \ReflectionProperty(InProcessHostServices::class, 'client'))->getValue($this->host);
        // session.created is queued by createSession(); flush it before the listeners attach.
        $this->fixture->run();
        $first = [];
        $second = [];
        $this->host->subscribeEvents(function (EventEnvelope $envelope) use (&$first): void {
            $first[] = $envelope->type;
            if ($envelope->type === 'first') {
                $this->host->disconnect();
            }
        });
        $this->host->subscribeEvents(function (EventEnvelope $envelope) use (&$second): void {
            $second[] = $envelope->type;
        });

        // Two events emitted in one synchronous run: the first listener's disconnect must not
        // let the second event reach the listeners ahead of the first.
        $client->emit(EventEnvelope::ephemeral($inId, 'first', []));
        $client->emit(EventEnvelope::ephemeral($inId, 'second', []));
        $this->fixture->run(0.08);

        self::assertSame(['first', 'second'], $first);
        self::assertSame(['first', 'second'], $second, 'every listener hears the owed events in emit order');
        self::assertFalse($this->host->isConnected());
    }

    public function testUnsubscribeAnswersTheWireShapeAndAnUnknownSessionIsNoRefusal(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->host->createSession(null, null)['id'];
        $this->wire->call('session.subscribe', ['sessionId' => $wireId]);
        $this->host->subscribeSession($inId, null, null);

        $wireAnswer = $this->wire->call('session.unsubscribe', ['sessionId' => $wireId]);
        $this->host->unsubscribeSession($inId);

        self::assertSame(['unsubscribed' => true], $wireAnswer);
        self::assertSame([], $this->host->followedSessions());

        // The wire answers an unknown session with unsubscribed=false, not a refusal; so does the host.
        self::assertSame(['unsubscribed' => false], $this->wire->call('session.unsubscribe', ['sessionId' => 'no-such-session']));
        $this->host->unsubscribeSession('no-such-session');
    }

    public function testExecCommandAnswersTheWireShapeAndTheUiOnlyRefusal(): void
    {
        $this->connect();
        $wireId = $this->wire->call('session.create', [])['id'];
        $inId = $this->host->createSession(null, null)['id'];

        $wireClear = $this->wire->call('command.exec', ['sessionId' => $wireId, 'name' => 'clear']);
        $inClear = $this->host->execCommand($inId, 'clear', '');
        self::assertSame(self::shape($wireClear), self::shape($inClear));
        self::assertSame($wireClear['effects'], $inClear['effects']);
        self::assertSame(['clear-transcript'], $inClear['effects']);

        $this->assertSameRefusal(
            $this->refusalOf(fn () => $this->host->execCommand($inId, 'theme', '')),
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
        $inAnswer = $this->host->respondPermission($wireId, $inAsk->askId, 'once', null);

        self::assertTrue($wireAnswer['applied']);
        self::assertTrue($inAnswer['applied']);
        self::assertSame(self::shape($wireAnswer), self::shape($inAnswer));
    }

    private function connect(): void
    {
        $this->fixture->await($this->host->connect());
        self::assertTrue($this->host->isConnected());
    }

    /** @param \Closure(): mixed $call */
    private function refusalOf(\Closure $call): RpcError
    {
        try {
            $call();
        } catch (RpcError $e) {
            return $e;
        }
        self::fail('the in-process call answered where a refusal was expected');
    }

    /** The wire's error object must carry the refusal's code and kind. */
    private function assertSameRefusal(RpcError $inProcess, array $wireResponse): void
    {
        self::assertArrayHasKey('error', $wireResponse, 'the wire answered where in-process refused: ' . $inProcess->getMessage());
        self::assertSame($inProcess->errorCode->value, $wireResponse['error']['code']);
        self::assertSame($inProcess->kind, $wireResponse['error']['data']['kind'] ?? null);
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
