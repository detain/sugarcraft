<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Events\PermissionResolved;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\Schema\Definitions;
use SugarCraft\Code\Protocol\Schema\EventSchemas;
use SugarCraft\Code\Protocol\Schema\ProtocolSchema;
use SugarCraft\Code\Protocol\Schema\Schema;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * C-WEB-7.d: AskUser's own choices reach every ask surface typed — the host
 * `permission.requested` event, its server-scope `permission.asked`
 * rebroadcast, the `permission.pending` items and the snapshot `pendingAsks` —
 * each equal to the options and each fitting its schema. The typed accessor is
 * the single source, so a hook's ask ABOUT an AskUser call stays a permission
 * question (no `choices` key at all), exactly like the gate's ask on `Bash`:
 * every other ask's payload stays byte-identical to the old wire.
 *
 * The questions ride {@see ScriptedTurnBackend::emit()} — a public door —
 * through a real subscribed client, so projector, feed, envelope and replay
 * are all the production path; the fixture itself is not edited.
 */
final class AskUserChoicesWireTest extends TestCase
{
    /** @var list<string> */
    private const OPTIONS = ['Blue', 'Green', 'Red'];

    private ProtocolFixture $fixture;

    private WireClient $client;

    private string $sessionId = '';

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
        $this->client = $this->fixture->client();
        $this->sessionId = $this->fixture->session($this->client);
        $this->client->call('session.subscribe', ['sessionId' => $this->sessionId]);
        $this->fixture->run();
        // A running turn is what carries a backend event to the host; the
        // scripted backend keeps its channel open until a test settles it.
        $this->client->call('session.send', ['sessionId' => $this->sessionId, 'text' => 'pick a color']);
        $this->fixture->run();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testAnAskUserQuestionCarriesItsChoicesAcrossEveryAskSurface(): void
    {
        $ask = $this->putAskOnTheWire('call-1', 'AskUser', 'tool:AskUser', ['question' => 'Favorite color?', 'options' => self::OPTIONS]);
        $this->fixture->run(0.08);

        $askSchema = Schema::ref(Definitions::PENDING_ASK);

        // The session-scoped event, straight off the projector's askData arm.
        $requested = $this->client->events('permission.requested');
        self::assertCount(1, $requested);
        self::assertSame($ask->askId, $requested[0]['data']['askId']);
        self::assertSame(self::OPTIONS, $requested[0]['data']['choices']);
        $requestedSchema = EventSchemas::data(SessionEvent::PERMISSION_REQUESTED);
        self::assertNotNull($requestedSchema);
        self::assertSame([], ProtocolSchema::errors($requested[0]['data'], $requestedSchema), 'permission.requested fits');

        // The server-scope rebroadcast ServerContext::heard() makes of it.
        $asked = $this->client->events('permission.asked');
        self::assertCount(1, $asked);
        self::assertSame($this->sessionId, $asked[0]['data']['sessionId']);
        self::assertSame($ask->askId, $asked[0]['data']['askId']);
        self::assertSame(self::OPTIONS, $asked[0]['data']['choices']);
        $askedSchema = EventSchemas::data(EventType::PERMISSION_ASKED);
        self::assertNotNull($askedSchema);
        self::assertSame([], ProtocolSchema::errors($asked[0]['data'], $askedSchema), 'permission.asked fits');

        // permission.pending and the snapshot ride SessionFeed::askSummary().
        $pending = $this->client->call('permission.pending', ['sessionId' => $this->sessionId]);
        $items = \array_values(\array_filter($pending['items'], static fn (array $row): bool => $row['askId'] === $ask->askId));
        self::assertCount(1, $items);
        self::assertSame(self::OPTIONS, $items[0]['choices']);
        self::assertSame([], ProtocolSchema::errors($items[0], $askSchema), 'the pending item fits');

        $snapshot = $this->client->call('session.get', ['sessionId' => $this->sessionId]);
        $rows = \array_values(\array_filter($snapshot['pendingAsks'], static fn (array $row): bool => $row['askId'] === $ask->askId));
        self::assertCount(1, $rows);
        self::assertSame(self::OPTIONS, $rows[0]['choices']);
        self::assertSame([], ProtocolSchema::errors($rows[0], $askSchema), 'the snapshot row fits');
    }

    public function testAHooksAskAboutAnAskUserCallCarriesNoChoicesKeyAnywhere(): void
    {
        $ask = $this->putAskOnTheWire('call-2', 'AskUser', 'hook:PreToolUse', ['question' => 'Favorite color?', 'options' => self::OPTIONS]);
        $this->fixture->run(0.08);

        $requested = $this->client->events('permission.requested');
        self::assertCount(1, $requested);
        self::assertSame($ask->askId, $requested[0]['data']['askId']);
        self::assertArrayNotHasKey('choices', $requested[0]['data'], 'a hook question about the call stays a permission question');
        foreach ($this->client->events('permission.asked') as $asked) {
            self::assertArrayNotHasKey('choices', $asked['data']);
        }
        $pending = $this->client->call('permission.pending', ['sessionId' => $this->sessionId]);
        self::assertCount(1, $pending['items']);
        self::assertArrayNotHasKey('choices', $pending['items'][0]);
        $snapshot = $this->client->call('session.get', ['sessionId' => $this->sessionId]);
        self::assertCount(1, $snapshot['pendingAsks']);
        self::assertArrayNotHasKey('choices', $snapshot['pendingAsks'][0]);
    }

    public function testAGateAskOnBashCarriesNoChoicesKey(): void
    {
        $ask = $this->fixture->backend->ask('call-3', 'Bash', ['command' => 'ls -la']);
        $this->fixture->run(0.08);

        $requested = $this->client->events('permission.requested');
        self::assertCount(1, $requested);
        self::assertSame($ask->askId, $requested[0]['data']['askId']);
        self::assertArrayNotHasKey('choices', $requested[0]['data'], 'every non-AskUser ask stays byte-identical to the old wire');
        foreach ($this->client->events('permission.asked') as $asked) {
            self::assertArrayNotHasKey('choices', $asked['data']);
        }
        $pending = $this->client->call('permission.pending', ['sessionId' => $this->sessionId]);
        self::assertArrayNotHasKey('choices', $pending['items'][0]);
    }

    /**
     * Put one question on the running turn exactly as the turn child's ask
     * frame would, naming its source verbatim.
     *
     * @param array<string, mixed> $arguments
     */
    private function putAskOnTheWire(string $toolCallId, string $tool, string $source, array $arguments): PendingAsk
    {
        $ask = PendingAsk::fromFrame([
            'askId' => PendingAsk::askId($toolCallId, $tool, $arguments),
            'toolCallId' => $toolCallId,
            'tool' => $tool,
            'arguments' => $arguments,
            'reason' => 'the call asks its own question',
            'source' => $source,
            'mode' => 'default',
            'suggestions' => ['once', 'always', 'reject'],
            'alwaysScope' => ['tool' => $tool],
        ], static function (PermissionResolved $resolution): void {
            // The answer channel is not this test's subject.
        });
        self::assertNotNull($ask);
        $this->fixture->backend->emit(new PermissionAsked($ask));

        return $ask;
    }
}
