<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionQuery;
use SugarCraft\Code\Session\SessionStore;

/**
 * SES-2 polarity A — the FTS5 module present, which is every runtime this
 * suite runs on: `withBodySearch` answers from message bodies in bm25 rank
 * order with a snippet, incremental saves keep the index current without a
 * rebuild, a fresh process pays the sweep exactly once, and the pre-SES-2
 * LIKE path is byte-unchanged by the index merely existing.
 *
 * The no-FTS5 half of the pair lives in SessionSearchUnavailableTest; the
 * side table at its own level is SessionSearchIndexTest.
 *
 * @see EnhancedSessionStore::listSessionsFiltered()
 */
final class SessionSearchFtsTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush_ftsq_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->dbPath = $this->tempDir . '/fts.db';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['fts.db', 'fts.db-wal', 'fts.db-shm'] as $name) {
            @unlink($this->tempDir . '/' . $name);
        }
        @rmdir($this->tempDir);
    }

    private function ftsStore(): EnhancedSessionStore
    {
        return new EnhancedSessionStore($this->dbPath);
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

    public function testABodyWordNoNameOrPreviewCarriesStillFindsItsSession(): void
    {
        $store = $this->ftsStore();
        $this->saveChat($store, 'quiet', ['nothing remarkable here at all']);
        $this->saveChat($store, 'keeper', ['the negotiation fell through over the octopus clause']);

        $hits = $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('octopus'));

        self::assertSame(['keeper'], array_map(static fn($row): string => $row->id, $hits));
        self::assertNotNull($hits[0]->bodySnippet);
        self::assertStringContainsString('[octopus]', (string) $hits[0]->bodySnippet);
    }

    public function testRankedResultsComeBackBestMatchFirstNotNewestFirst(): void
    {
        $store = $this->ftsStore();
        // 'later' is the NEWEST session; 'denser' is the better full-text hit.
        $this->saveChat($store, 'trace', ['one mention of the quarterly review']);
        $this->saveChat($store, 'denser', ['quarterly quarterly quarterly review of the quarterly numbers']);
        $this->saveChat($store, 'later', ['the quarterly summary landed last']);

        $order = array_map(
            static fn($row): string => $row->id,
            $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('quarterly')),
        );

        self::assertSame('denser', $order[0], 'bm25 density outranks recency — that is the feature');
        self::assertContains('trace', $order);
        self::assertContains('later', $order);
    }

    public function testBodySearchAndLikeSearchConjunctivelyAndRankingStillOrders(): void
    {
        $store = $this->ftsStore();
        $this->saveChat($store, 'alpha-notes', ['harbor drafts about the quarterly ledger']);
        $this->saveChat($store, 'beta-notes', ['harbor drafts about the quarterly ledger too']);

        $both = array_map(
            static fn($row): string => $row->id,
            $store->listSessionsFiltered(
                (SessionQuery::new())->withBodySearch('harbor')->withSearch('alpha')
            ),
        );

        self::assertSame(['alpha-notes'], $both, 'LIKE narrows what FTS ranked, it does not replace it');
    }

    public function testASecondSaveInSameSessionNeedsNoRebuildToBeSearchable(): void
    {
        $store = $this->ftsStore();
        $this->saveChat($store, 'growing', ['the first message mentions windmills']);
        $this->saveChat($store, 'stale', ['nothing relevant']);

        self::assertCount(1, $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('windmills')));

        $store->saveTranscript('growing', [
            ['role' => 'user', 'content' => 'the first message mentions windmills'],
            ['role' => 'assistant', 'content' => 'and the reply brings in windfarms'],
        ]);

        $hits = array_map(
            static fn($row): string => $row->id,
            $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('windfarms')),
        );
        self::assertSame(['growing'], $hits, 'the per-save diff indexes the new message in the save that made it');
    }

    public function testAResavedUnchangedTranscriptDoesNotChurnTheIndex(): void
    {
        $store = $this->ftsStore();
        $this->saveChat($store, 's1', ['carried identities keep the anchor rows stable']);

        // A body search has now paid the first sweep; snapshot the anchor.
        $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('anchor'));
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $before = (int) $pdo->query('SELECT COUNT(*) FROM session_message_rows')->fetchColumn();
        unset($pdo);

        $rows = $store->loadTranscript('s1') ?? [];
        self::assertNotSame([], $rows);
        $store->saveTranscript('s1', $rows);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $after = (int) $pdo->query('SELECT COUNT(*) FROM session_message_rows')->fetchColumn();
        unset($pdo);

        self::assertSame($before, $after, 'carried ids and equal hashes make the diff a true no-op');
    }

    public function testTheSweepHappensOnTheFirstBodySearchNotAtOpen(): void
    {
        $writer = $this->ftsStore();
        $this->saveChat($writer, 'old-process', ['a sentence about windroses and salt']);
        $reader = $this->ftsStore();

        // Opening is index-cheap: the second store has read nothing yet, and
        // the first thing that costs work is the search itself.
        self::assertSame(
            ['old-process'],
            array_map(
                static fn($row): string => $row->id,
                $reader->listSessionsFiltered((SessionQuery::new())->withBodySearch('windroses')),
            ),
            'a store that saved nothing this process still finds sessions an earlier process saved',
        );
    }

    public function testDeletingASessionTakesItsBodiesOutOfSearch(): void
    {
        $store = $this->ftsStore();
        $this->saveChat($store, 'doomed', ['seventeen words about the albatross incident']);
        $this->saveChat($store, 'survivor', ['the albatross incident, retold']);

        self::assertCount(2, $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('albatross')));
        $store->deleteSession('doomed');

        $after = array_map(
            static fn($row): string => $row->id,
            $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('albatross')),
        );
        self::assertSame(['survivor'], $after, 'a deleted session stops answering while its words are still shadow rows');
    }

    public function testTheLikePathStaysItselfAlongsideAndAfterTheIndex(): void
    {
        $store = $this->ftsStore();
        $this->saveChat($store, 'plain-name', ['body prose that the plain search must not see']);
        $this->saveChat($store, 'other', ['nothing']);

        $plain = $store->listSessionsFiltered((SessionQuery::new())->withSearch('plain-name'));
        self::assertSame(['plain-name'], array_map(static fn($row): string => $row->id, $plain));
        self::assertNull($plain[0]->bodySnippet, 'LIKE answers never wear a snippet — null is how the two paths tell');

        // A body search ran in this process; the LIKE result is identical now.
        $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('prose'));
        $again = $store->listSessionsFiltered((SessionQuery::new())->withSearch('plain-name'));
        self::assertSame(
            array_map(static fn($row): string => $row->id, $plain),
            array_map(static fn($row): string => $row->id, $again),
        );
    }

    public function testThePlainStoreRefusesBodySearchInsteadOfMisansweringIt(): void
    {
        $base = new SessionStore($this->dbPath);
        $base->createSession('s1', 'p', 'm');

        $this->expectException(\LogicException::class);
        $base->listSessionsFiltered((SessionQuery::new())->withBodySearch('anything'));
    }
}
