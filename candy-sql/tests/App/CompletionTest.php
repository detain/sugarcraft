<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\App;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\App\QueryState;
use SugarCraft\Sql\App\UiState;
use SugarCraft\Sql\Completer;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Pane;

/**
 * SQL-O04 v1 (owner ruling 3a, keyword leg): Ctrl+Space in the Query pane
 * completes the word at the caret against {@see Completer::KEYWORDS}. The
 * schema leg (tables after FROM/JOIN, columns in context) is deferred and
 * disclosed — these pins never claim it.
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
}
