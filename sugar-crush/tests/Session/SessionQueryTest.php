<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionKind;
use SugarCraft\Crush\Session\SessionQuery;
use SugarCraft\Crush\Session\SessionRow;
use SugarCraft\Crush\Session\SessionStore;

/**
 * The Appendix P list API: {@see SessionQuery} filters, child lookups, and
 * the defaults that keep sub-agent and archived rows out of the tab strip,
 * `--continue` and retention (risk 8: without them every sub-agent becomes a
 * tab).
 *
 * @see EnhancedSessionStore::listSessionsFiltered()
 */
final class SessionQueryTest extends TestCase
{
    private EnhancedSessionStore $store;
    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush_query_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->dbPath = $this->tempDir . '/test.db';
        $this->store = new EnhancedSessionStore($this->dbPath);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($this->store);
        foreach (glob($this->tempDir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir . '/sessions');
        @rmdir($this->tempDir);
    }

    public function testTheQueryIsImmutableWithDefaultListDefaults(): void
    {
        $query = SessionQuery::new();

        $this->assertSame([SessionKind::Main, SessionKind::Branch], $query->kinds);
        $this->assertFalse($query->includeArchived);
        $this->assertFalse($query->archivedOnly);
        $this->assertNull($query->parentId);
        $this->assertFalse($query->pinnedFirst);
        $this->assertSame(20, $query->limit);
        $this->assertSame(0, $query->offset);
        $this->assertNull($query->search);

        $changed = $query->withKinds(SessionKind::Subagent)->withLimit(0)->withOffset(-3)->withSearch('  ')->withParentId('p');
        $this->assertNotSame($query, $changed);
        $this->assertSame([SessionKind::Subagent], $changed->kinds);
        $this->assertSame(1, $changed->limit, 'limit clamps to 1');
        $this->assertSame(0, $changed->offset, 'offset clamps to 0');
        $this->assertNull($changed->search, 'a blank search lifts the filter');
        $this->assertSame('p', $changed->parentId);
        $this->assertNull($changed->withParentId(null)->parentId);
        $this->assertSame([SessionKind::Main, SessionKind::Branch], $query->kinds, 'the original is untouched');
        $this->assertSame([], $query->withKinds()->kinds, 'no kinds means every kind');
    }

    public function testTheDefaultListExcludesSubagentBackgroundAndArchivedRows(): void
    {
        $this->seedTree();

        $default = $this->ids($this->store->listSessionsFiltered(SessionQuery::new()));
        sort($default);
        $this->assertSame(['branch', 'main', 'pinned'], $default);

        // The tab strip's memoised statement applies the same filter.
        $strip = array_column($this->store->listSessions(50), 'id');
        sort($strip);
        $this->assertSame($default, $strip);
    }

    public function testKindsArchivedAndParentFilters(): void
    {
        $this->seedTree();

        $everything = $this->ids($this->store->listSessionsFiltered(SessionQuery::new()->withKinds()->withIncludeArchived()->withLimit(100)));
        $this->assertCount(6, $everything);

        $this->assertSame(['archived'], $this->ids($this->store->listSessionsFiltered(SessionQuery::new()->withArchivedOnly())));
        $this->assertSame(['sub'], $this->ids($this->store->listSessionsFiltered(SessionQuery::new()->withKinds(SessionKind::Subagent))));

        $children = $this->ids($this->store->listSessionsFiltered(SessionQuery::new()->withKinds()->withParentId('main')));
        sort($children);
        $this->assertSame(['bg', 'branch', 'sub'], $children);
    }

    public function testPinnedFirstThenNewest(): void
    {
        $this->store->createSession('old', 'p', 'm');
        $this->store->createSession('new', 'p', 'm');
        $this->store->createSession('pin', 'p', 'm');
        $this->age('pin', '2000-01-01 00:00:00');
        $this->store->setPinned('pin', true);

        $this->assertSame(['new', 'old', 'pin'], $this->ids($this->store->listSessionsFiltered(SessionQuery::new())));
        $this->assertSame(['pin', 'new', 'old'], $this->ids($this->store->listSessionsFiltered(SessionQuery::new()->withPinnedFirst())));
    }

