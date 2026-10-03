<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionKind;
use SugarCraft\Crush\Session\SessionRow;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Session\TitleSource;

/**
 * The Appendix P session columns (`kind`, `parent_id`, `pinned`,
 * `archived_at`, `turns`, `last_preview`, …) reach an existing database on
 * its next open, idempotently and with their defaults, and the row-level
 * writes that use them behave.
 *
 * @see SessionStore::initSchema()
 */
final class SessionSchemaMigrationTest extends TestCase
{
    private const NEW_COLUMNS = [
        'kind', 'parent_id', 'parent_call_id', 'agent', 'status', 'title_source',
        'pinned', 'archived_at', 'cwd', 'git_branch', 'turns', 'last_preview',
    ];

    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush_schema_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->dbPath = $this->tempDir . '/test.db';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    public function testAnOldDatabaseGainsEveryColumnWithItsDefault(): void
    {
        $this->createPreMigrationDatabase();

        $store = new SessionStore($this->dbPath);

        $columns = $this->columns();
        foreach (self::NEW_COLUMNS as $column) {
            $this->assertContains($column, $columns, "column {$column} was not migrated in");
        }

        $row = SessionRow::fromArray($store->getSession('legacy'));
        $this->assertSame(SessionKind::Main, $row->kind);
        $this->assertFalse($row->pinned);
        $this->assertNull($row->archivedAt);
        $this->assertSame(0, $row->turns);
        $this->assertNull($row->parentId);
        $this->assertSame('Old work', $row->name);
        $this->assertNull($row->titleSource, 'a pre-migration name has no recorded source');

        // Still listed: the default filter matches the defaulted columns.
        $this->assertSame(['legacy'], array_column($store->listSessions(), 'id'));
    }

    public function testTheMigrationIsIdempotentAcrossOpens(): void
    {
        $this->createPreMigrationDatabase();

        new SessionStore($this->dbPath);
        new EnhancedSessionStore($this->dbPath);
        new SessionStore($this->dbPath);

        $columns = $this->columns();
        $this->assertSame(count($columns), count(array_unique($columns)));
        foreach (self::NEW_COLUMNS as $column) {
            $this->assertContains($column, $columns);
        }
    }

    public function testAFreshDatabaseHasTheParentIndex(): void
    {
        new SessionStore($this->dbPath);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'sessions'");
        $indexes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('idx_sessions_parent_id', $indexes);
        $this->assertContains('idx_sessions_updated_at', $indexes);
    }

    public function testCreateSessionRecordsWhereItWasOpened(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm', null, null, '/work/app', 'main');
        $store->createSession('s2', 'p', 'm', null, null, '', '');

        $one = SessionRow::fromArray($store->getSession('s1'));
        $this->assertSame('/work/app', $one->cwd);
        $this->assertSame('main', $one->gitBranch);

        $two = SessionRow::fromArray($store->getSession('s2'));
        $this->assertNull($two->cwd, 'a blank cwd is stored as NULL');
        $this->assertNull($two->gitBranch);
    }

    public function testRenameRecordsItsSourceAndAnAutoRenameNeverBeatsAUserOne(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm');

        $this->assertTrue($store->renameSession('s1', 'Generated', TitleSource::Auto));
        $this->assertSame(TitleSource::Auto, SessionRow::fromArray($store->getSession('s1'))->titleSource);

        $this->assertTrue($store->renameSession('s1', 'Mine'));
        $this->assertSame(TitleSource::User, SessionRow::fromArray($store->getSession('s1'))->titleSource);

        $this->assertFalse($store->renameSession('s1', 'Generated again', TitleSource::Auto));
        $this->assertSame('Mine', $store->getSession('s1')['name']);
    }

    public function testRecordTurnCountsAndKeepsAClippedOneLinePreview(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm');
        $before = $store->getSession('s1')['updated_at'];

        $this->assertTrue($store->recordTurn('s1', "  fix the\n\tlogin   bug  "));
        $this->assertTrue($store->recordTurn('s1', str_repeat('é', 200)));

        $row = SessionRow::fromArray($store->getSession('s1'));
        $this->assertSame(2, $row->turns);
        $this->assertNotNull($row->lastPreview);
        $this->assertLessThanOrEqual(SessionStore::PREVIEW_MAX_BYTES, strlen($row->lastPreview));
        $this->assertTrue(mb_check_encoding($row->lastPreview, 'UTF-8'), 'the cut must land on a character boundary');
        $this->assertSame($before, $row->updatedAt, 'recency belongs to the transcript save');

        $store->recordTurn('s1', "  fix the\n\tlogin   bug  ");
        $this->assertSame('fix the login bug', $store->getSession('s1')['last_preview']);

        $this->assertFalse($store->recordTurn('missing', 'x'));
    }

