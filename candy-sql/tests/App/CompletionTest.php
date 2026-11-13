<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\App;
use SugarCraft\Sql\App\BrowseState;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\App\QueryState;
use SugarCraft\Sql\App\UiState;
use SugarCraft\Sql\Completer;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Pane;

/**
 * SQL-O04 v1 (owner ruling 3a, keyword leg): Ctrl+Space in the Query pane
 * completes the word at the caret: keywords from {@see Completer::KEYWORDS},
 * and — the schema leg — table names after FROM/JOIN and columns in context
 * (alias-aware) read from the live SQLite fixture schema. The pure classifier
 * is pinned in CompleterContextTest.
 */
final class CompletionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE t1 (id INTEGER)');
    }

    private function app(string $editorText = '', Pane $pane = Pane::Query): App
    {
        return new App(
            ConnectionState::new(new SqliteDatabase($this->pdo), Flavor::Sqlite, null, ''),
            query: new QueryState(editor: TextArea::new()->setValue($editorText)->focus()[0]),
            ui: new UiState(pane: $pane),
        );
    }

    private function ctrlSpace(): KeyMsg
    {
        return new KeyMsg(KeyType::Space, ' ', ctrl: true);
    }

    public function testUniqueCandidateCompletesOutright(): void
    {
        [$a, $cmd] = $this->app('sel')->update($this->ctrlSpace());
        $this->assertNull($cmd);
        $this->assertSame('SELECT', ($a->query->editor?->value() ?? ""));
        $this->assertStringContainsString('completed sel → SELECT', (string) $a->ui->status);
    }

    public function testAmbiguousPrefixListsCandidatesWithoutTouchingText(): void
    {
        [$a, ] = $this->app('gr')->update($this->ctrlSpace());
        $this->assertSame('gr', ($a->query->editor?->value() ?? ""), 'a listing never rewrites the caret word');
        $status = (string) $a->ui->status;
        $this->assertStringContainsString('GRANT', $status);
        $this->assertStringContainsString('GROUP', $status);
    }

    public function testLongestCommonPrefixAdvancesInOnePress(): void
    {
        // CURRENT_DATE/TIME/TIMESTAMP/USER all share 'CURRENT_' — pressing
        // once on 'cur' must pay for the shared run, not stall on a list.
        [$a, ] = $this->app('cur')->update($this->ctrlSpace());
        $this->assertSame('CURRENT_', ($a->query->editor?->value() ?? ""));
    }

    public function testEmptyPrefixRefusesPolitely(): void
    {
        [$a, ] = $this->app('SELECT ')->update($this->ctrlSpace());
        $this->assertSame('SELECT ', ($a->query->editor?->value() ?? ""));
        $this->assertStringContainsString('nothing to complete', (string) $a->ui->status);
    }

    public function testUnknownWordIsAnHonestNoMatch(): void
    {
        [$a, ] = $this->app('zz')->update($this->ctrlSpace());
        $this->assertSame('zz', ($a->query->editor?->value() ?? ""));
        $this->assertStringContainsString('no keyword completes "zz"', (string) $a->ui->status);
    }

    public function testCompletionAtMidLineKeepsTheTailAndMovesOn(): void
    {
        $app = $this->app();
        [$a, ] = $app->update(new KeyMsg(KeyType::Char, 's'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'e'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'l'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, ' '));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'C'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'T'));
        // caret sits after 'CT'; walk three left to park it after 'sel'.
        for ($i = 0; $i < 3; $i++) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Left));
        }
        [$a, ] = $a->update($this->ctrlSpace());
        $this->assertSame('SELECT CT', ($a->query->editor?->value() ?? ""), 'the tail survives the swap');
        $this->assertSame(6, $a->query->editor->column(), 'caret lands after the completed word');
    }

    public function testCompletionIsAnEditForTheRecallWalk(): void
    {
        $a = $this->app('SELECT');
        [$a, ] = $a->update(new KeyMsg(KeyType::Up)); // walk onto history? history empty here
        $this->assertNull($a->query->recallIndex);
        [$a, ] = $a->update($this->ctrlSpace());
        $this->assertNull($a->query->recallIndex, 'completion never strands a recall walk');
    }

    public function testChordIsQueryPaneOnlyAndNeverStealsTablesSpace(): void
    {
        // Space loads the highlighted table in the Tables pane (SQL-O10);
        // Ctrl+Space there must stay inert, not a completion and not a load.
        [$a, $cmd] = $this->app('', Pane::Tables)->update($this->ctrlSpace());
        $this->assertNull($a->ui->status, 'no completion status leaks into the browser');
        $this->assertNull($cmd, "the chord dispatches nothing in the browser pane");
    }

    public function testStatusEchoesInTheFrame(): void
    {
        [$a, ] = $this->app('sel')->update($this->ctrlSpace());
        $this->assertStringContainsString('completed sel', $a->view());
    }

    public function testCompleterUnits(): void
    {
        $this->assertSame(['SELECT'], Completer::candidates('sel'));
        $this->assertSame(['SELECT'], Completer::candidates('SELECT'));
        $this->assertSame([], Completer::candidates(''), 'empty prefix never fires the roster');
        $this->assertEmpty(array_diff(Completer::candidates('GR'), ['GRANT', 'GROUP', 'GROUPING']));
        $this->assertSame('CURRENT_', Completer::longestCommonPrefix(['CURRENT_DATE', 'CURRENT_TIME']));
        $this->assertSame('', Completer::longestCommonPrefix([]));
        $this->assertSame('sel', Completer::wordAt(['sel CT'], 0, 3));
        $this->assertSame('', Completer::wordAt(['sel CT'], 0, 4), 'caret after a space sees no word');
        $this->assertSame('', Completer::wordAt([], 5, 2), 'no line, no word');
    }

    // ─── schema leg ──────────────────────────────────────────────────────

    private function schemaApp(string $editorText, int $caretLeft = 0): App
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS people (id INTEGER PRIMARY KEY, full_name TEXT, email TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS pets (id INTEGER PRIMARY KEY, person_id INTEGER, species TEXT)');
        $db = new SqliteDatabase($this->pdo);
        $editor = TextArea::new()->setValue($editorText)->focus()[0];
        for ($i = 0; $i < $caretLeft; $i++) {
            [$editor, ] = $editor->update(new KeyMsg(KeyType::Left));
        }
        return new App(
            ConnectionState::new($db, Flavor::Sqlite, null, ''),
            BrowseState::new()->withTables($db->tables()),
            query: new QueryState(editor: $editor),
            ui: new UiState(pane: Pane::Query),
        );
    }

    public function testTableNameCompletesAfterFrom(): void
    {
        [$a, ] = $this->schemaApp('SELECT * FROM peo')->update($this->ctrlSpace());
        $this->assertSame('SELECT * FROM people', $a->query->editor?->value());
        $this->assertStringContainsString('completed peo → people', (string) $a->ui->status);
    }

    public function testEmptyPrefixAfterFromListsTables(): void
    {
        [$a, ] = $this->schemaApp('SELECT * FROM ')->update($this->ctrlSpace());
        $this->assertSame('SELECT * FROM ', $a->query->editor?->value(), 'a listing never edits');
        $status = (string) $a->ui->status;
        $this->assertStringContainsString('people', $status);
        $this->assertStringContainsString('pets', $status);
    }

    public function testAliasColumnCompletesFromTheAliasedTable(): void
    {
        // caret parked after "p.full"
        $a = $this->schemaApp('SELECT p.full FROM people p', strlen(' FROM people p'));
        [$a, ] = $a->update($this->ctrlSpace());
        $this->assertSame('SELECT p.full_name FROM people p', $a->query->editor?->value());
    }

    public function testUnqualifiedColumnsAreScopedToTheStatementTables(): void
    {
        [$a, ] = $this->schemaApp('SELECT spec')->update($this->ctrlSpace());
        // no FROM yet -> no table scope -> keyword fallback, never a column from nowhere
        $this->assertStringContainsString('no keyword completes "spec"', (string) $a->ui->status);

        $b = $this->schemaApp('SELECT * FROM pets WHERE spec');
        [$b, ] = $b->update($this->ctrlSpace());
        $this->assertSame('SELECT * FROM pets WHERE species', $b->query->editor?->value());

        $c = $this->schemaApp('SELECT * FROM pets WHERE emai');
        [$c, ] = $c->update($this->ctrlSpace());
        $this->assertSame('SELECT * FROM pets WHERE emai', $c->query->editor?->value(), 'people.email is not in scope');
    }

    public function testAmbiguousColumnsListAndLeaveTheBufferAlone(): void
    {
        [$a, ] = $this->schemaApp('SELECT * FROM pets p JOIN people q ON p.')->update($this->ctrlSpace());
        $this->assertSame('SELECT * FROM pets p JOIN people q ON p.', $a->query->editor?->value());
        $this->assertStringContainsString('id', (string) $a->ui->status);
        $this->assertStringContainsString('species', (string) $a->ui->status);
        $this->assertStringNotContainsString('full_name', (string) $a->ui->status, 'q is people, p. is pets');
    }

    public function testUnknownQualifierAndStringContextAreNamedRefusals(): void
    {
        [$a, ] = $this->schemaApp('SELECT zz. FROM pets', strlen(' FROM pets'))->update($this->ctrlSpace());
        $this->assertStringContainsString('no column of zz completes', (string) $a->ui->status);

        [$b, ] = $this->schemaApp("SELECT 'fr")->update($this->ctrlSpace());
        $this->assertStringContainsString('inside a string or comment', (string) $b->ui->status);
        $this->assertSame("SELECT 'fr", $b->query->editor?->value());
    }

    public function testNoTablesLoadedKeepsKeywordBehaviour(): void
    {
        [$a, ] = $this->app('SELECT * FROM sel')->update($this->ctrlSpace());
        $this->assertSame('SELECT * FROM SELECT', $a->query->editor?->value());
    }
}
