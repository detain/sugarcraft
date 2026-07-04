<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\AdminPane;
use SugarCraft\Query\App;
use SugarCraft\Query\App\AdminState;
use SugarCraft\Query\App\BrowseState;
use SugarCraft\Query\App\ConnectionState;
use SugarCraft\Query\App\QueryState;
use SugarCraft\Query\App\UiState;
use SugarCraft\Query\Database;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Pane;

/**
 * Guards the plan-3.3 refactor: App's former ~29-parameter constructor is now
 * five cohesive value objects. Each group must default sensibly, stay
 * immutable through its with*() methods, and compose into a working App.
 */
final class StateObjectsTest extends TestCase
{
    public function testAppConstructorTakesExactlyTheFiveStateGroups(): void
    {
        $params = (new \ReflectionClass(App::class))->getConstructor()->getParameters();
        $names = array_map(static fn (\ReflectionParameter $p) => $p->getName(), $params);

        $this->assertSame(['connection', 'browse', 'query', 'ui', 'admin'], $names);
        // Every group after the required connection is optional.
        foreach (array_slice($params, 1) as $param) {
            $this->assertTrue($param->isDefaultValueAvailable(), $param->getName());
        }
    }

    public function testBrowseStateDefaultsAndImmutability(): void
    {
        $s = BrowseState::new();
        $this->assertSame([], $s->tables);
        $this->assertSame(0, $s->tableCursor);
        $this->assertNull($s->selectedTable);
        $this->assertSame([], $s->rows);
        $this->assertSame(0, $s->rowCursor);
        $this->assertNull($s->resultTable);
        $this->assertFalse($s->rowsLoading);

        $s2 = $s->withTables(['users'])->withSelectedTable('users')->withRowsLoading(true);
        $this->assertNotSame($s, $s2);
        $this->assertSame(['users'], $s2->tables);
        $this->assertSame('users', $s2->selectedTable);
        $this->assertTrue($s2->rowsLoading);
        $this->assertSame([], $s->tables, 'original untouched');
    }

    public function testQueryStateDefaultsAndImmutability(): void
    {
        $s = QueryState::new();
        $this->assertNull($s->editor);
        $this->assertSame([], $s->history);
        $this->assertSame([], $s->favorites);

        $s2 = $s->withHistory(['SELECT 1'])->withFavorites(['SELECT 2']);
        $this->assertNotSame($s, $s2);
        $this->assertSame(['SELECT 1'], $s2->history);
        $this->assertSame(['SELECT 2'], $s2->favorites);
        $this->assertSame([], $s->history, 'original untouched');
    }

    public function testUiStateDefaultsAndImmutability(): void
    {
        $s = UiState::new();
        $this->assertSame(Pane::Tables, $s->pane);
        $this->assertNull($s->error);
        $this->assertNull($s->status);

        $s2 = $s->withPane(Pane::Query)->withError('boom')->withStatus('3 rows');
        $this->assertNotSame($s, $s2);
        $this->assertSame(Pane::Query, $s2->pane);
        $this->assertSame('boom', $s2->error);
        $this->assertSame('3 rows', $s2->status);
        $this->assertSame(Pane::Tables, $s->pane, 'original untouched');
    }

    public function testAdminStateDefaultsAndPaneChangeResetsPage(): void
    {
        $s = AdminState::new();
        $this->assertSame(AdminPane::ProcessList, $s->pane);
        $this->assertSame(0, $s->cursor);
        $this->assertFalse($s->paused);
        $this->assertNull($s->page);
        $this->assertNull($s->cachedStatusVars);
        $this->assertNull($s->cachedServerVars);
        $this->assertSame(0.0, $s->cacheTs);
        $this->assertFalse($s->loading);
        $this->assertSame(0.0, $s->lastFetchAt);
        $this->assertNull($s->historyRecorder);

        // Selecting a pane is the ONLY transition that drops the lazily-built
        // page; loading ticks must preserve it (state-survival regression).
        $s2 = $s->withPane(AdminPane::Dashboard);
        $this->assertSame(AdminPane::Dashboard, $s2->pane);
        $this->assertNull($s2->page);

        $s3 = $s->withCachedData(['Uptime' => '1'], ['max_connections' => '100'], 12.5);
        $this->assertSame(['Uptime' => '1'], $s3->cachedStatusVars);
        $this->assertSame(['max_connections' => '100'], $s3->cachedServerVars);
        $this->assertSame(12.5, $s3->cacheTs);
        $this->assertFalse($s3->loading, 'cached data arrival clears loading');
    }

    public function testConnectionStateCarriesDbFlavorAndContext(): void
    {
        $db = new Database(new \PDO('sqlite::memory:'));
        $s = ConnectionState::new($db);

        $this->assertSame($db, $s->db);
        $this->assertSame(Flavor::Sqlite, $s->flavor);
        $this->assertNull($s->serverContext);
    }

    /** End-to-end smoke: groups compose into a working, renderable App. */
    public function testAppStartComposesTheGroupsEndToEnd(): void
    {
        $db = new Database(new \PDO('sqlite::memory:'));
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec("INSERT INTO users (name) VALUES ('alice')");

        $a = App::start($db);

        $this->assertSame(['users'], $a->browse->tables);
        $this->assertSame('users', $a->browse->selectedTable);
        $this->assertCount(1, $a->browse->rows);
        $this->assertSame(Pane::Tables, $a->ui->pane);
        $this->assertSame(AdminPane::ProcessList, $a->admin->pane);
        $this->assertSame(Flavor::Sqlite, $a->connection->flavor);
        $this->assertStringContainsString('users', $a->view());
    }

    /** Guards plan 3.4: the Async* renames are clean — no BC aliases kept. */
    public function testCachingWrappersCarryTheirAsyncNames(): void
    {
        $this->assertTrue(class_exists(\SugarCraft\Query\Admin\AsyncCachedConnection::class));
        $this->assertTrue(class_exists(\SugarCraft\Query\Admin\AsyncCachingServerContext::class));
        $this->assertFalse(class_exists(\SugarCraft\Query\Admin\CachedConnection::class), 'old name gone (pre-1.0, no alias)');
        $this->assertFalse(class_exists(\SugarCraft\Query\Admin\CachingServerContext::class), 'old name gone (pre-1.0, no alias)');
    }
}