    public function testPinArchiveAndUnarchiveReportWhetherTheyApplied(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm');

        $this->assertTrue($store->setPinned('s1', true));
        $this->assertTrue(SessionRow::fromArray($store->getSession('s1'))->pinned);
        $this->assertFalse($store->setPinned('missing', true));

        $this->assertTrue($store->archive('s1'));
        $this->assertFalse($store->archive('s1'), 'already archived');
        $this->assertSame([], $store->listSessions(), 'an archived row leaves the default list');

        $this->assertTrue($store->unarchive('s1'));
        $this->assertFalse($store->unarchive('s1'));
        $this->assertSame(['s1'], array_column($store->listSessions(), 'id'));
    }

    public function testChildSessionsRecordTheirParentAndStatus(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('parent', 'p', 'm', null, null, '/work', 'feature');

        $child = $store->createChildSession('parent', SessionKind::Subagent, 'explore', 'call-1', 'p', 'm', 'Map the login flow');
        $row = SessionRow::fromArray($store->getSession($child));

        $this->assertSame(SessionKind::Subagent, $row->kind);
        $this->assertSame('parent', $row->parentId);
        $this->assertSame('call-1', $row->parentCallId);
        $this->assertSame('explore', $row->agent);
        $this->assertSame('running', $row->status);
        $this->assertSame('/work', $row->cwd, 'a child inherits where its parent runs');
        $this->assertSame('feature', $row->gitBranch);

        $this->assertTrue($store->markSubAgentStatus($child, 'complete'));
        $this->assertSame('complete', $store->getSession($child)['status']);
        $this->assertFalse($store->markSubAgentStatus('parent', 'complete'), 'a main row has no status');

        $this->expectException(\InvalidArgumentException::class);
        $store->markSubAgentStatus($child, 'exploded');
    }

    public function testAChildNeedsAnExistingParentAndAChildKind(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('parent', 'p', 'm');

        try {
            $store->createChildSession('missing', SessionKind::Subagent, null, null, 'p', 'm');
            $this->fail('a missing parent must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('missing', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $store->createChildSession('parent', SessionKind::Main, null, null, 'p', 'm');
    }

    public function testAForkRecordsItsParentAndCarriesThePickerColumns(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('src', 'p', 'm', null, null, '/work', 'main');
        $store->renameSession('src', 'Auth refactor');
        $store->recordTurn('src', 'make the login controller use the new guard');

        $fork = SessionRow::fromArray($store->getSession($store->forkSession('src')));
        $this->assertSame(SessionKind::Branch, $fork->kind);
        $this->assertSame('src', $fork->parentId);
        $this->assertSame('Auth refactor (branch)', $fork->name);
        $this->assertSame(TitleSource::User, $fork->titleSource);
        $this->assertSame('/work', $fork->cwd);
        $this->assertSame('main', $fork->gitBranch);
        $this->assertSame(1, $fork->turns);
        $this->assertSame('make the login controller use the new guard', $fork->lastPreview);

        $bg = SessionRow::fromArray($store->getSession($store->forkSession('src', SessionKind::Background)));
        $this->assertSame(SessionKind::Background, $bg->kind);

        $this->expectException(\InvalidArgumentException::class);
        $store->forkSession('src', SessionKind::Subagent);
    }

    public function testSessionRowRoundTripsThroughItsStorageShape(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm', null, null, '/w', 'b');
        $store->setPinned('s1', true);

        $raw = $store->getSession('s1');
        $row = SessionRow::fromArray($raw);
        $array = $row->toArray();

        $this->assertSame('s1', $array['id']);
        $this->assertSame('main', $array['kind']);
        $this->assertTrue($array['pinned']);
        $this->assertSame('/w', $array['cwd']);
        $this->assertEquals($row, SessionRow::fromArray([...$array, 'pinned' => 1]));
        $this->assertFalse($row->archived());
    }

    public function testUnknownStoredEnumValuesReadLeniently(): void
    {
        $row = SessionRow::fromArray(['id' => 'x', 'kind' => 'from-the-future', 'title_source' => 'robot']);

        $this->assertSame(SessionKind::Main, $row->kind);
        $this->assertNull($row->titleSource);
    }

    /** The `sessions` table as shipped before Appendix P, with one named row. */
    private function createPreMigrationDatabase(): void
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE sessions (
                id TEXT PRIMARY KEY,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                provider TEXT NOT NULL,
                model TEXT NOT NULL,
                system_prompt TEXT,
                name TEXT,
                metadata TEXT
            )
        ');
        $pdo->exec("INSERT INTO sessions (id, provider, model, name) VALUES ('legacy', 'p', 'm', 'Old work')");
    }

    /** @return list<string> */
    private function columns(): array
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);

        return array_column($pdo->query('PRAGMA table_info(sessions)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
}
