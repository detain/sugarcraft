<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Database;
use SugarCraft\Sql\Pane;

/**
 * SQL-O03 end to end against a live SQLite file-less database: the Rows pane
 * edit chords (e cell / o insert / D delete), the confirmation line that
 * shows the exact statement before anything is written, persistence, and
 * every named refusal.
 */
final class CellEditTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT, note TEXT)');
        $this->pdo->exec("INSERT INTO people (name, note) VALUES ('ann', 'a'), ('bob', NULL)");
        $this->pdo->exec('CREATE TABLE loose (a TEXT, b TEXT)');
        $this->pdo->exec("INSERT INTO loose VALUES ('x', 'y')");
        $this->pdo->exec('CREATE TABLE media (id INTEGER PRIMARY KEY, img BLOB)');
        $this->pdo->exec("INSERT INTO media (img) VALUES (X'DEADBEEF')");
    }

    private function key(KeyType $t, string $rune = '', bool $ctrl = false): KeyMsg
    {
        return new KeyMsg($t, $rune, ctrl: $ctrl);
    }

    private function press(App $a, string ...$runes): App
    {
        foreach ($runes as $r) {
            [$a, ] = $a->update($this->key(KeyType::Char, $r));
        }
        return $a;
    }

    private function enter(App $a): App
    {
        [$a, ] = $a->update($this->key(KeyType::Enter));
        return $a;
    }

    /** App focused on the Rows pane of $table, preview loaded, cursor at row 0 / col 0. */
    private function rowsOf(string $table): App
    {
        $a = App::start(new Database($this->pdo));
        $idx = (int) array_search($table, $a->browse->tables, true);
        for ($i = 0; $i < $idx; $i++) {
            [$a, ] = $a->update($this->key(KeyType::Down));
        }
        $a = $this->enter($a);
        $this->assertSame($table, $a->browse->selectedTable);
        [$a, ] = $a->update($this->key(KeyType::Tab));
        $this->assertSame(Pane::Rows, $a->ui->pane);

        return $a;
    }

    private function typeText(App $a, string $text): App
    {
        foreach (mb_str_split($text) as $ch) {
            $a = $this->press($a, $ch);
        }
        return $a;
    }

    private function moveCol(App $a, int $n): App
    {
        for ($i = 0; $i < $n; $i++) {
            $a = $this->press($a, 'l');
        }
        return $a;
    }

    private function moveRow(App $a, int $n): App
    {
        for ($i = 0; $i < $n; $i++) {
            [$a, ] = $a->update($this->key(KeyType::Down));
        }
        return $a;
    }

    /** @return list<array<string,mixed>> */
    private function dump(string $table): array
    {
        return $this->pdo->query("SELECT * FROM \"$table\" ORDER BY 1")->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─── edit cell ───────────────────────────────────────────────────────

    public function testEditCellShowsTheStatementThenWritesOnConfirm(): void
    {
        $a = $this->rowsOf('people');
        $a = $this->moveCol($a, 1);               // name column
        $a = $this->press($a, 'e');
        $this->assertSame('cell', $a->edit->stage);
        $this->assertSame('ann', $a->edit->buffer, 'the editor starts from the current value');

        for ($i = 0; $i < 3; $i++) {
            [$a, ] = $a->update($this->key(KeyType::Backspace));
        }
        $a = $this->enter($this->typeText($a, "o'neil"));
        $this->assertSame('confirm', $a->edit->stage);
        $this->assertStringContainsString('UPDATE "people" SET "name" = \'o\'\'neil\' WHERE "id" = 1', $a->view());
        $this->assertSame('ann', $this->dump('people')[0]['name'], 'nothing is written before the confirming Enter');

        $a = $this->enter($a);
        $this->assertFalse($a->edit->isOpen());
        $this->assertSame("o'neil", $this->dump('people')[0]['name']);
        $this->assertSame("o'neil", $a->browse->rows[0]['name'], 'the preview is re-read');
        $this->assertStringContainsString('updated 1 row in people', (string) $a->ui->status);
        $this->assertSame(1, $a->browse->colCursor, 'the cell cursor stays put');
    }

    public function testEscapeCancelsAtEveryStageWithoutQuittingOrWriting(): void
    {
        $a = $this->moveCol($this->rowsOf('people'), 1);
        $a = $this->typeText($this->press($a, 'e'), 'zzz');
        [$a, $cmd] = $a->update($this->key(KeyType::Escape));
        $this->assertNull($cmd, 'Escape closes the edit line, it does not quit');
        $this->assertFalse($a->edit->isOpen());
        $this->assertStringContainsString('edit cancelled', (string) $a->ui->status);

        $a = $this->enter($this->typeText($this->press($a, 'e'), 'q'));
        $this->assertSame('confirm', $a->edit->stage);
        [$a, ] = $a->update($this->key(KeyType::Escape));
        $this->assertFalse($a->edit->isOpen());
        $this->assertSame('ann', $this->dump('people')[0]['name']);
    }

    public function testEditLineOwnsTheKeyboard(): void
    {
        $a = $this->moveCol($this->rowsOf('people'), 1);
        $a = $this->press($a, 'e');

        // q is a value letter, Tab does not switch panes while the line is open.
        [$a, $cmd] = $a->update($this->key(KeyType::Char, 'q'));
        $this->assertNull($cmd);
        $this->assertSame('annq', $a->edit->buffer);
        [$a, ] = $a->update($this->key(KeyType::Tab));
        $this->assertSame(Pane::Rows, $a->ui->pane);
        [$a, ] = $a->update($this->key(KeyType::Char, 'x'));
        $this->assertSame('annqx', $a->edit->buffer, 'x is a letter here, not the export chord');
        $this->assertFalse($a->io->isPromptOpen());
    }

    public function testUnchangedValueWritesNothing(): void
    {
        $a = $this->moveCol($this->rowsOf('people'), 1);
        $a = $this->enter($this->press($a, 'e'));

        $this->assertFalse($a->edit->isOpen());
        $this->assertStringContainsString('value unchanged', (string) $a->ui->status);
    }

    public function testCtrlNStoresNullAndTypingLeavesIt(): void
    {
        $a = $this->moveCol($this->rowsOf('people'), 2); // note column, row 0 = 'a'
        $a = $this->press($a, 'e');
        [$a, ] = $a->update($this->key(KeyType::Char, 'n', true));
        $this->assertTrue($a->edit->isNull);
        $a = $this->enter($a);
        $this->assertStringContainsString('SET "note" = NULL', $a->view());
        $a = $this->enter($a);
        $this->assertNull($this->dump('people')[0]['note']);

        // NULL cell: the editor opens in NULL state; typing replaces it.
        $a = $this->moveCol($this->moveRow($this->rowsOf('people'), 1), 2);
        $a = $this->press($a, 'e');
        $this->assertTrue($a->edit->isNull);
        $a = $this->enter($this->typeText($a, 'hi'));
        $a = $this->enter($a);
        $this->assertSame('hi', $this->dump('people')[1]['note']);
    }

    // ─── insert ──────────────────────────────────────────────────────────

    public function testInsertWalksColumnsAndEmptyMeansDefault(): void
    {
        $a = $this->press($this->rowsOf('people'), 'o');
        $this->assertSame('insert', $a->edit->stage);
        $this->assertStringContainsString('new row in people', $a->view());

        $a = $this->enter($a);                                   // id: empty -> DEFAULT (rowid)
        $a = $this->enter($this->typeText($a, 'cy'));            // name
        [$a, ] = $a->update($this->key(KeyType::Char, 'n', true)); // note -> NULL
        $a = $this->enter($a);
        $this->assertSame('confirm', $a->edit->stage);
        $this->assertStringContainsString('INSERT INTO "people" ("name", "note") VALUES (\'cy\', NULL)', $a->view());

        $a = $this->enter($a);
        $rows = $this->dump('people');
        $this->assertCount(3, $rows);
        $this->assertSame('cy', $rows[2]['name']);
        $this->assertNull($rows[2]['note']);
        $this->assertStringContainsString('inserted 1 row into people', (string) $a->ui->status);
        $this->assertCount(3, $a->browse->rows);
    }

    public function testInsertIntoAnEmptyTableIsAllowed(): void
    {
        $this->pdo->exec('DELETE FROM people');
        $a = $this->rowsOf('people');
        $this->assertSame([], $a->browse->rows);

        $a = $this->press($a, 'o');
        for ($i = 0; $i < 3; $i++) {
            $a = $this->enter($a);
        }
        $a = $this->enter($a); // INSERT ... DEFAULT VALUES
        $this->assertCount(1, $this->dump('people'));
    }

    // ─── delete ──────────────────────────────────────────────────────────

    public function testDeleteShowsStatementAndNeedsConfirm(): void
    {
        $a = $this->moveRow($this->rowsOf('people'), 1);
        $a = $this->press($a, 'D');
        $this->assertSame('confirm', $a->edit->stage);
        $this->assertStringContainsString('DELETE FROM "people" WHERE "id" = 2', $a->view());
        $this->assertCount(2, $this->dump('people'));

        $a = $this->enter($a);
        $this->assertSame([1], array_map('intval', array_column($this->dump('people'), 'id')));
        $this->assertStringContainsString('deleted 1 row from people', (string) $a->ui->status);
        $this->assertSame(0, $a->browse->rowCursor, 'the cursor is clamped onto the surviving row');
    }

    public function testLowercaseDDoesNotDelete(): void
    {
        $a = $this->press($this->rowsOf('people'), 'd');

        $this->assertFalse($a->edit->isOpen());
        $this->assertCount(2, $this->dump('people'));
    }

    // ─── refusals ────────────────────────────────────────────────────────

    public function testTableWithoutPrimaryKeyRefusesEveryChord(): void
    {
        foreach (['e', 'D'] as $chord) {
            $a = $this->press($this->rowsOf('loose'), $chord);
            $this->assertFalse($a->edit->isOpen(), $chord);
            $this->assertStringContainsString('no primary key', (string) $a->ui->status, $chord);
        }
        $this->assertSame([['a' => 'x', 'b' => 'y']], $this->dump('loose'));
    }

    public function testBinaryCellRefusesEditButTheKeyStaysAddressable(): void
    {
        $a = $this->moveCol($this->rowsOf('media'), 1);
        $a = $this->press($a, 'e');

        $this->assertFalse($a->edit->isOpen());
        $this->assertStringContainsString('binary or large cells', (string) $a->ui->status);
        $this->assertSame('DEADBEEF', strtoupper(bin2hex((string) $this->pdo->query('SELECT img FROM media')->fetchColumn())), 'the placeholder never overwrote the bytes');
    }

    public function testQueryResultsAreNotEditable(): void
    {
        $a = $this->rowsOf('people');
        $a = new App($a->connection, $a->browse->withSelectedTable('(query)'), $a->query, $a->ui, $a->admin, $a->io);

        foreach (['e', 'o', 'D'] as $chord) {
            $b = $this->press($a, $chord);
            $this->assertFalse($b->edit->isOpen(), $chord);
            $this->assertStringContainsString('open a table preview first', (string) $b->ui->status, $chord);
        }
    }

    public function testReadOnlySessionRefuses(): void
    {
        $a = $this->rowsOf('people')->withReadOnly(true);

        foreach (['e', 'o', 'D'] as $chord) {
            $b = $this->press($a, $chord);
            $this->assertFalse($b->edit->isOpen(), $chord);
            $this->assertStringContainsString('read-only session', (string) $b->ui->status, $chord);
        }
    }

    public function testReadOnlyDatabaseFailsGracefullyAfterRollback(): void
    {
        $a = $this->moveCol($this->rowsOf('people'), 1);
        $a = $this->enter($this->typeText($this->press($a, 'e'), 'X'));
        $this->pdo->exec('PRAGMA query_only = ON');

        $a = $this->enter($a);
        $this->assertFalse($a->edit->isOpen());
        $this->assertStringContainsString('write failed', (string) $a->ui->error);
        $this->pdo->exec('PRAGMA query_only = OFF');
        $this->assertSame('ann', $this->dump('people')[0]['name']);
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testChordsOnlyFireInTheRowsPane(): void
    {
        $a = App::start(new Database($this->pdo)); // Tables pane
        $b = $this->press($a, 'e', 'o', 'D');

        $this->assertFalse($b->edit->isOpen());
        $this->assertSame([], array_filter([$b->io->isPromptOpen()]), "'o'/'e'/'D' are inert in the Tables pane");
    }

    public function testRowGoneBeforeConfirmReportsNoChangeAndRefreshes(): void
    {
        $a = $this->press($this->moveRow($this->rowsOf('people'), 1), 'D');
        $this->assertSame('confirm', $a->edit->stage);
        $this->pdo->exec('DELETE FROM people WHERE id = 2'); // another client got there first

        $a = $this->enter($a);
        $this->assertFalse($a->edit->isOpen());
        $this->assertNull($a->ui->error, 'a vanished row is a refresh, not a failure');
        $this->assertStringContainsString('no row changed', (string) $a->ui->status);
        $this->assertCount(1, $a->browse->rows, 'the preview was re-read');
    }

    // ─── cell cursor ─────────────────────────────────────────────────────

    public function testColumnCursorMovesAndIsClampedAndHighlighted(): void
    {
        $a = $this->rowsOf('people');
        $this->assertSame(0, $a->browse->colCursor);
        $this->assertStringContainsString("\e[7m", $a->view(), 'the focused Rows pane paints a reverse-video cell cursor');

        $a = $this->moveCol($a, 9);
        $this->assertSame(2, $a->browse->colCursor, 'clamped to the last column');
        for ($i = 0; $i < 9; $i++) {
            $a = $this->press($a, 'h');
        }
        $this->assertSame(0, $a->browse->colCursor);
    }

    public function testNoCellCursorWhenAnotherPaneHasFocus(): void
    {
        $a = $this->rowsOf('people');
        [$a, ] = $a->update($this->key(KeyType::Tab)); // Query pane

        $this->assertStringNotContainsString("\e[7m", explode("\n", $a->view())[3] ?? '');
    }
}
