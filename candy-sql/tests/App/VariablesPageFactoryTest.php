<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\Variables\Catalog;
use SugarCraft\Sql\Admin\Variables\VariableEditor;
use SugarCraft\Sql\Admin\Variables\VariablesPage;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;
use SugarCraft\Sql\Tests\Admin\FakePostgresDatabase;

/**
 * SQL-C02 (client-002 + adminpages-030): the Variables page must actually
 * RECEIVE the Catalog and VariableEditor that App::buildVariablesPage()
 * loads — the factory used to hand them to a 1-arg VariablesPage::new() and
 * PHP silently discarded the extras, so the category tree and editability
 * died at the wire on production entry while every page-side test (which
 * used the 3-arg constructor) stayed green. The editor additionally gates
 * on flavor: SET GLOBAL/SET PERSIST with backticks is MySQL-family syntax
 * and answers 42601 on PostgreSQL, so PG contexts build the page in its
 * read-only no-edit mode.
 */
final class VariablesPageFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        AdminQueryCache::reset();
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
    }

    public function testMysqlFactoryDeliversCatalogAndEditor(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult([
            ['Variable_name' => 'max_connections', 'Value' => '151'],
            ['Variable_name' => 'innodb_buffer_pool_size', 'Value' => '134217728'],
        ]);

        $app = $this->openVariablesPane(App::start($db, Flavor::MySQL));

        // Simulate the async admin fetch landing: this clears the loading
        // gate (PageBase::view) so the factory-built page actually renders.
        [$app, ] = $app->update(new \SugarCraft\Sql\Core\Msg\AdminDataLoadedMsg(
            ['Uptime' => '100'],
            ['max_connections' => '151', 'innodb_buffer_pool_size' => '134217728', 'binlog_format' => 'ROW'],
            microtime(true),
        ));

        $page = $app->adminPage();
        $this->assertInstanceOf(VariablesPage::class, $page);

        $catalog = (new ReflectionProperty(VariablesPage::class, 'catalog'))->getValue($page);
        $editor = (new ReflectionProperty(VariablesPage::class, 'editor'))->getValue($page);

        $this->assertInstanceOf(Catalog::class, $catalog, 'the loaded catalog must reach the page');
        $this->assertNotNull($editor, 'MySQL-family contexts get the edit bridge');
        $this->assertInstanceOf(VariableEditor::class, $editor);

        // Acceptance (G1 re-derive): on a MySQL fixture context the category
        // tree RENDERS — before the fix the section answered "(no data)".
        $plain = preg_replace('/\e\[[0-9;]*m/', '', $page->view());
        $this->assertStringNotContainsString('(no data)', $plain, 'catalog-backed tree must not degrade to the empty state');
        $this->assertMatchesRegularExpression('/\bbinlog\b/', $plain, 'a real catalog group renders in the tree');
    }

    public function testPostgresFactoryGatesTheEditorToNull(): void
    {
        $db = new FakePostgresDatabase();
        $db->setQueryResult('pg_stat_database', [[
            'numbackends' => '1',
            'xact_commit' => '10',
            'xact_rollback' => '0',
            'blks_read' => '1',
            'blks_hit' => '1',
            'tup_returned' => '1',
            'tup_fetched' => '1',
            'tup_inserted' => '0',
            'tup_updated' => '0',
            'tup_deleted' => '0',
            'conflicts' => '0',
            'temp_files' => '0',
            'temp_bytes' => '0',
            'deadlocks' => '0',
            'stats_reset' => '2024-01-01 00:00:00',
        ]]);

        $app = $this->openVariablesPane(App::start($db, Flavor::Postgres));
        [$app, ] = $app->update(new \SugarCraft\Sql\Core\Msg\AdminDataLoadedMsg(
            ['Uptime' => '100'],
            ['max_connections' => '100'],
            microtime(true),
        ));

        $page = $app->adminPage();
        $this->assertInstanceOf(VariablesPage::class, $page);

        $catalog = (new ReflectionProperty(VariablesPage::class, 'catalog'))->getValue($page);
        $editor = (new ReflectionProperty(VariablesPage::class, 'editor'))->getValue($page);

        $this->assertInstanceOf(Catalog::class, $catalog, 'metadata is flavor-neutral — the tree still works');
        $this->assertNull($editor, 'adminpages-030: SET GLOBAL editor must not exist off the MySQL family');

        // The page stays fully renderable in its read-only mode.
        $this->assertNotSame('', $page->view());
    }

    private function openVariablesPane(App $app): App
    {
        // Tables → Rows → Query → Admin (the documented Tab cycle).
        [$app, ] = $app->update(new KeyMsg(KeyType::Tab, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Tab, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Admin, $app->ui->pane);

        // Admin digit 2 = Variables (Management group order: ProcessList, Variables, ...).
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, '2'));

        return $app;
    }
}
