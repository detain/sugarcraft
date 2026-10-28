<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * C-SES-7.e — the archive tray and the incognito session over the wire.
 *
 * `session.list {archived}` is a tri-state: absent and false both hide the
 * tray (the historical law — every existing caller keeps its answer), true
 * shows ONLY archived rows. `session.archive`/`session.unarchive` wrap the
 * store's flag write; re-asking the state a session already holds is a
 * no-op answered with the fresh summary, and a session that leaves no store
 * row — an ephemeral one — is honestly not archivable.
 *
 * `session.create {ephemeral:true}` is the whole incognito contract in one
 * answer: the summary says so, the store says nothing (no row, no lock),
 * and the default listing never learns the id.
 *
 * @see \SugarCraft\Code\Host\SessionHub::list()
 * @see \SugarCraft\Code\Protocol\Methods\SessionMethods::setArchived()
 */
final class SessionArchiveMethodsTest extends TestCase
{
    private ?ProtocolFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->tearDown();
        $this->fixture = null;
        parent::tearDown();
    }

    private function storedSession(EnhancedSessionStore $store, string $id): void
    {
        $store->createSession($id, 'p', 'm');
        $store->saveTranscript($id, [['role' => 'user', 'content' => 'the ' . $id . ' body']]);
    }

    /**
     * `session.list` ids through one client. ProtocolFixture::client() opens
     * a NEW WireClient each call — a second client on the same id would
     * shadow the first server-side — so every arm here rides one client.
     *
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function listIds(WireClient $client, array $params = []): array
    {
        $result = $client->call('session.list', $params);

        return \array_map(static fn (array $row): string => (string) $row['id'], $result['items']);
    }

    public function testArchiveTakesASessionOutOfTheDefaultListingAndTrayIn(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $this->storedSession($fixture->store, 'live');
        $this->storedSession($fixture->store, 'filed');

        $client = $fixture->client();
        $summary = $client->call('session.archive', ['sessionId' => 'filed']);

        self::assertTrue($summary['archived'], 'the answer carries the new state');
        self::assertFalse($summary['ephemeral']);
        self::assertSame(['live'], $this->listIds($client), 'absent reads the live listing');
        self::assertSame(['live'], $this->listIds($client, ['archived' => false]), 'false spells the default out');
        self::assertSame(['filed'], $this->listIds($client, ['archived' => true]), 'true reads the tray');

        $trayRow = $client->call('session.list', ['archived' => true])['items'][0];
        self::assertTrue($trayRow['archived']);
        self::assertFalse($trayRow['open'], 'a stored tray row is not open');
    }

    public function testArchiveAndUnarchiveAreIdempotentNoOpsAnsweredByTheFreshSummary(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $this->storedSession($fixture->store, 'twice');

        $client = $fixture->client();
        $first = $client->call('session.archive', ['sessionId' => 'twice']);
        $again = $client->call('session.archive', ['sessionId' => 'twice']);
        self::assertTrue($again['archived'], 'a repeat archive costs nothing and answers true');
        self::assertSame($first, $again, 'the two answers are one answer');

        $back = $client->call('session.unarchive', ['sessionId' => 'twice']);
        self::assertFalse($back['archived']);
        self::assertSame(['twice'], $this->listIds($client));
        self::assertSame([], $this->listIds($client, ['archived' => true]));
        $repeat = $client->call('session.unarchive', ['sessionId' => 'twice']);
        self::assertFalse($repeat['archived'], 'a repeat unarchive is a no-op too');
        self::assertSame(['twice'], $this->listIds($client));
    }

    public function testTheTrayRidesBodySearchTooAndNeverLeaksIntoLiveMatches(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $this->storedSession($fixture->store, 'quiet');
        $this->storedSession($fixture->store, 'secret');
        $client = $fixture->client();
        $client->call('session.archive', ['sessionId' => 'secret']);

        self::assertSame(['quiet'], $this->listIds($client, ['query' => 'body']), 'the query path honours the tray law');
        self::assertSame(['secret'], $this->listIds($client, ['query' => 'body', 'archived' => true]), 'and reads the tray on request');
    }

    public function testArchiveOfAnUnknownSessionAnswersNotFound(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();

        $answer = $fixture->client()->request('session.archive', ['sessionId' => 'never-was']);

        self::assertSame(-32004, $answer['error']['code'] ?? null, (string) \json_encode($answer));
        self::assertSame('session_not_found', $answer['error']['data']['kind'] ?? null);
    }

    public function testUnarchiveOfAnUnknownSessionAnswersNotFound(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $client = $fixture->client();

        $answer = $client->request('session.unarchive', ['sessionId' => 'never-was']);

        self::assertSame(-32004, $answer['error']['code'] ?? null, (string) \json_encode($answer));
        self::assertSame('session_not_found', $answer['error']['data']['kind'] ?? null);
    }

    public function testAnEphemeralSessionAnswersItsSummaryAndLeavesNoRow(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $client = $fixture->client();

        $summary = $client->call('session.create', ['name' => 'ghost', 'ephemeral' => true]);

        self::assertTrue($summary['ephemeral'], 'the summary says incognito');
        self::assertFalse($summary['archived']);
        self::assertTrue($summary['open']);
        self::assertSame('ghost', $summary['name']);
        self::assertNull($fixture->store->session($summary['id']), 'no row was minted');
        self::assertSame([], $this->listIds($client), 'the default listing never learns the id');
        self::assertSame([], $this->listIds($client, ['archived' => true]), 'and neither does the tray');

        $asked = $client->request('session.archive', ['sessionId' => $summary['id']]);
        self::assertSame(-32004, $asked['error']['code'] ?? null, (string) \json_encode($asked));

        $closed = $client->call('session.close', ['sessionId' => $summary['id']]);
        self::assertSame(['closed' => true], $closed);
        self::assertFalse($fixture->hub->isOpen($summary['id']), 'and the hub dropped it on close');

        $lock = $fixture->store->lockSession($summary['id']);
        self::assertNotNull($lock, 'no lease was ever held — another process could always take the id');
        $lock->release();
    }

    public function testAPlainCreateStillListsAndEphemeralDefaultsOff(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $client = $fixture->client();

        $plain = $client->call('session.create', ['name' => 'ordinary']);
        self::assertFalse($plain['ephemeral'], 'omitted means persisted, as before');
        self::assertNotNull($fixture->store->session($plain['id']), 'and the row exists');
        self::assertSame([$plain['id']], $this->listIds($client));
    }
}
