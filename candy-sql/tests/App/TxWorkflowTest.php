<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Lang;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-O12: the `:tx` transaction workflow on the synchronous session.
 *
 * Before this row every statement autocommitted and BEGIN…COMMIT typed as
 * plain SQL was uncoordinated even though the session was one connection.
 * These legs pin the grammar (open/commit/two-press rollback), the badge,
 * the honest failure postures (nested BEGIN surfaces; failed COMMIT keeps
 * the badge standing), the async refusal BY NAME before any dial, and the
 * law that a leading ':' never reaches history, the D-M27 gate or the
 * server. The persistence legs probe a file-backed database from a SECOND
 * connection: rolled-back rows must be absent, committed rows present.
 */
final class TxWorkflowTest extends TestCase
{
    private PDO $pdo;

    private ?string $file = null;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'candysql-tx-') . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->file);
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, label TEXT)');
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if ($this->file !== null && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    private function app(string $editorText = '', ?FakeDatabase $db = null): App
    {
        if ($db !== null) {
            return App::builder()
                ->withDb($db)
                ->withFlavor(Flavor::Sqlite)
                ->withPane(Pane::Query)
                ->withQueryEditor(TextArea::new()->setValue($editorText)->focus()[0])
                ->build();
        }

        return App::builder()
            ->withDb(new SqliteDatabase($this->pdo))
            ->withFlavor(Flavor::Sqlite)
            ->withPane(Pane::Query)
            ->withQueryEditor(TextArea::new()->setValue($editorText)->focus()[0])
            ->build();
    }

    private function pressRun(App $app): array
    {
        return $app->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
    }

    /** Type into the live model's editor the way the pane does (bracketed paste). */
    private function type(App $app, string $text): App
    {
        [$next,] = $app->update(new PasteMsg($text));
        $this->assertInstanceOf(App::class, $next);
        return $next;
    }

    /** Open a transaction on the app and return the live model. */
    private function open(App $app): App
    {
        [$opened, $cmd] = $this->pressRun($this->type($app, ':tx'));
        $this->assertNull($cmd);
        $this->assertInstanceOf(App::class, $opened);
        $this->assertTrue($opened->ui->txOpen, 'the fixture opened a transaction');
        return $opened;
    }

    /** Read table t from an INDEPENDENT connection — what actually persisted. */
    private function persistedCount(): int
    {
        $probe = new PDO('sqlite:' . (string) $this->file);
        try {
            return (int) $probe->query('SELECT COUNT(*) FROM t')->fetchColumn();
        } finally {
            $probe = null;
        }
    }

    public function testColonTxOpensTheSessionAndBadgesTheStatusLine(): void
    {
        [$opened, $cmd] = $this->pressRun($this->app(':tx'));
        $this->assertNull($cmd);
        $this->assertInstanceOf(App::class, $opened);

        $this->assertTrue($opened->ui->txOpen);
        $this->assertSame(Lang::t('tx.opened'), $opened->ui->status);
        $this->assertNull($opened->ui->error);
        $this->assertSame('', trim($opened->editor()->value()), 'the command text is consumed');
        $this->assertStringContainsString('[TX]', $opened->view(), 'the badge renders');
        $this->assertSame([], $opened->query->history, 'a colon command is not SQL history');
    }

    public function testRollbackDiscardsEveryChangeBehindTwoPresses(): void
    {
        $opened = $this->open($this->app());

        [$inserted,] = $this->pressRun($this->type($opened, "INSERT INTO t (label) VALUES ('gone')"));
        $this->assertInstanceOf(App::class, $inserted);

        // First rollback press only ARMS: same exact-text law as D-M27.
        [$armed, $armCmd] = $this->pressRun($this->type($inserted, ':tx rollback'));
        $this->assertNull($armCmd);
        $this->assertInstanceOf(App::class, $armed);
        $this->assertSame(':tx rollback', $armed->ui->armedDestructive);
        $this->assertSame(Lang::t('tx.arm_rollback'), $armed->ui->status);
        $this->assertTrue($armed->ui->txOpen, 'arming is not executing');
        $this->assertStringContainsString(':tx rollback', $armed->editor()->value(), 'the confirming press needs the text standing');

        [$rolled,] = $this->pressRun($armed);
        $this->assertInstanceOf(App::class, $rolled);
        $this->assertFalse($rolled->ui->txOpen, 'the badge retires with the transaction');
        $this->assertNull($rolled->ui->armedDestructive);
        $this->assertSame(Lang::t('tx.rolled_back'), $rolled->ui->status);

        $this->assertSame(0, $this->persistedCount(), 'nothing may survive a rollback');
    }

    public function testCommitPersistsEverythingTheTransactionCarried(): void
    {
        $opened = $this->open($this->app());
        [$inserted,] = $this->pressRun($this->type($opened, "INSERT INTO t (label) VALUES ('kept')"));
        $this->assertInstanceOf(App::class, $inserted);

        [$committed, $cmd] = $this->pressRun($this->type($inserted, ':tx commit'));
        $this->assertNull($cmd);
        $this->assertInstanceOf(App::class, $committed);
        $this->assertFalse($committed->ui->txOpen);
        $this->assertSame(Lang::t('tx.committed'), $committed->ui->status);

        $this->assertSame(1, $this->persistedCount(), 'the commit flushed the statement');
    }

    public function testCommitOrRollbackWithoutAnOpenTransactionRefusesWithoutDialing(): void
    {
        $db = new FakeDatabase();
        foreach ([':tx commit', ':tx rollback'] as $command) {
            [$next,] = $this->pressRun($this->app($command, $db));
            $this->assertInstanceOf(App::class, $next);
            $this->assertSame(Lang::t('tx.no_tx'), $next->ui->status);
            $this->assertFalse($next->ui->txOpen);
        }
        $this->assertSame([], $db->execLog(), 'a refusal may not issue COMMIT or ROLLBACK to the server');
    }

    public function testBeginWhileOpenReportsAlreadyOpenWithoutASecondDial(): void
    {
        $db = new FakeDatabase();
        $opened = $this->open($this->app('', $db));
        $this->assertSame(['BEGIN'], $db->execLog());

        [$again,] = $this->pressRun($this->type($opened, ':tx'));
        $this->assertInstanceOf(App::class, $again);
        $this->assertSame(Lang::t('tx.already_open'), $again->ui->status);
        $this->assertTrue($again->ui->txOpen);
        $this->assertSame(['BEGIN'], $db->execLog(), 'the second begin must not re-dial');
    }

    public function testNestedBeginCollisionSurfacesHonestlyAndKeepsTheBadgeClosed(): void
    {
        // The PDO session is already in a transaction the app does not know
        // about — SQLite's driver answers BEGIN with a collision error.
        $this->pdo->exec('BEGIN');

        [$next,] = $this->pressRun($this->app(':tx begin'));
        $this->assertInstanceOf(App::class, $next);
        $this->assertFalse($next->ui->txOpen, 'the badge may not open on a failed BEGIN');
        $this->assertStringStartsWith(
            Lang::t('tx.failed', ['message' => '']),
            (string) $next->ui->error,
            'the fold names the transaction-command failure'
        );
        $this->assertStringContainsString('transaction', (string) $next->ui->error, "the driver's own message rides through");
    }

    public function testFailedCommitKeepsTheBadgeStanding(): void
    {
        $db = new FakeDatabase();
        $opened = $this->open($this->app('', $db));

        $db->setExecThrows(new \PDOException('commit exploded'));
        [$next,] = $this->pressRun($this->type($opened, ':tx commit'));
        $this->assertInstanceOf(App::class, $next);
        $this->assertTrue($next->ui->txOpen, 'a failed COMMIT leaves the session inside the transaction');
        $this->assertStringContainsString('commit exploded', (string) $next->ui->error);
    }

    public function testAsyncTransportIsRefusedByNameBeforeAnyDial(): void
    {
        $db = new FakeDatabase();
        $app = App::builder()
            ->withDb($db)
            ->withFlavor(Flavor::MySQL)
            ->withPane(Pane::Query)
            ->withQueryEditor(TextArea::new()->setValue(':tx')->focus()[0])
            ->build();

        [$next, $cmd] = $this->pressRun($app);
        $this->assertNull($cmd, 'the refusal is a fold, not a dispatch');
        $this->assertInstanceOf(App::class, $next);
        $this->assertSame(Lang::t('tx.async_refusal'), $next->ui->status);
        $this->assertFalse($next->ui->txOpen);
        $this->assertSame([], $db->execLog());
    }

    public function testUnknownColonCommandNeverReachesHistoryOrServer(): void
    {
        $db = new FakeDatabase();
        [$next,] = $this->pressRun($this->app(':nope -- please', $db));
        $this->assertInstanceOf(App::class, $next);
        $this->assertSame(Lang::t('tx.unknown'), $next->ui->status);
        $this->assertSame('', trim($next->editor()->value()), 'the line is consumed');
        $this->assertSame([], $next->query->history);
        $this->assertSame(0, $db->queryCount(':nope -- please'));
        $this->assertSame([], $db->execLog());
    }

    public function testPlainRunWhileRollbackIsArmedDisarmsAndTheTransactionSurvives(): void
    {
        $opened = $this->open($this->app());
        [$armed,] = $this->pressRun($this->type($opened, ':tx rollback'));
        $this->assertInstanceOf(App::class, $armed);

        [$ran,] = $this->pressRun($this->type($armed, 'SELECT 1 AS one'));
        $this->assertInstanceOf(App::class, $ran);
        $this->assertNull($ran->ui->armedDestructive, 'another statement consumed the arm');
        $this->assertTrue($ran->ui->txOpen, 'disarming the rollback does not close the transaction');
    }
}
