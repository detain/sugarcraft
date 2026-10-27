<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Code\Share\ShareStore;
use SugarCraft\Code\Session\SessionStore;

/**
 * C-SES-3: the session-delete path is the share-revoke path.
 *
 * `shares` carries no foreign key to `sessions`, so before this fix a
 * deleted (or retention-pruned) session left its published pages answering
 * on `/s/{token}` for the rest of the expiry window — up to a year of live
 * transcripts for a conversation the user destroyed. The revocation rides
 * the same transaction as the row deletes, and the ruling is revoke-not-
 * delete: the row remains listable and auditable until ShareStore's own
 * sweep reaps it. These tests pin the death of the page (lookup null), the
 * survival of the marked row, the tolerance of a shares-less database, and
 * that the statement text itself is not forked into the session store.
 */
final class SessionDeleteRevokesSharesTest extends TestCase
{
    private string $dir = '';

    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/sc-sessrevoke-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o700);
        $this->dbPath = $this->dir . '/session.db';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);
    }

    public function testDeletingASessionRevokesItsLiveShares(): void
    {
        $sessions = new SessionStore($this->dbPath);
        $sessions->createSession('gone', 'p', 'm');
        $shares = ShareStore::atPath($this->dbPath);
        $record = $shares->publish('gone', 'html', '<p>secret</p>', 7);
        self::assertNotNull($shares->lookup($record->token), 'sanity: the page lives before the delete');

        $sessions->deleteSession('gone');

        self::assertNull($shares->lookup($record->token), 'a deleted session must not keep serving its page');
        // Ruled revoke-not-delete: the row survives, marked.
        self::assertSame(1, $this->countRowsWithRevocation($record->token), 'the audit trail stays listable, not erased');
    }

    public function testRetentionPruningRevokesSharesOfPrunedSessions(): void
    {
        $sessions = new SessionStore($this->dbPath);
        $sessions->createSession('ancient', 'p', 'm');
        $shares = ShareStore::atPath($this->dbPath);
        $record = $shares->publish('ancient', 'html', '<p>x</p>', 7);

        $this->ageSessions(\date('Y-m-d H:i:s', \time() - 90 * 86400));
        $pruned = $sessions->pruneSessions(30);

        self::assertSame(1, $pruned, 'the fixture really aged a prunable row');
        self::assertNull($shares->lookup($record->token), 'prune is delete by the user; the page must die with it');
    }

    public function testDeletedSubagentChildrenLoseTheirPagesAndDetachedBranchesKeepTheirs(): void
    {
        $sessions = new SessionStore($this->dbPath);
        $sessions->createSession('root', 'p', 'm');
        $sessions->createSession('worker', 'p', 'm');
        $sessions->createSession('branchy', 'p', 'm');
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->prepare("UPDATE sessions SET kind = 'subagent', parent_id = 'root' WHERE id = 'worker'")->execute();
        $pdo->prepare("UPDATE sessions SET kind = 'branch', parent_id = 'root' WHERE id = 'branchy'")->execute();
        $pdo = null;
        $shares = ShareStore::atPath($this->dbPath);
        $rootPage = $shares->publish('root', 'html', '<p>r</p>', 7);
        $workerPage = $shares->publish('worker', 'html', '<p>w</p>', 7);
        $branchPage = $shares->publish('branchy', 'html', '<p>b</p>', 7);

        $sessions->deleteSession('root');

        self::assertNull($shares->lookup($rootPage->token));
        self::assertNull($shares->lookup($workerPage->token), 'subagent children ride the tree into deletion, and their pages with it');
        self::assertNotNull($shares->lookup($branchPage->token), 'a DETACHED branch is kept, so its live page is not a dead session leak');
    }

    /**
     * The shares table only exists once a `/share serve` (or any publish)
     * has touched this database; deleting sessions from a store that never
     * saw one must not start throwing because an UPDATE names a missing
     * table.
     */
    public function testDeletionToleratesADatabaseWithoutAnySharesTable(): void
    {
        $sessions = new SessionStore($this->dbPath);
        $sessions->createSession('plain', 'p', 'm');

        $deleted = $sessions->deleteSession('plain');

        self::assertSame(['plain'], $deleted);
    }

    /**
     * The SQL lives once, in ShareStore, beside the table it writes (§ the
     * step's law): a copy pasted into SessionStore would drift from the
     * schema silently. Pinned structurally in the house source-slice
     * doctrine — the delete path must NAME the shared builder and must not
     * spell the UPDATE itself.
     */
    public function testTheSessionStoreNamesTheShareStoresOwnRevokeStatementInsteadOfRepeatingIt(): void
    {
        $method = new ReflectionMethod(SessionStore::class, 'deleteSessionTrees');
        $lines = \file((string) $method->getFileName());
        self::assertNotFalse($lines);
        $body = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString('ShareStore::revokeForSessionsSql(', $body, 'the revoke runs through the builder beside the table');

        $whole = \file_get_contents((string) (new ReflectionClass(SessionStore::class))->getFileName());
        self::assertIsString($whole);
        self::assertStringNotContainsString('UPDATE shares', $whole, 'the shares UPDATE text must have exactly one home');
    }

    /** @return int rows for $token that carry a revocation stamp */
    private function countRowsWithRevocation(string $token): int
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $statement = $pdo->prepare('SELECT COUNT(*) FROM shares WHERE token = ? AND revoked_at IS NOT NULL');
        $statement->execute([$token]);
        $count = (int) $statement->fetchColumn();
        $pdo = null;

        return $count;
    }

    /**
     * Age every session row past the retention horizon. The column is data,
     * so this is honest fixture surgery — the byte-identical sibling of
     * SessionIndexAndRetentionTest's helper of the same name.
     */
    private function ageSessions(string $timestamp): void
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->prepare('UPDATE sessions SET updated_at = ?')->execute([$timestamp]);
    }
}
