<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionQuery;

/**
 * SES-2 polarity B — the latch. The store is opened asking for an FTS module
 * by a name SQLite does not know, which is exactly the "this build has no
 * fts5" condition, and the promise is that nothing else notices: the schema
 * never gains the side tables, saves stay whole, and `bodySearch` answers
 * with the byte-identical result the pre-SES-2 LIKE prefilter gave for the
 * same text — degrade, never fail.
 *
 * @see SessionSearchFtsTest the module-present mirror of every pin here
 */
final class SessionSearchUnavailableTest extends TestCase
{
    private const NO_SUCH_MODULE = 'no_such_fts_module_probe';

    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush_ftsl_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->dbPath = $this->tempDir . '/latched.db';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['latched.db', 'latched.db-wal', 'latched.db-shm'] as $name) {
            @unlink($this->tempDir . '/' . $name);
        }
        @rmdir($this->tempDir);
    }

    public function testTheLatchedStoreNeverMintsTheSideTables(): void
    {
        new EnhancedSessionStore($this->dbPath, self::NO_SUCH_MODULE);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $names = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        unset($pdo);

        self::assertNotContains('session_message_rows', $names);
        self::assertNotContains('session_messages_fts', $names);
    }

    public function testABodyQueryOnALatchedStoreAnswersExactlyLikeItsNameQuery(): void
    {
        $store = new EnhancedSessionStore($this->dbPath, self::NO_SUCH_MODULE);
        $store->createSession('named-marmoset', 'p', 'm');
        $store->saveTranscript('named-marmoset', [
            ['role' => 'user', 'content' => 'bodies mentioning marmosets are invisible to LIKE'],
        ]);
        $store->createSession('other', 'p', 'm');
        $store->saveTranscript('other', [['role' => 'user', 'content' => 'unrelated prose']]);

        $bodyWay = $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('named-marmoset'));
        $likeWay = $store->listSessionsFiltered((SessionQuery::new())->withSearch('named-marmoset'));

        self::assertSame(
            array_map(static fn($row): string => $row->id, $likeWay),
            array_map(static fn($row): string => $row->id, $bodyWay),
            'the folded query is the same query',
        );
        self::assertSame([], $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('marmosets')), 'body words stay invisible while latched — LIKE never saw them, and pretending otherwise would be the fail part of fail-not-degrade');
    }

    public function testASaveStillPersistsWholeWhileLatched(): void
    {
        $store = new EnhancedSessionStore($this->dbPath, self::NO_SUCH_MODULE);
        $store->createSession('s1', 'p', 'm');
        $store->saveTranscript('s1', [
            ['role' => 'user', 'content' => 'the save itself cannot care about the index'],
            ['role' => 'assistant', 'content' => 'nor can the resume'],
        ]);

        self::assertCount(2, $store->loadTranscript('s1') ?? []);
        self::assertSame(['s1'], array_map(
            static fn($row): string => $row->id,
            $store->listSessionsFiltered((SessionQuery::new())->withSearch('s1')),
        ));

        $store->deleteSession('s1');
        self::assertSame([], $store->listSessionsFiltered((SessionQuery::new())->withSearch('s1')));
    }

    public function testBodyAndNameQueriesBothSetCollapseToTheNameOne(): void
    {
        $store = new EnhancedSessionStore($this->dbPath, self::NO_SUCH_MODULE);
        $store->createSession('ibex-alpha', 'p', 'm');
        $store->saveTranscript('ibex-alpha', [['role' => 'user', 'content' => 'body says ibex twice twice']]);

        $both = $store->listSessionsFiltered(
            (SessionQuery::new())->withSearch('ibex-alpha')->withBodySearch('ibex')
        );
        self::assertSame(['ibex-alpha'], array_map(static fn($row): string => $row->id, $both), 'the name query is kept; the body word it cannot rank is simply what LIKE gets');
        self::assertNull($both[0]->bodySnippet);
    }
}
