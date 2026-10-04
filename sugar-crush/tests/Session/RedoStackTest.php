<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionKind;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * Item 3.A-2: a rewind sets the checkpoints it steps over aside on a redo
 * stack (`checkpoints.undone`) instead of deleting them, records the state it
 * left as the stack's top the first time, and the next save discards the
 * stack. Plus the once-per-reason notice a refused file snapshot raises.
 *
 * @see EnhancedSessionStore::restoreCheckpoint()
 * @see EnhancedSessionStore::redoCheckpoint()
 */
final class RedoStackTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    private string $dbPath;

    private EnhancedSessionStore $store;

    private string|false $previousErrorLog = false;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/sc_redo_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        $this->dbPath = $this->dir . '/session.db';
        $this->store = new EnhancedSessionStore($this->dbPath);
        $this->store->createSession('s', 'p', 'm');
        EnhancedSessionStore::forgetAnnouncedSnapshots();
        RuntimeNoticeSink::reset();
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->dir . '/error.log');
    }

    protected function tearDown(): void
    {
        EnhancedSessionStore::forgetAnnouncedSnapshots();
        RuntimeNoticeSink::reset();
        WorkspaceCheckpointer::forgetDisabled();
        $this->previousErrorLog === false ? ini_restore('error_log') : ini_set('error_log', $this->previousErrorLog);
        $this->restoreHomeSandbox();
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        @rmdir($this->dir . '/home');
        @rmdir($this->dir);
    }

    public function testARewindSetsRowsAsideAndRecordsTheStateItLeftOnce(): void
    {
        $this->turns(4);

        $state = $this->store->restoreCheckpoint('s', 2, self::state('tip'));

        self::assertSame('turn 2', $state['inputBuf']);
        self::assertSame([1, 0], array_column($this->store->listCheckpoints('s'), 'index'), 'live readers no longer see the rows');
        self::assertNull($this->store->getCheckpoint('s', 2));
        self::assertSame(
            [[2, false], [3, false], [4, true]],
            array_map(static fn (array $row): array => [$row['index'], $row['tip']], $this->store->redoStack('s')),
        );

        // A second rewind adds rows below the stack, not a second top.
        $this->store->restoreCheckpoint('s', 0, self::state('another tip'));
        self::assertSame([0, 1, 2, 3, 4], array_column($this->store->redoStack('s'), 'index'));
        self::assertSame([], $this->store->listCheckpoints('s'));
    }

    public function testRedoStepsUpTheStackAndTheTipEndsIt(): void
    {
        $this->turns(3);
        $this->store->restoreCheckpoint('s', 1, self::state('tip'));

        $step = $this->store->redoCheckpoint('s');
        self::assertSame(2, $step['index']);
        self::assertFalse($step['tip']);
        self::assertSame('turn 2', $step['state']['inputBuf']);
        self::assertSame([1, 0], array_column($this->store->listCheckpoints('s'), 'index'));

        $last = $this->store->redoCheckpoint('s');
        self::assertTrue($last['tip']);
        self::assertSame('tip', $last['state']['inputBuf']);
        self::assertSame([2, 1, 0], array_column($this->store->listCheckpoints('s'), 'index'), 'every checkpoint is live again');
        self::assertSame([], $this->store->redoStack('s'), 'the tip row is gone');

        self::assertNull($this->store->redoCheckpoint('s'));
    }

    public function testTheNextSaveDiscardsTheStackAndItsBodies(): void
    {
        $this->turns(3);
        $this->store->restoreCheckpoint('s', 1, self::state('tip'));
        self::assertCount(3, (new PDO('sqlite:' . $this->dbPath))->query('SELECT id FROM checkpoint_blobs')->fetchAll(), 'fixture: the stack keeps every body');

        $index = $this->store->saveCheckpoint('s', self::state('new turn'));

        self::assertSame(1, $index, 'the discarded rows free their indexes');
        self::assertSame([], $this->store->redoStack('s'));
        self::assertNull($this->store->redoCheckpoint('s'));
        $payloads = (new PDO('sqlite:' . $this->dbPath))->query('SELECT payload FROM checkpoint_blobs')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['"message 0"'], $payloads, 'bodies only the stack named are collected');
    }

    public function testABranchTakenAfterARewindCanStillRedo(): void
    {
        $this->turns(2);
        $this->store->restoreCheckpoint('s', 1, self::state('tip'));

        $branch = $this->store->forkSession('s', SessionKind::Branch);

        self::assertSame([1, 2], array_column($this->store->redoStack($branch), 'index'));
        self::assertSame('tip', $this->store->redoCheckpoint($branch)['state']['inputBuf']);
        self::assertCount(2, $this->store->redoStack('s'), 'redoing the branch leaves the source alone');
    }

    public function testAnOlderDatabaseGainsTheColumnWithEveryRowLive(): void
    {
        $legacy = $this->dir . '/legacy.db';
        $pdo = new PDO('sqlite:' . $legacy);
        $pdo->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY, provider TEXT, model TEXT, created_at DATETIME, updated_at DATETIME)');
        $pdo->exec("INSERT INTO sessions (id, provider, model, created_at, updated_at) VALUES ('old', 'p', 'm', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec('CREATE TABLE checkpoints (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT NOT NULL, "index" INTEGER NOT NULL, state_data TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('INSERT INTO checkpoints (session_id, "index", state_data) VALUES (\'old\', 0, \'{"inputBuf":"kept"}\')');
        unset($pdo);

        $store = new EnhancedSessionStore($legacy);

        self::assertSame('kept', $store->getCheckpoint('old', 0)['inputBuf']);
        self::assertSame([], $store->redoStack('old'));
        $columns = array_column((new PDO('sqlite:' . $legacy))->query('PRAGMA table_info(checkpoints)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        self::assertContains('undone', $columns);
        @unlink($legacy);
    }

    public function testARefusedSnapshotIsAnnouncedOncePerDirectoryAndReason(): void
    {
        $home = $this->useHomeSandbox($this->dir . '/home');
        RuntimeNoticeSink::arm(false);

        $first = $this->store->saveCheckpoint('s', self::state('a'));
        $this->store->captureWorkspace('s', $first, $home);
        $second = $this->store->saveCheckpoint('s', self::state('b'));
        $this->store->captureWorkspace('s', $second, $home);

        $notices = RuntimeNoticeSink::drain();
        self::assertCount(1, $notices, 'a refusal that repeats every turn is said once');
        self::assertStringContainsString('Files are not being snapshotted in ' . $home . ': checkpoints are not taken in the home directory', $notices[0]);
        self::assertStringContainsString('/rewind and /undo can bring back the conversation here, not the files', $notices[0]);
    }

    /** Save $count turns' checkpoints, each with one more message. */
    private function turns(int $count): void
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = 'message ' . $i;
            $this->store->saveCheckpoint('s', ['messages' => $messages, 'inputBuf' => 'turn ' . $i]);
        }
    }

    /** @return array<string, mixed> */
    private static function state(string $draft): array
    {
        return ['messages' => ['message 0'], 'inputBuf' => $draft];
    }
}
