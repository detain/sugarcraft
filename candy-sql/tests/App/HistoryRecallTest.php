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
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Pane;

/**
 * SQL-O01a: Up/Down in the Query pane recall executed statements from the
 * capped {@see QueryState::HISTORY_MAX} history into an EMPTY editor (or
 * continue walking while the editor still holds the recalled text verbatim).
 * Nothing read $history before this row — the list was write-only state.
 */
final class HistoryRecallTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE t1 (id INTEGER)');
    }

    /**
     * Direct construction (not App::builder()) so a test can seed history
     * without replaying a run for each entry.
     *
     * @param list<string> $history
     */
    private function app(array $history = [], string $editorText = ''): App
    {
        return new App(
            ConnectionState::new(new SqliteDatabase($this->pdo), Flavor::Sqlite, null, ''),
            query: new QueryState(
                editor: TextArea::new()->setValue($editorText)->focus()[0],
                history: $history,
            ),
            ui: new UiState(pane: Pane::Query),
        );
    }

    private function up(): KeyMsg
    {
        return new KeyMsg(type: KeyType::Up);
    }

    private function down(): KeyMsg
    {
        return new KeyMsg(type: KeyType::Down);
    }

    private function editorValue(App $app): string
    {
        $editor = $app->query->editor;
        $this->assertNotNull($editor);
        return $editor->value();
    }

    public function testUpRecallsIntoAnEmptyEditorWithPositionReadout(): void
    {
        [$next,] = $this->app(['SELECT 2', 'SELECT 1'])->update($this->up());
        \assert($next instanceof App);

        $this->assertSame('SELECT 2', $this->editorValue($next));
        $this->assertSame(0, $next->query->recallIndex);
        $this->assertSame('history 1/2', $next->ui->status);
    }

    public function testSyncRunClearsEditorThenUpRestagesTheJustRunStatement(): void
    {
        // The documented acceptance path: run, type junk, clear it, ↑ brings
        // the statement back into the empty editor.
        [$ran,] = $this->app([], 'SELECT 1')->update(
            new KeyMsg(type: KeyType::Char, rune: 'r', ctrl: true)
        );
        \assert($ran instanceof App);
        // The sync SQLite run clears the editor (that was the bug: the
        // recall target had REMOVED what it was meant to recall).
        $this->assertSame('', $this->editorValue($ran));
        $this->assertSame(['SELECT 1'], $ran->query->history);

        [$junked,] = $ran->update(new KeyMsg(type: KeyType::Char, rune: 'x'));
        \assert($junked instanceof App);
        [$guarding,] = $junked->update($this->up());
        \assert($guarding instanceof App);
        // The junk edit ended the walk and left 'x' in the editor; recall
        // only fires from an empty editor, so 'x' survives untouched.
        $this->assertSame('x', $this->editorValue($guarding));

        [$emptied,] = $junked->update(new KeyMsg(type: KeyType::Backspace));
        \assert($emptied instanceof App);
        [$restaged,] = $emptied->update($this->up());
        \assert($restaged instanceof App);
        $this->assertSame('SELECT 1', $this->editorValue($restaged));
        $this->assertSame('history 1/1', $restaged->ui->status);
    }

    public function testUpWalksOlderAndClampsAndDownWalksNewer(): void
    {
        $app = $this->app(['SELECT 3', 'SELECT 2', 'SELECT 1']);

        [$one,] = $app->update($this->up());
        \assert($one instanceof App);
        $this->assertSame('SELECT 3', $this->editorValue($one));

        [$two,] = $one->update($this->up());
        \assert($two instanceof App);
        $this->assertSame('SELECT 2', $this->editorValue($two));
        $this->assertSame('history 2/3', $two->ui->status);

        [$three,] = $two->update($this->up());
        \assert($three instanceof App);
        [$clamped,] = $three->update($this->up());
        \assert($clamped instanceof App);
        $this->assertSame('SELECT 1', $this->editorValue($clamped));
        $this->assertSame(2, $clamped->query->recallIndex);

        [$back,] = $clamped->update($this->down());
        \assert($back instanceof App);
        $this->assertSame('SELECT 2', $this->editorValue($back));
        $this->assertSame(1, $back->query->recallIndex);

        [$floor,] = $back->update($this->down());
        \assert($floor instanceof App);
        $this->assertSame('SELECT 3', $this->editorValue($floor));
        $this->assertSame(0, $floor->query->recallIndex);

        [$stuck,] = $floor->update($this->down());
        \assert($stuck instanceof App);
        $this->assertSame('SELECT 3', $this->editorValue($stuck));
        $this->assertSame(0, $stuck->query->recallIndex);
    }

    public function testDraftTextIsNeverClobbered(): void
    {
        [$next,] = $this->app(['SELECT 1'], 'SEL')->update($this->up());
        \assert($next instanceof App);
        // A non-empty draft with no active walk keeps Up/Down as cursor keys.
        $this->assertSame('SEL', $this->editorValue($next));
        $this->assertNull($next->query->recallIndex);
    }

    public function testEditAfterRecallEndsTheWalk(): void
    {
        [$recalled,] = $this->app(['SELECT 1'])->update($this->up());
        \assert($recalled instanceof App);

        [$typed,] = $recalled->update(new KeyMsg(type: KeyType::Char, rune: ';'));
        \assert($typed instanceof App);
        $this->assertSame('SELECT 1;', $this->editorValue($typed));
        $this->assertNull($typed->query->recallIndex);

        // Walk ended → Up is a plain cursor key again, draft survives.
        [$again,] = $typed->update($this->up());
        \assert($again instanceof App);
        $this->assertSame('SELECT 1;', $this->editorValue($again));
    }

    public function testDownFromInactiveWalkStagesTheNewestEntry(): void
    {
        [$next,] = $this->app(['SELECT 2', 'SELECT 1'])->update($this->down());
        \assert($next instanceof App);
        $this->assertSame('SELECT 2', $this->editorValue($next));
        $this->assertSame(0, $next->query->recallIndex);
    }

    public function testEmptyHistoryMakesUpAQuietNoOp(): void
    {
        [$next,] = $this->app([])->update($this->up());
        \assert($next instanceof App);
        $this->assertSame('', $this->editorValue($next));
        $this->assertNull($next->query->recallIndex);
        $this->assertNull($next->ui->status);
    }

    public function testModifiedChordsReachTheEditorNotTheWalk(): void
    {
        [$ctrlUp,] = $this->app(['SELECT 1'])->update(
            new KeyMsg(type: KeyType::Up, ctrl: true)
        );
        \assert($ctrlUp instanceof App);
        $this->assertSame('', $this->editorValue($ctrlUp));
        $this->assertNull($ctrlUp->query->recallIndex);

        [$shiftDown,] = $this->app(['SELECT 1'])->update(
            new KeyMsg(type: KeyType::Down, shift: true)
        );
        \assert($shiftDown instanceof App);
        $this->assertSame('', $this->editorValue($shiftDown));
        $this->assertNull($shiftDown->query->recallIndex);
    }
}
