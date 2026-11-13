<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Sql\App;
use SugarCraft\Sql\App\ExportState;
use SugarCraft\Sql\Db\SqliteDatabase;

/**
 * SQL-O07: the exporters stop being a library nobody could reach. The
 * Ctrl+S / x export line writes the live result to disk (refuse-overwrite
 * armed), the i import line round-trips a CSV back into a named table, and
 * while either line is open it owns the keyboard.
 */
final class ExportImportLineTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) (sys_get_temp_dir() . '/candy-sql-o07-' . bin2hex(random_bytes(6)));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function db(): SqliteDatabase
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE zimport (id INTEGER PRIMARY KEY, title TEXT)');
        $pdo->exec("INSERT INTO posts (title) VALUES ('hello'), ('world')");
        $pdo->exec("INSERT INTO users (name) VALUES ('alice'), ('bob'), ('carol')");

        return new SqliteDatabase($pdo);
    }

    /** App::start loads 'posts' (alphabetically first) — 2 live rows. */
    private function appWithRows(): App
    {
        $a = App::start($this->db());
        $this->assertNotSame([], $a->browse->rows, 'fixture sanity: a preview is live');

        return $a;
    }

    /** @param array{App, mixed} $pair */
    private function typePath(array $pair, string $path): App
    {
        [$a, ] = $pair;
        foreach (mb_str_split($path) as $ch) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $a;
    }

    public function testExportLineWritesTheLiveResultToDisk(): void
    {
        $path = $this->dir . '/out.csv';
        $a = $this->appWithRows();

        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNull($cmd, 'opening the line is local');
        $this->assertSame(ExportState::STAGE_EXPORT_PATH, $a->io->stage);

        $a = $this->typePath([$a, null], $path);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(ExportState::STAGE_CLOSED, $a->io->stage);
        $this->assertFileExists($path);
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertStringContainsString('id', $lines[0]);
        $this->assertStringContainsString('hello', implode("\n", $lines));
        $this->assertStringContainsString('world', implode("\n", $lines));
        $this->assertSame('exported 2 rows to ' . $path, $a->ui->status);
        $this->assertCount(3, $lines, 'header + exactly the live window');
    }

    public function testExistingFileArmsRefuseOverwrite(): void
    {
        $path = $this->dir . '/exists.csv';
        file_put_contents($path, 'PRE-EXISTING');
        $a = $this->appWithRows();

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));
        $a = $this->typePath([$a, null], $path);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(ExportState::STAGE_EXPORT_OVERWRITE, $a->io->stage);
        $this->assertSame('PRE-EXISTING', file_get_contents($path), 'the first Enter must not write');

        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertStringContainsString('hello', (string) file_get_contents($path), 'the confirming Enter replaces');
        $this->assertSame(ExportState::STAGE_CLOSED, $a->io->stage);
    }

    public function testEditingThePathDisarmsTheOverwriteConfirm(): void
    {
        $path = $this->dir . '/exists2.csv';
        file_put_contents($path, 'KEEP');
        $a = $this->appWithRows();

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));
        $a = $this->typePath([$a, null], $path);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(ExportState::STAGE_EXPORT_OVERWRITE, $a->io->stage);

        // One appended letter re-opens the path stage (the armed name no
        // longer matches what the user is typing).
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'z'));
        $this->assertSame(ExportState::STAGE_EXPORT_PATH, $a->io->stage);
        $this->assertSame($path . 'z', $a->io->buffer);
        $this->assertSame('KEEP', file_get_contents($path));

        // The NEW path does not exist, so Enter writes straight through.
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertFileExists($path . 'z');
        $this->assertSame('KEEP', file_get_contents($path), 'the untouched original stayed put');
    }

    public function testTheLineOwnsTheKeyboardAndQIsAPathLetter(): void
    {
        $a = $this->appWithRows();
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));

        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNull($cmd, 'q inside the file line is text, never quit');
        $this->assertSame('q', $a->io->buffer);

        [$a, $cmd] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($cmd, 'Escape closes the line, it never quits');
        $this->assertSame(ExportState::STAGE_CLOSED, $a->io->stage);
        $this->assertSame('file line cancelled', $a->ui->status);

        [, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd, 'once the line closes, q quits again');
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testExportNeedsALiveResultAndCtrlSWorksFromQueryPane(): void
    {
        $bare = new App(new \SugarCraft\Sql\App\ConnectionState(new SqliteDatabase(new PDO('sqlite::memory:')), \SugarCraft\Sql\Db\Flavor::Sqlite));
        [$next, ] = $bare->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertFalse($next->io->isPromptOpen(), 'nothing to export means no line');

        $a = $this->appWithRows();
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, '')); // leave the Tables pane
        $this->assertNotSame(\SugarCraft\Sql\Pane::Tables, $a->ui->pane);
        [$a, ] = $a->update(new KeyMsg(type: KeyType::Char, rune: 's', ctrl: true));
        $this->assertTrue($a->io->isPromptOpen(), 'Ctrl+S raises the line from any pane');
    }

    public function testImportPickerRoundTripsTheExport(): void
    {
        $path = $this->dir . '/round.csv';
        $a = $this->appWithRows();

        // export posts
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));
        $a = $this->typePath([$a, null], $path);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertFileExists($path);

        // import into the OTHER table (users2 is not the live preview)
        $a2 = $this->appWithRows();
        [$a2, ] = $a2->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertSame(ExportState::STAGE_IMPORT_PATH, $a2->io->stage);

        // File-gated picker: a not-yet-real path does not advance.
        $ghost = '/nope/missing.csv';
        $a2 = $this->typePath([$a2, null], $ghost);
        [$a2, ] = $a2->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(ExportState::STAGE_IMPORT_PATH, $a2->io->stage, 'the picker refuses an unreadable source');
        $this->assertStringContainsString('no such file', (string) $a2->ui->status);

        // Back the typo out to the empty line, then type the real path.
        for ($i = 0; $i < strlen($ghost); $i++) {
            [$a2, ] = $a2->update(new KeyMsg(KeyType::Backspace, ''));
        }
        $this->assertSame('', $a2->io->buffer, 'backspacing the ghost path empties the line');
        $a2 = $this->typePath([$a2, null], $path);
        [$a2, ] = $a2->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(ExportState::STAGE_IMPORT_TABLE, $a2->io->stage);
        $this->assertSame('', $a2->io->buffer, 'the confirmed source moved to path, the line is empty');

        $a2 = $this->typePath([$a2, null], 'zimport');
        [$a2, ] = $a2->update(new KeyMsg(KeyType::Enter, ''));

        // Clean import into a table that is NOT the live preview: the
        // window stays as it was, the status names the round trip.
        $this->assertSame(ExportState::STAGE_CLOSED, $a2->io->stage);
        $this->assertStringContainsString('imported', (string) $a2->ui->status);
        $this->assertNull($a2->ui->error);
        $this->assertCount(2, $a2->browse->rows, 'zimport was not the on-screen table');
        $cnt = (int) $this->pdoCount($a2, 'zimport');
        $this->assertSame(2, $cnt, 'both exported rows landed in the import target');

        // Same file re-imported onto occupied primary keys: the importer's
        // transaction rolls back and the line folds the named failure.
        [$a2, ] = $a2->update(new KeyMsg(KeyType::Char, 'i'));
        $a2 = $this->typePath([$a2, null], $path);
        [$a2, ] = $a2->update(new KeyMsg(KeyType::Enter, ''));
        $a2 = $this->typePath([$a2, null], 'posts');
        [$a2, ] = $a2->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringContainsString('import failed', (string) $a2->ui->error);
        $this->assertFalse($a2->io->isPromptOpen(), 'the failure closes the line — nothing half-imported remains');
        $this->assertSame(
            ['hello', 'world'],
            $this->pdoColumn($a2, 'SELECT title FROM posts ORDER BY id'),
            'the rolled-back import changed nothing',
        );
    }

    private function pdoCount(App $a, string $table): int
    {
        return (int) $this->raw($a)->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /** @return list<string> */
    private function pdoColumn(App $a, string $sql): array
    {
        return array_map('strval', $this->raw($a)->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    private function raw(App $a): PDO
    {
        $ref = new \ReflectionProperty(SqliteDatabase::class, 'pdo');
        $ref->setAccessible(true);

        /** @var PDO $pdo */
        $pdo = $ref->getValue($a->connection->db);

        return $pdo;
    }

    public function testPromptEchoesTheOpenLineInRender(): void
    {
        $a = $this->appWithRows();
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));

        $this->assertStringContainsString('to CSV → a', $a->view());

        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertStringNotContainsString('to CSV', $a->view(), 'the prompt retires with the line');
    }
}
