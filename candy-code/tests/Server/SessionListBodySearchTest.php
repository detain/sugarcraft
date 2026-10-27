<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Usage;

/**
 * C-SES-2.c — `session.list {query}` is the server-side full-text search.
 *
 * Before this step the query arm was a str_contains over name, id and the
 * one-line preview, applied only to the rows a first chunk happened to hold;
 * a needle living anywhere else in a session's body was simply invisible.
 * Through the hub's FTS path it now finds body matches ranked best-first and
 * carries the matching snippet — and the summary honest about the spend even
 * after the session closed, because the ledger folds `cost_usd`/`cost_unpriced`
 * onto the row inside its own write transaction.
 *
 * @see \SugarCraft\Code\Host\SessionHub::list()
 * @see \SugarCraft\Code\Protocol\Methods\SessionMethods::list()
 */
final class SessionListBodySearchTest extends TestCase
{
    private ?ProtocolFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->tearDown();
        $this->fixture = null;
        parent::tearDown();
    }

    /** @param list<string> $bodies */
    private function saveChat(EnhancedSessionStore $store, string $id, array $bodies): void
    {
        $store->createSession($id, 'p', 'm');
        $messages = [];
        foreach ($bodies as $i => $body) {
            $messages[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => $body];
        }
        $store->saveTranscript($id, $messages);
    }

    public function testTheQueryReachesBodyTextTheClientStringFilterCouldNeverSee(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        // The needle is in the FIRST body, nowhere in id, name or the
        // last-message preview — the exact blind spot of the old arm.
        $this->saveChat($fixture->store, 'keeper', ['the negotiation fell through over the octopus clause', 'closing thoughts for now']);
        $this->saveChat($fixture->store, 'quiet', ['nothing remarkable here at all']);

        $client = $fixture->client();
        $result = $client->call('session.list', ['query' => 'octopus']);

        $ids = array_map(static fn (array $row): string => (string) $row['id'], $result['items']);
        self::assertSame(['keeper'], $ids, 'a body-only match is found, and only it');
        self::assertStringContainsString('[octopus]', (string) $result['items'][0]['snippet']);
        self::assertFalse((bool) $result['items'][0]['open']);
    }

    public function testAnUnqueriedSummaryCarriesNoSnippetAndNoInventedSpend(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $this->saveChat($fixture->store, 'plain', ['nothing remarkable here at all']);

        $client = $fixture->client();
        $result = $client->call('session.list', []);

        self::assertSame(['plain'], array_map(static fn (array $row): string => (string) $row['id'], $result['items']));
        self::assertNull($result['items'][0]['snippet'], 'the snippet rides ranked reads only');
        self::assertNull($result['items'][0]['spentUsd'], 'no ledger, no figure');
        self::assertFalse($result['items'][0]['spendUnpriced']);
    }

    public function testAClosedSessionStillReportsItsCachedSpendAndUnpricedBit(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $fixture->store->createSession('ledger', 'p', 'm');
        // The real ledger path: appendUsageRecord folds cost_usd/cost_unpriced
        // onto the row inside its own write transaction, so the figures the
        // summary reports for a CLOSED session are the ones the ledger cached
        // — no re-SUM of usage_records per list page.
        self::assertTrue($fixture->store->appendUsageRecord('ledger', Usage::new(totalTokens: 100, costUsd: 2.5, unpricedModel: 'mystery-9')));

        $client = $fixture->client();
        $listed = $client->call('session.list', [])['items'][0];

        self::assertSame('ledger', $listed['id']);
        self::assertSame(2.5, $listed['spentUsd'], 'the row cache answers after the host is gone');
        self::assertTrue($listed['spendUnpriced'], 'the sticky ≥ bit rides the same cache');
        self::assertSame('closed', $listed['status']);
    }

    public function testTheQueryPagesMatchesWithTheSameCursorLawAsThePlainList(): void
    {
        $fixture = $this->fixture = ProtocolFixture::new();
        $this->saveChat($fixture->store, 'first', ['an octopus in the rafters']);
        $this->saveChat($fixture->store, 'second', ['an octopus on the stage']);
        $this->saveChat($fixture->store, 'third', ['an octopus in the soup']);
        $this->saveChat($fixture->store, 'silent', ['no marine life discussed']);

        $client = $fixture->client();
        $pageOne = $client->call('session.list', ['query' => 'octopus', 'limit' => 2]);

        self::assertCount(2, $pageOne['items']);
        self::assertNotNull($pageOne['nextCursor'], 'a third match waits behind the cursor');

        $pageTwo = $client->call('session.list', ['query' => 'octopus', 'limit' => 2, 'cursor' => $pageOne['nextCursor']]);

        $seen = array_map(static fn (array $row): string => (string) $row['id'], [...$pageOne['items'], ...$pageTwo['items']]);
        sort($seen);
        self::assertSame(['first', 'second', 'third'], $seen, 'the pages cover exactly the matches, no repeat, no drop');
        self::assertNull($pageTwo['nextCursor']);
        self::assertStringContainsString('[octopus]', (string) $pageTwo['items'][0]['snippet'], 'paged matches keep their snippets');
    }
}
