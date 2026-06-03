<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Query\App;
use SugarCraft\Query\Database;
use SugarCraft\Query\Pane;
use SugarCraft\Query\Renderer;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private function db(): Database
    {
        $db = new Database(new \PDO('sqlite::memory:'));
        $db->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
        $db->pdo->exec("INSERT INTO users (name) VALUES ('alice'), ('bob'), ('carol')");
        $db->pdo->exec("INSERT INTO posts (title) VALUES ('hello'), ('world')");
        return $db;
    }

    public function testStartListsTablesAndLoadsFirst(): void
    {
        $a = App::start($this->db());
        $this->assertSame(['posts', 'users'], $a->tables);
        $this->assertSame('posts', $a->selectedTable);
        $this->assertCount(2, $a->rows);
    }

    public function testTabCyclesPanes(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Rows, $a->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Query, $a->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        // Tab cycles through Admin as well: Tables → Rows → Query → Admin → Tables
        $this->assertSame(Pane::Admin, $a->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Tables, $a->pane);
    }

    public function testJKMovesTableCursor(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(1, $a->tableCursor);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'k'));
        $this->assertSame(0, $a->tableCursor);
    }

    public function testEnterLoadsSelectedTable(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('users', $a->selectedTable);
        $this->assertCount(3, $a->rows);
    }

    /**
     * Regression: moving the table cursor must NOT trigger a DB load. Auto-
     * loading on every Up/Down ran a synchronous query per keystroke, which
     * froze the UI for minutes when browsing a remote database.
     */
    public function testNavigationDoesNotLoadRows(): void
    {
        $a = App::start($this->db());
        // App::start loads the first table ('posts', 2 rows).
        $this->assertSame('posts', $a->selectedTable);
        $this->assertCount(2, $a->rows);

        // Move the cursor down to 'users' — selection/rows must stay put.
        [$a, ] = $a->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(1, $a->tableCursor);
        $this->assertSame('posts', $a->selectedTable, 'cursor move must not change the loaded table');
        $this->assertCount(2, $a->rows, 'cursor move must not re-query rows');

        // Only Enter loads the cursored table.
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('users', $a->selectedTable);
        $this->assertCount(3, $a->rows);
    }

    /**
     * Regression: the framework's WindowSizeMsg is the single source of truth
     * for terminal dimensions. App must forward it to the Renderer (and leave
     * the model otherwise unchanged) so layout matches the real screen.
     */
    public function testWindowSizeMsgUpdatesRendererSize(): void
    {
        $a = App::start($this->db());
        try {
            [$a2, $cmd] = $a->update(new WindowSizeMsg(123, 45));
            $this->assertNull($cmd);
            $this->assertSame($a, $a2, 'WindowSizeMsg should not mutate the model');
            $this->assertSame(['rows' => 45, 'cols' => 123], Renderer::getTerminalSize());
        } finally {
            Renderer::resetSizeCache();
        }
    }

    public function testQuitFromTablesPane(): void
    {
        $a = App::start($this->db());
        [, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
    }

    public function testQuitDoesNotFireFromQueryPane(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → rows
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        [$a2, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNull($cmd);
        // 'q' should land in the editor.
        $this->assertSame('q', $a2->editor()->value());
    }

    public function testCharsAccumulateInQueryBuffer(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        foreach (['s', 'e', 'l', 'e', 'c', 't'] as $c) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $c));
        }
        $this->assertSame('select', $a->editor()->value());
    }

    public function testCtrlREnterRunsQueryAndPopulatesRows(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        // Manually splat a SELECT into the buffer.
        $sql = 'SELECT name FROM users ORDER BY name';
        foreach (str_split($sql) as $c) {
            $msg = $c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c);
            [$a, ] = $a->update($msg);
        }
        // Ctrl+r runs.
        $msg = new KeyMsg(KeyType::Char, 'r', ctrl: true);
        [$a, ] = $a->update($msg);
        $this->assertNull($a->error);
        $this->assertCount(3, $a->rows);
        $this->assertSame('alice', $a->rows[0]['name']);
    }

    public function testInvalidQueryStashesErrorWithoutThrowing(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        foreach (str_split('NOPE') as $c) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNotNull($a->error);
    }

    public function testBackspaceDropsLastChar(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        foreach (['a','b','c'] as $c) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('ab', $a->editor()->value());
    }

    public function testRunQueryRecordsHistoryAndClearsEditor(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        // Type and run first query
        foreach (str_split('SELECT 1') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertSame(['SELECT 1'], $a->queryHistory);
        // Editor is reset after a successful run.
        $this->assertSame('', $a->editor()->value());
        // Type and run second query — newest lands at the front.
        foreach (str_split('SELECT 2') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertSame(['SELECT 2', 'SELECT 1'], $a->queryHistory);
    }

    public function testRunQueryPopulatesResultTable(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        foreach (str_split('SELECT name FROM users') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNull($a->error);
        $this->assertNotNull($a->resultTable);
        $this->assertStringContainsString('alice', $a->resultTable->render());
    }

    public function testLoadTableClearsResultTable(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → rows
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        foreach (str_split('SELECT 1') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNotNull($a->resultTable);
        // Cycle Query → Admin → Tables, then Enter loads a table, which drops
        // the query result viewer back to the sugar-table browse grid.
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → admin
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → tables
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, '')); // load selected table
        $this->assertNull($a->resultTable);
    }

    public function testEnterInsertsNewlineInEditor(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame("a\nb", $a->editor()->value());
        $this->assertSame(2, $a->editor()->lineCount());
    }

    public function testCtrlFFavoritesQuery(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        // Type a query
        foreach (str_split('SELECT 42') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        // Ctrl+F to favorite
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f', ctrl: true));
        $this->assertContains('SELECT 42', $a->queryFavorites);
    }

    public function testCtrlShiftFUnfavoritesQuery(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        // Type a query
        foreach (str_split('SELECT 42') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        // Ctrl+F to favorite
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f', ctrl: true));
        $this->assertContains('SELECT 42', $a->queryFavorites);
        // Ctrl+Shift+F to unfavorite
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f', ctrl: true, shift: true));
        $this->assertNotContains('SELECT 42', $a->queryFavorites);
    }

    public function testHistoryNotDuplicatedOnMultipleRuns(): void
    {
        $a = App::start($this->db());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → query
        // Type and run same query twice
        foreach (str_split('SELECT 1') as $c) {
            [$a, ] = $a->update($c === ' '
                ? new KeyMsg(KeyType::Space, '')
                : new KeyMsg(KeyType::Char, $c));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        // Modify and run again (add nothing - just run same)
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        // Should only have one entry
        $this->assertCount(1, $a->queryHistory);
        $this->assertSame('SELECT 1', $a->queryHistory[0]);
    }

}
