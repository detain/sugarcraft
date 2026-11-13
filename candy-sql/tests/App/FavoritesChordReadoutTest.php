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
 * SQL-O01b: the Ctrl+F / Ctrl+Shift+F chords were byte-identical UI —
 * save, duplicate and remove all produced zero readout, making favorites
 * unobservable mutation. Each chord now answers on the status line with a
 * count, so duplicates/removals are visible without the (still-to-come)
 * drawer pane.
 */
final class FavoritesChordReadoutTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE t1 (id INTEGER)');
    }

    /**
     * @param list<string> $favorites
     */
    private function app(string $editorText = '', array $favorites = []): App
    {
        return new App(
            ConnectionState::new(new SqliteDatabase($this->pdo), Flavor::Sqlite, null, ''),
            query: new QueryState(
                editor: TextArea::new()->setValue($editorText)->focus()[0],
                favorites: $favorites,
            ),
            ui: new UiState(pane: Pane::Query),
        );
    }

    private function save(): KeyMsg
    {
        return new KeyMsg(type: KeyType::Char, rune: 'f', ctrl: true);
    }

    private function unsave(): KeyMsg
    {
        return new KeyMsg(type: KeyType::Char, rune: 'f', ctrl: true, shift: true);
    }

    public function testFirstSaveReportsTheNewCount(): void
    {
        [$next,] = $this->app('SELECT 1')->update($this->save());
        \assert($next instanceof App);
        $this->assertSame(['SELECT 1'], $next->query->favorites);
        $this->assertSame('saved (1)', $next->ui->status);
    }

    public function testDuplicateSaveIsObservable(): void
    {
        [$saved,] = $this->app('SELECT 1')->update($this->save());
        \assert($saved instanceof App);
        [$dup,] = $saved->update($this->save());
        \assert($dup instanceof App);
        $this->assertSame(['SELECT 1'], $dup->query->favorites);
        $this->assertSame('already saved (1)', $dup->ui->status);
    }

    public function testCountUpdatesAcrossSaves(): void
    {
        [$one,] = $this->app('SELECT 1')->update($this->save());
        \assert($one instanceof App);
        [$typed,] = $one->update(new KeyMsg(type: KeyType::Backspace));
        \assert($typed instanceof App);
        [$typed2,] = $typed->update(new KeyMsg(type: KeyType::Char, rune: '2'));
        \assert($typed2 instanceof App);
        [$two,] = $typed2->update($this->save());
        \assert($two instanceof App);
        $this->assertSame(['SELECT 2', 'SELECT 1'], $two->query->favorites);
        $this->assertSame('saved (2)', $two->ui->status);
    }

    public function testRemoveReportsTheRemainingCount(): void
    {
        [$next,] = $this->app('SELECT 1', ['SELECT 1'])->update($this->unsave());
        \assert($next instanceof App);
        $this->assertSame([], $next->query->favorites);
        $this->assertSame('removed (0)', $next->ui->status);
    }

    public function testRemoveMissIsDistinguishableFromRemove(): void
    {
        [$next,] = $this->app('SELECT 9', ['SELECT 1'])->update($this->unsave());
        \assert($next instanceof App);
        $this->assertSame(['SELECT 1'], $next->query->favorites);
        $this->assertSame('not in favorites (1)', $next->ui->status);
    }

    public function testEmptyEditorSaveRefusesWithReadout(): void
    {
        [$next,] = $this->app('   ')->update($this->save());
        \assert($next instanceof App);
        $this->assertSame([], $next->query->favorites);
        $this->assertSame('nothing to save', $next->ui->status);
    }
}
