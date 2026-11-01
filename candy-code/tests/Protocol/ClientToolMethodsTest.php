<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\PendingClientCall;
use SugarCraft\Code\Host\ClientTools\ClientToolProxy;
use SugarCraft\Code\Host\ClientTools\ClientToolRegistry;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\Schema\EventSchemas;
use SugarCraft\Code\Protocol\Schema\ProtocolSchema;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * `tools.client.*` on the wire (C-TOOL-6.i): the door, the registration, and
 * the round trip of one call.
 *
 * The ENGINE side of this machinery — registry, proxy, pending calls,
 * first-answer-wins, disconnect failure — is pinned by round C-TOOL-6.h inside
 * SessionHostTest. These tests ride the protocol surface added on top of it:
 * that `server.clientTools` keeps the whole family shut until the operator
 * opens it, that registration speaks in proxy names and mints per-connection
 * ids, that a call announces `tool.client.call` over the session's feed with
 * the registration that owns it, and that answers arrive as
 * `tools.client.result` with the settled-once semantics visible from the
 * client's side of the socket.
 *
 * The scope leg pins the MethodSpec the way the house does (the roster is the
 * refusal: {@see \SugarCraft\Code\Protocol\CallContext::requireScope} runs it
 * for every dispatch): a Read-only client cannot reach a Write-scoped method,
 * and these two are Write-scoped because registering tools hands the model
 * code that runs inside the client.
 */
final class ClientToolMethodsTest extends TestCase
{
    private const BROWSE_ANNOUNCEMENT = [
        'name' => 'browse',
        'description' => 'opens a page in the user browser',
        'inputSchema' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string']]],
        'timeoutSeconds' => 60,
    ];

    private ?ProtocolFixture $closedDoorFixture = null;

    protected function tearDown(): void
    {
        $this->closedDoorFixture?->tearDown();
        $this->closedDoorFixture = null;
    }

    public function testTheTwoMethodsAreOnTheRosterWriteScopedAndClaimSideEffects(): void
    {
        $registry = Dispatcher::methods();
        foreach (['tools.client.register', 'tools.client.result'] as $name) {
            $spec = $registry->get($name);
            self::assertInstanceOf(MethodSpec::class, $spec, $name . ' is on the roster');
            self::assertSame(Scope::Write, $spec->scope, $name . ' changes what the server can call into the client, so a Read client is refused by the dispatcher');
            self::assertTrue($spec->sideEffects, $name . ' claims a side effect, which is what makes the idempotency key honoured for it');
        }
    }

    public function testTheDoorStaysShutUntilTheSettingOpens(): void
    {
        // The default ServerConfig has clientTools = false — the shape every
        // un-configured serve launches with.
        $fixture = $this->closedDoorFixture = ProtocolFixture::new();
        $client = $fixture->client('c1');
        $sessionId = $fixture->session($client);

        foreach (
            [
                ['tools.client.register', ['sessionId' => $sessionId, 'tools' => [self::BROWSE_ANNOUNCEMENT]]],
                ['tools.client.result', ['sessionId' => $sessionId, 'callId' => \str_repeat('a', 16), 'registrationId' => \str_repeat('b', 16), 'content' => 'x']],
            ] as [$method, $params]
        ) {
            $reply = $client->request($method, $params);
            self::assertSame(ErrorCode::Forbidden->value, $reply['error']['code'] ?? null, $method . ' is refused while the setting is off');
            self::assertSame('client_tools_disabled', $reply['error']['data']['kind'] ?? null, 'the one kind names the door, not the caller');
        }

        // Nothing leaked behind the door: the session's host holds no proxy.
        self::assertSame([], self::c6iRosterNames($fixture, $sessionId));
    }

    public function testRegistrationAnswersProxyNamesAndTakesEffectOnTheNextTurn(): void
    {
        $fixture = ProtocolFixture::new(self::c6iOpenDoor());
        $client = $fixture->client('c1');
        $sessionId = $fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);