    public function testLimitAndOffsetPage(): void
    {
        foreach (range(1, 5) as $i) {
            $this->store->createSession("s{$i}", 'p', 'm');
        }

        $this->assertSame(['s5', 's4'], $this->ids($this->store->listSessionsFiltered(SessionQuery::new()->withLimit(2))));
        $this->assertSame(['s3', 's2'], $this->ids($this->store->listSessionsFiltered(SessionQuery::new()->withLimit(2)->withOffset(2))));
    }

    public function testSearchPrefiltersNamePreviewAndIdLiterally(): void
    {
        $this->store->createSession('aaa111', 'p', 'm', null, 'Auth refactor');
        $this->store->createSession('bbb222', 'p', 'm');
        $this->store->recordTurn('bbb222', 'why is the PTY test flaky');
        $this->store->createSession('ccc333', 'p', 'm', null, '100% done_ok');

        $this->assertSame(['aaa111'], $this->ids($this->store->listSessionsFiltered(SessionQuery::new()->withSearch('auth'))));
        $this->assertSame(['bbb222'], $this->ids($this->store->listSessionsFiltered(SessionQuery::new()->withSearch('PTY'))));
        $this->assertSame(['ccc333'], $this->ids($this->store->listSessionsFiltered(SessionQuery::new()->withSearch('ccc3'))));
        // LIKE metacharacters are matched literally, not as wildcards.
        $this->assertSame(['ccc333'], $this->ids($this->store->listSessionsFiltered(SessionQuery::new()->withSearch('0% d'))));
        $this->assertSame([], $this->ids($this->store->listSessionsFiltered(SessionQuery::new()->withSearch('d_n'))));
    }

    public function testChildrenOfAndChildCount(): void
    {
        $this->seedTree();

        $children = $this->ids($this->store->childrenOf('main'));
        sort($children);
        $this->assertSame(['bg', 'branch', 'sub'], $children);
        $this->assertContainsOnlyInstancesOf(SessionRow::class, $this->store->childrenOf('main'));

        $this->assertSame(['main' => 3, 'branch' => 0, 'nope' => 0], $this->store->childCount(['main', 'branch', 'nope']));
        $this->assertSame([], $this->store->childCount([]));
    }

    public function testDeletingAParentTakesItsSubagentsAndDetachesItsBranches(): void
    {
        $this->seedTree();
        $grandchild = $this->store->createChildSession('sub', SessionKind::Subagent, 'worker', 'call-2', 'p', 'm');

        $deleted = $this->store->deleteSession('main');
        sort($deleted);

        $expected = ['main', 'sub', $grandchild];
        sort($expected);
        $this->assertSame($expected, $deleted);
        $this->assertNull($this->store->getSession('sub'));
        $this->assertNull($this->store->getSession($grandchild));
        $this->assertNotNull($this->store->getSession('branch'), 'a branch is a conversation of its own');
        $this->assertNull($this->store->getSession('branch')['parent_id'], 'and is detached, not left dangling');
        $this->assertNull($this->store->getSession('bg')['parent_id']);
    }

    public function testDeletingWithChildrenTakesEveryDescendant(): void
    {
        $this->seedTree();

        $deleted = $this->store->deleteSession('main', true);

        $this->assertCount(4, $deleted);
        foreach (['main', 'branch', 'sub', 'bg'] as $id) {
            $this->assertNull($this->store->getSession($id), "{$id} survived");
        }
        $this->assertNotNull($this->store->getSession('pinned'));
    }

    public function testDeletingAChildDropsItsTranscriptToo(): void
    {
        $this->store->createSession('main', 'p', 'm');
        $child = $this->store->createChildSession('main', SessionKind::Subagent, 'explore', null, 'p', 'm');
        $this->store->saveTranscript($child, [Message::user('look around')]);

        $this->store->deleteSession('main');

        $this->assertNull($this->store->loadTranscript($child));
    }

    public function testContinueSkipsSubagentAndArchivedSessions(): void
    {
        $this->store->createSession('main', 'p', 'm');
        $this->store->saveTranscript('main', [Message::user('my work')]);
        $this->age('main', '2020-01-01 00:00:00');

        $child = $this->store->createChildSession('main', SessionKind::Subagent, 'explore', null, 'p', 'm');
        $this->store->saveTranscript($child, [Message::user('child work')]);

        $this->store->createSession('shelved', 'p', 'm');
        $this->store->saveTranscript('shelved', [Message::user('put away')]);
        $this->store->archive('shelved');

        $this->assertSame('main', $this->store->latestResumableSession()['id'] ?? null);
    }

