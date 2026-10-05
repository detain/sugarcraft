<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Protocol\Schema\EventSchemas;
use SugarCraft\Crush\Protocol\Schema\ProtocolSchema;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Server\Support\WireClient;

/**
 * Roadmap O-6a, Appendix O §6.9 / §7.4: watching several sessions at once.
 *
 * A question put in a session a client does not follow reaches that client
 * at once as the server-scope `permission.asked` (and its settling as
 * `permission.settled`), and every status change reaches every client as a
 * `session.updated` summary — the cross-session approvals drawer and the
 * sidebar no longer wait for the next 15 s `server.tick`.
 *
 * `client.viewing` with `narrate` turns every followed session that is not
 * in front into a narrated one — tails at most every 2 s, no deltas — and a
 * session brought to the front is handed the tail at once, at the byte
 * offset it starts, so the deltas that follow continue it without a gap.
 */
final class MultiSessionAttentionTest extends TestCase
{
    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testAQuestionInASessionNobodyHereFollowsArrivesAtOnce(): void
    {
        $driver = $this->fixture->client('driver');
        $watcher = $this->fixture->client('watcher');
        $sessionId = $this->fixture->session($driver);
        $driver->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();
        $driver->call('session.send', ['sessionId' => $sessionId, 'text' => 'deploy']);
        $this->fixture->run(0.08);

        $ask = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'make deploy']);
        $this->fixture->run(0.08);

        self::assertSame([], $watcher->events('permission.requested'), 'the watcher follows no session');
        $asked = $watcher->events('permission.asked');
        self::assertCount(1, $asked, 'one question, one server-scope event — no tick needed');
        self::assertNull($asked[0]['sessionId'], 'a server-scope event names its session in its data');
        self::assertSame($sessionId, $asked[0]['data']['sessionId']);
        self::assertSame($ask->askId, $asked[0]['data']['askId']);
        self::assertSame('Bash', $asked[0]['data']['tool']);
        self::assertSame(['command' => 'make deploy'], $asked[0]['data']['arguments']);
        self::assertSame([], self::schemaErrors('permission.asked', $asked[0]['data']));

        $watcher->call('permission.respond', ['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'once']);
        $this->fixture->run(0.08);

        $settled = $watcher->events('permission.settled');
        self::assertCount(1, $settled);
        self::assertSame(['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'once'], $settled[0]['data']);
        self::assertSame([], self::schemaErrors('permission.settled', $settled[0]['data']));
        self::assertCount(1, $driver->events('permission.asked'), 'a follower hears it too; the askId makes it idempotent');
    }

    public function testACancelledQuestionIsSettledForEveryClient(): void
    {
        $driver = $this->fixture->client('driver');
        $watcher = $this->fixture->client('watcher');
        $sessionId = $this->fixture->session($driver);
        $driver->call('session.send', ['sessionId' => $sessionId, 'text' => 'wait']);
        $this->fixture->run(0.08);
        $ask = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'sleep 99']);
        $this->fixture->run(0.08);

        $driver->call('session.cancel', ['sessionId' => $sessionId]);
        $this->fixture->run(0.08);

        $settled = $watcher->events('permission.settled');
        self::assertCount(1, $settled);
        self::assertSame($ask->askId, $settled[0]['data']['askId']);
        self::assertTrue($settled[0]['data']['cancelled']);
    }

    public function testEveryStatusChangeReachesEveryClientAsASessionSummary(): void
    {
        $driver = $this->fixture->client('driver');
        $watcher = $this->fixture->client('watcher');
        $sessionId = $this->fixture->session($driver);
        $watcher->clear();

        $driver->call('session.send', ['sessionId' => $sessionId, 'text' => 'hello']);
        $this->fixture->run(0.08);
        $this->fixture->backend->ask('c1', 'Read', ['file_path' => 'a.txt']);
        $this->fixture->run(0.08);
        $this->fixture->backend->settle(Message::assistant('done'));
        $this->fixture->run(0.08);

        $updates = \array_values(\array_filter(
            $watcher->events('session.updated'),
            static fn (array $event): bool => $event['data']['id'] === $sessionId,
        ));
        self::assertSame(['busy', 'waiting_permission', 'idle'], \array_column(\array_column($updates, 'data'), 'status'));
        foreach ($updates as $update) {
            self::assertSame([], self::schemaErrors('session.updated', $update['data']));
            self::assertTrue($update['data']['open']);
        }
    }

    public function testABackgroundPaneIsNarratedAndTheOneInFrontStreams(): void
    {
        $client = $this->fixture->client();
        $front = $this->fixture->session($client);
        $glanced = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $front]);
        $client->call('session.subscribe', ['sessionId' => $glanced]);
        $this->fixture->run();

        $viewing = $client->call('client.viewing', ['sessionIds' => [$front, $glanced], 'foreground' => $front, 'narrate' => true]);
        self::assertSame([$glanced], $viewing['narrated']);

        $client->call('session.send', ['sessionId' => $glanced, 'text' => 'explain']);
        $this->fixture->run(0.08);
        $this->fixture->backend->token('Hello ');
        $this->fixture->backend->token('world');
        $this->fixture->run(0.08);

        $mine = static fn (array $event): bool => $event['sessionId'] === $glanced;
        self::assertSame([], \array_filter($client->events('assistant.delta'), $mine), 'a glanced-at pane hears no deltas');
        $narration = \array_values(\array_filter($client->events('assistant.narration'), $mine));
        self::assertCount(1, $narration, 'at most one tail every 2 s');
        self::assertSame('Hello ', $narration[0]['data']['tail']);
        self::assertSame(0, $narration[0]['data']['offset']);
        self::assertContains('turn.started', \array_column(\array_filter($client->events(), $mine), 'type'), 'durable events still arrive');
    }

    public function testAPaneBroughtToTheFrontIsCaughtUpAndItsDeltasContinueWithoutAGap(): void
    {
        $client = $this->fixture->client();
        $front = $this->fixture->session($client);
        $glanced = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $front]);
        $client->call('session.subscribe', ['sessionId' => $glanced]);
        $this->fixture->run();
        $client->call('client.viewing', ['sessionIds' => [$front, $glanced], 'foreground' => $front, 'narrate' => true]);

        $client->call('session.send', ['sessionId' => $glanced, 'text' => 'explain']);
        $this->fixture->run(0.08);
        $this->fixture->backend->token('Hello ');
        $this->fixture->backend->token('world');
        $this->fixture->run(0.08);
        $client->clear();

        $viewing = $client->call('client.viewing', ['sessionIds' => [$front, $glanced], 'foreground' => $glanced, 'narrate' => true]);
        self::assertSame([$front], $viewing['narrated']);
        $this->fixture->run();

        $caughtUp = $client->events('assistant.narration');
        self::assertCount(1, $caughtUp, 'the reply so far, at once — not at the next 2 s tail');
        self::assertSame('Hello world', $caughtUp[0]['data']['tail']);
        self::assertSame(0, $caughtUp[0]['data']['offset']);

        $this->fixture->backend->token('!');
        $this->fixture->run(0.08);
        $deltas = $client->events('assistant.delta');
        self::assertSame(['!'], \array_column(\array_column($deltas, 'data'), 'text'));
        self::assertSame(11, $deltas[0]['data']['offset'], 'the first delta starts where the tail ended');
    }

    public function testTheTailStartsAfterAToolCallAndNamesItsOffset(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId, 'mode' => 'narration']);
        $this->fixture->run();

        $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'look']);
        $this->fixture->run(0.08);
        $this->fixture->backend->token('Reading it.');
        $this->fixture->backend->emit(new ToolStarted('c1', 'Read', ['file_path' => 'a.txt']));
        $this->fixture->backend->token('Found');
        $this->fixture->run(0.08);

        // The first tail went out at once; the second waits out the 2 s, so
        // catch up explicitly: the tail holds only what followed the call.
        $view = $client->call('client.viewing', ['sessionIds' => [$sessionId], 'foreground' => $sessionId]);
        self::assertSame([$sessionId], $view['narrated'], 'a narration subscription stays narrated in front');
        $tails = \array_column(\array_column($client->events('assistant.narration'), 'data'), 'tail');
        self::assertSame(['Reading it.'], $tails);

        $feed = $this->fixture->context->feed($sessionId);
        self::assertNotNull($feed);
        $socket = $this->fixture->context->clients()[0];
        $client->clear();
        self::assertTrue($feed->catchUp($socket));
        $socket->outbox()->flush();
        $caughtUp = $client->events('assistant.narration');
        self::assertSame('Found', $caughtUp[0]['data']['tail'], 'the text before the tool call is already a row');
        self::assertSame(\strlen('Reading it.'), $caughtUp[0]['data']['offset']);
    }

    public function testNarrateMustBeABoolean(): void
    {
        $client = $this->fixture->client();

        self::assertSame(-32602, $client->request('client.viewing', ['sessionIds' => [], 'narrate' => 'yes'])['error']['code']);
        self::assertSame([], $client->call('client.viewing', ['sessionIds' => []])['narrated']);
    }

    public function testAClientThatDoesNotFollowTheSessionIsNotCaughtUp(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $feed = $this->fixture->context->feed($sessionId);
        self::assertNotNull($feed);

        $other = WireClient::open($this->fixture->dispatcher, 'stranger');
        $other->hello();
        self::assertFalse($feed->catchUp($this->fixture->context->clients()[1]), 'a client that does not follow the session gets nothing');
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function schemaErrors(string $type, array $data): array
    {
        $schema = EventSchemas::data($type);
        self::assertNotNull($schema, $type . ' has a schema');

        return ProtocolSchema::errors($data, $schema);
    }
}