        $result = $client->call('tools.client.register', ['sessionId' => $sessionId, 'tools' => [self::BROWSE_ANNOUNCEMENT]]);
        self::assertIsString($result['registrationId'] ?? null);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', (string) $result['registrationId']);
        self::assertSame(['client__browse'], $result['names'], 'the client is told the exact name the model will use');
        self::assertSame('next_turn', $result['effective'], 'turn rosters freeze when the turn is built');

        // The registration reached the session's registry — which is what the
        // NEXT turn's EngineBackend append reads (the append itself is pinned
        // in SessionHostTest, C-TOOL-6.h).
        self::assertSame(['client__browse'], self::c6iRosterNames($fixture, $sessionId));
    }

    public function testReRegistrationMintsAFreshIdAndAnEmptySetWithdrawsEverything(): void
    {
        $fixture = ProtocolFixture::new(self::c6iOpenDoor());
        $client = $fixture->client('c1');
        $sessionId = $fixture->session($client);

        $first = (string) $client->call('tools.client.register', ['sessionId' => $sessionId, 'tools' => [self::BROWSE_ANNOUNCEMENT]])['registrationId'];
        $second = (string) $client->call('tools.client.register', ['sessionId' => $sessionId, 'tools' => [self::BROWSE_ANNOUNCEMENT]])['registrationId'];
        self::assertNotSame($first, $second, 'each registration is its own id');

        $withdrawn = $client->call('tools.client.register', ['sessionId' => $sessionId, 'tools' => []]);
        self::assertSame([], $withdrawn['names'], 'the empty list still registers — it withdraws the set');
        self::assertNotSame($second, (string) $withdrawn['registrationId']);
        self::assertSame([], self::c6iRosterNames($fixture, $sessionId));
    }

    public function testMalformedAnnouncementsRefuseAsInvalidParams(): void
    {
        $fixture = ProtocolFixture::new(self::c6iOpenDoor());
        $client = $fixture->client('c1');
        $sessionId = $fixture->session($client);

        $cases = [
            'not a list' => ['sessionId' => $sessionId, 'tools' => ['name' => 'browse']],
            'entry not an object' => ['sessionId' => $sessionId, 'tools' => ['browse']],
            'bad name' => ['sessionId' => $sessionId, 'tools' => [['name' => 'Browse!', 'description' => 'd', 'inputSchema' => ['type' => 'object']]]],
            'schema without object type' => ['sessionId' => $sessionId, 'tools' => [['name' => 'browse', 'description' => 'd', 'inputSchema' => ['type' => 'string']]]],
            'timeout out of bounds' => ['sessionId' => $sessionId, 'tools' => [['name' => 'browse', 'description' => 'd', 'inputSchema' => ['type' => 'object'], 'timeoutSeconds' => 9999]]],
        ];
        foreach ($cases as $label => $params) {
            $reply = $client->request('tools.client.register', $params);
            self::assertSame(ErrorCode::InvalidParams->value, $reply['error']['code'] ?? null, $label . ' is the caller\'s bad announcement, said as invalid params');
        }
        self::assertSame([], self::c6iRosterNames($fixture, $sessionId), 'a refused announcement leaves nothing behind');
    }

    public function testTheCallEventReachesSubscribersOfItsSessionWithItsRegistration(): void
    {
        $fixture = ProtocolFixture::new(self::c6iOpenDoor());
        $owner = $fixture->client('c1');
        $watcher = $fixture->client('c2');
        $other = $fixture->client('c3');
        $sessionId = $fixture->session($owner);
        $otherSession = $fixture->session($other);
        $owner->call('session.subscribe', ['sessionId' => $sessionId]);
        $watcher->call('session.subscribe', ['sessionId' => $sessionId]);
        $other->call('session.subscribe', ['sessionId' => $otherSession]);

        $registrationId = (string) $owner->call('tools.client.register', ['sessionId' => $sessionId, 'tools' => [self::BROWSE_ANNOUNCEMENT]])['registrationId'];
        $owner->clear();
        $watcher->clear();
        $other->clear();

        // The engine reached the tool: open the call the way the proxy's
        // caller does (same reflection seam C-TOOL-6.h pinned the sink with).
        $settled = [];
        $pending = new PendingClientCall(
            PendingClientCall::callId('call_1', 'client__browse', ['url' => 'page']),
            'call_1',
            'client__browse',
            ['url' => 'page'],
            60,
            static function (PendingClientCall $p) use (&$settled): void {
                $settled[] = [$p->content(), $p->isError()];
            },
        );
        self::c6iSink($fixture, $sessionId)($pending, static fn (string $content, bool $isError): bool => $pending->resolve($content, $isError));
        $fixture->run();

        foreach (['the registering subscriber' => $owner, 'a second subscriber' => $watcher] as $label => $listener) {
            $events = $listener->events(SessionEvent::TOOL_CLIENT_CALL);
            self::assertCount(1, $events, $label . ' hears the call over its subscription');
            $data = (array) $events[0]['data'];
            self::assertSame($pending->callId, $data['callId']);
            self::assertSame($registrationId, $data['registrationId'], 'the event names the registration that owns the name');
            self::assertSame('client__browse', $data['name']);
            self::assertSame(['url' => 'page'], $data['arguments']);
            self::assertGreaterThan((int) \floor(\microtime(true) * 1000) - 5_000, (int) $data['deadlineAt'], 'the deadline is now plus the spec timeout, in ms');
            self::assertSame([], ProtocolSchema::errors($data, EventSchemas::data(SessionEvent::TOOL_CLIENT_CALL)), 'the announced payload fits the event schema');
        }
        self::assertSame([], $other->events(SessionEvent::TOOL_CLIENT_CALL), 'a subscriber of another session never hears it');
        self::assertSame([], $settled, 'the call stays open until an answer settles it');
    }

    public function testTheFirstWireAnswerWinsAndStaleForeignOrUnknownAnswersReadFalse(): void
    {
        $fixture = ProtocolFixture::new(self::c6iOpenDoor());
        $owner = $fixture->client('c1');
        $sessionId = $fixture->session($owner);
        $owner->call('session.subscribe', ['sessionId' => $sessionId]);
        $registrationId = (string) $owner->call('tools.client.register', ['sessionId' => $sessionId, 'tools' => [self::BROWSE_ANNOUNCEMENT]])['registrationId'];

        $settled = [];
        $pending = new PendingClientCall(
            PendingClientCall::callId('call_1', 'client__browse', []),
            'call_1',
            'client__browse',
            [],
            60,
            static function (PendingClientCall $p) use (&$settled): void {
                $settled[] = [$p->content(), $p->isError()];
            },
        );
        self::c6iSink($fixture, $sessionId)($pending, static fn (string $content, bool $isError): bool => $pending->resolve($content, $isError));
        $fixture->run();

        $answer = static fn (string $registration, string $content, bool $isError = false): array => [
            'sessionId' => $sessionId,
            'callId' => $pending->callId,
            'registrationId' => $registration,
            'content' => $content,
            'isError' => $isError,
        ];

        self::assertSame(['accepted' => false], $owner->call('tools.client.result', $answer(\str_repeat('0', 16), 'nope')), 'a foreign id cannot spend the call');
        $fresh = (string) $owner->call('tools.client.register', ['sessionId' => $sessionId, 'tools' => [self::BROWSE_ANNOUNCEMENT]])['registrationId'];
        self::assertSame(['accepted' => false], $owner->call('tools.client.result', $answer($fresh, 'late')), 're-registering does not retro-earn the old call');
        self::assertSame([], $settled, 'a refused answer settles nothing');

        self::assertSame(['accepted' => true], $owner->call('tools.client.result', $answer($registrationId, 'first')));
        self::assertSame([['first', false]], $settled, 'the id the call was announced under settles the tool');
        self::assertSame(['accepted' => false], $owner->call('tools.client.result', $answer($registrationId, 'second')), 'first answer wins, said as accepted:false and not as an error');
        self::assertSame([['first', false]], $settled);

        $stranger = new PendingClientCall(
            PendingClientCall::callId('call_7', 'client__browse', []),
            'call_7',
            'client__browse',
            [],
            60,
            static function (): void {
            },
        );
        self::assertSame(['accepted' => false], $owner->call('tools.client.result', $answer($registrationId, 'x') + ['callId' => $stranger->callId]), 'an unknown callId reads the same false');
    }

    public function testAnOversizedAnswerBodyIsRefusedAsInvalidParams(): void
    {
        $fixture = ProtocolFixture::new(self::c6iOpenDoor());
        $owner = $fixture->client('c1');
        $sessionId = $fixture->session($owner);
        $registrationId = (string) $owner->call('tools.client.register', ['sessionId' => $sessionId, 'tools' => [self::BROWSE_ANNOUNCEMENT]])['registrationId'];

        $reply = $owner->request('tools.client.result', [
            'sessionId' => $sessionId,
            'callId' => \str_repeat('c', 16),
            'registrationId' => $registrationId,
            'content' => \str_repeat('x', 262_145),
        ]);
        self::assertSame(ErrorCode::InvalidParams->value, $reply['error']['code'] ?? null, 'the 256 KiB bound is the params guard\'s');
    }

    public function testADisconnectedClientFailsItsOpenCallsAtOnce(): void
    {
        $fixture = ProtocolFixture::new(self::c6iOpenDoor());
        $owner = $fixture->client('c1');
        $watcher = $fixture->client('c2');
        $sessionId = $fixture->session($owner);
        $watcher->call('session.subscribe', ['sessionId' => $sessionId]);
        $registrationId = (string) $owner->call('tools.client.register', ['sessionId' => $sessionId, 'tools' => [self::BROWSE_ANNOUNCEMENT]])['registrationId'];

        $settled = [];
        $pending = new PendingClientCall(
            PendingClientCall::callId('call_9', 'client__browse', []),
            'call_9',
            'client__browse',
            [],
            60,
            static function (PendingClientCall $p) use (&$settled): void {
                $settled[] = [$p->content(), $p->isError()];
            },
        );
        self::c6iSink($fixture, $sessionId)($pending, static fn (string $content, bool $isError): bool => $pending->resolve($content, $isError));
        self::assertSame([], $settled);

        $owner->close();

        self::assertSame([['the client that hosts this tool is gone', true]], $settled, 'the socket dying answers the open call immediately — no deadline wait');
        self::assertSame([], self::c6iRosterNames($fixture, $sessionId), 'the registration died with the connection');
        self::assertSame(
            ['accepted' => false],
            $watcher->call('tools.client.result', ['sessionId' => $sessionId, 'callId' => $pending->callId, 'registrationId' => $registrationId, 'content' => 'too late']),
            'a belated answer from anyone reads accepted:false',
        );
    }

    /** The launch shape a serve has when the operator opened the door. */
    private static function c6iOpenDoor(): ServerConfig
    {
        // The fixture applies its own root; the state dir here only needs to
        // exist long enough for ServerConfig to accept it.
        return ServerConfig::new((string) \sys_get_temp_dir())->withClientTools(true);
    }

    /**
     * The host's relay seam, opened the way C-TOOL-6.h's own tests open it:
     * the proxy's caller closure the engine would hand a pending call.
     */
    private static function c6iSink(ProtocolFixture $fixture, string $sessionId): \Closure
    {
        $host = $fixture->hub->get($sessionId);
        self::assertInstanceOf(SessionHost::class, $host);

        return (new \ReflectionMethod(SessionHost::class, 'clientCallSink'))->invoke($host);
    }

    /**
     * The proxy names currently registered on the session, read through the
     * host's registry exactly as the next turn's EngineBackend append would.
     *
     * @return list<string>
     */
    private static function c6iRosterNames(ProtocolFixture $fixture, string $sessionId): array
    {
        $host = $fixture->hub->get($sessionId);
        self::assertInstanceOf(SessionHost::class, $host);
        $registry = (new \ReflectionProperty(SessionHost::class, 'clientTools'))->getValue($host);
        self::assertInstanceOf(ClientToolRegistry::class, $registry);

        return \array_map(static fn (ClientToolProxy $proxy): string => $proxy->name(), $registry->proxies());
    }
}