    public function testRetentionKeepsPinnedAndLeavesSubagentsToTheirParent(): void
    {
        $this->store->createSession('keep-parent', 'p', 'm');
        $child = $this->store->createChildSession('keep-parent', SessionKind::Subagent, 'explore', null, 'p', 'm');
        $this->store->createSession('pinned', 'p', 'm');
        $this->store->setPinned('pinned', true);
        $this->store->createSession('doomed', 'p', 'm');
        $doomedChild = $this->store->createChildSession('doomed', SessionKind::Subagent, 'explore', null, 'p', 'm');
        foreach (['keep-parent', $child, 'pinned', 'doomed', $doomedChild] as $id) {
            $this->age($id, '2000-01-01 00:00:00');
        }

        $pruned = $this->store->pruneSessions(30, 'keep-parent');

        $this->assertSame(1, $pruned, 'only the doomed root counts as a victim');
        $this->assertSame(['doomed'], array_column($this->store->pruneReport(), 'id'));
        $this->assertNotNull($this->store->getSession('pinned'), 'pinned sessions are exempt from retention');
        $this->assertNotNull($this->store->getSession($child), 'a sub-agent is never a victim in its own right');
        $this->assertNull($this->store->getSession($doomedChild), 'but it goes with its parent');
    }

    public function testEmptySweepSkipsSubagentAndPinnedRows(): void
    {
        $this->store->createSession('parent', 'p', 'm', null, 'named');
        $child = $this->store->createChildSession('parent', SessionKind::Subagent, 'explore', null, 'p', 'm');
        $this->store->createSession('pinned-empty', 'p', 'm');
        $this->store->setPinned('pinned-empty', true);
        $this->store->createSession('empty', 'p', 'm');
        foreach ([$child, 'pinned-empty', 'empty'] as $id) {
            $this->age($id, '2000-01-01 00:00:00');
        }

        $this->assertSame(1, $this->store->pruneEmptySessions());
        $this->assertNull($this->store->getSession('empty'));
        $this->assertNotNull($this->store->getSession($child));
        $this->assertNotNull($this->store->getSession('pinned-empty'));
    }

    public function testThePlainStoreServesTheSameApi(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('a', 'p', 'm');
        $child = $store->createChildSession('a', SessionKind::Background, null, null, 'p', 'm', 'bg run');

        $this->assertSame(['a'], array_map(static fn(SessionRow $r): string => $r->id, $store->listSessionsFiltered(SessionQuery::new())));
        $this->assertSame([$child], array_map(static fn(SessionRow $r): string => $r->id, $store->childrenOf('a')));
        $this->assertSame(['a' => 1], $store->childCount(['a']));
    }

    /**
     * main ─┬─ branch (kind branch)
     *       ├─ sub    (kind subagent)
     *       └─ bg     (kind background)
     * pinned (main, pinned) · archived (main, archived)
     */
    private function seedTree(): void
    {
        $this->store->createSession('main', 'p', 'm');
        $this->store->createSession('pinned', 'p', 'm');
        $this->store->setPinned('pinned', true);
        $this->store->createSession('archived', 'p', 'm');
        $this->store->archive('archived');

        $pdo = $this->pdo();
        $pdo->exec("INSERT INTO sessions (id, provider, model, kind, parent_id) VALUES ('branch', 'p', 'm', 'branch', 'main')");
        $pdo->exec("INSERT INTO sessions (id, provider, model, kind, parent_id, status) VALUES ('sub', 'p', 'm', 'subagent', 'main', 'running')");
        $pdo->exec("INSERT INTO sessions (id, provider, model, kind, parent_id, status) VALUES ('bg', 'p', 'm', 'background', 'main', 'running')");
    }

    private function age(string $id, string $stamp): void
    {
        $this->pdo()->prepare('UPDATE sessions SET updated_at = ? WHERE id = ?')->execute([$stamp, $id]);
    }

    /** A second connection to the store's file, for fixture rows the API does not write. */
    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * @param list<SessionRow> $rows
     *
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn(SessionRow $row): string => $row->id, $rows);
    }
}
