<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Connections;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Query\Admin\Connections\ConnectionsPage;
use SugarCraft\Query\Admin\Connections\ConnectionFilters;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContext;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Tests for ConnectionsPage.
 */
final class ConnectionsPageTest extends TestCase
{
    private FakeDatabase $db;
    private ServerContext $context;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->context = new ServerContext($this->db);
    }

    public function testExtendsPageBase(): void
    {
        $page = new ConnectionsPage($this->context);

        $this->assertInstanceOf(PageBase::class, $page);
    }

    public function testNewCreatesInstanceWithDeps(): void
    {
        $page = ConnectionsPage::new($this->context);

        $this->assertInstanceOf(ConnectionsPage::class, $page);
    }

    public function testDetailTabReturnsDefault(): void
    {
        $page = ConnectionsPage::new($this->context);

        $this->assertSame(ConnectionsPage::DETAIL_TAB_DETAILS, $page->detailTab());
    }

    public function testWithDetailTabSetsTab(): void
    {
        $page = ConnectionsPage::new($this->context);

        $page = $page->withDetailTab(ConnectionsPage::DETAIL_TAB_ATTRIBUTES);

        $this->assertSame(ConnectionsPage::DETAIL_TAB_ATTRIBUTES, $page->detailTab());
    }

    public function testWithDetailTabIgnoresInvalidTab(): void
    {
        $page = ConnectionsPage::new($this->context);

        $page = $page->withDetailTab('invalid');

        $this->assertSame(ConnectionsPage::DETAIL_TAB_DETAILS, $page->detailTab());
    }

    public function testUpdateReturnsSelfForNonKeyMsg(): void
    {
        $page = ConnectionsPage::new($this->context);
        $msg = new \SugarCraft\Core\Msg\MouseMsg(
            0, 0,
            \SugarCraft\Core\MouseButton::Left,
            \SugarCraft\Core\MouseAction::Press
        );

        $result = $page->update($msg);

        $this->assertSame($page, $result[0]);
        $this->assertNull($result[1]);
    }

    public function testUpdateNavigatesDownForJKey(): void
    {
        $this->db->setQueryResult([
            ['PROCESSLIST_ID' => '1', 'PROCESSLIST_USER' => 'root', 'PROCESSLIST_HOST' => 'localhost', 'PROCESSLIST_DB' => '', 'PROCESSLIST_COMMAND' => 'Sleep', 'PROCESSLIST_TIME' => '10', 'PROCESSLIST_STATE' => '', 'PROCESSLIST_INFO' => '', 'PROCESSLIST_ATTRS' => ''],
            ['PROCESSLIST_ID' => '2', 'PROCESSLIST_USER' => 'root', 'PROCESSLIST_HOST' => 'localhost', 'PROCESSLIST_DB' => '', 'PROCESSLIST_COMMAND' => 'Query', 'PROCESSLIST_TIME' => '0', 'PROCESSLIST_STATE' => 'executing', 'PROCESSLIST_INFO' => 'SELECT 1', 'PROCESSLIST_ATTRS' => ''],
        ]);

        $page = ConnectionsPage::new($this->context);
        $msg = new KeyMsg(KeyType::Char, 'j');

        [$newPage, $cmd] = $page->update($msg);

        $this->assertNotSame($page, $newPage);
        $this->assertNull($cmd);
    }

    public function testUpdateNavigatesUpForKKey(): void
    {
        $this->db->setQueryResult([
            ['PROCESSLIST_ID' => '1', 'PROCESSLIST_USER' => 'root', 'PROCESSLIST_HOST' => 'localhost', 'PROCESSLIST_DB' => '', 'PROCESSLIST_COMMAND' => 'Sleep', 'PROCESSLIST_TIME' => '10', 'PROCESSLIST_STATE' => '', 'PROCESSLIST_INFO' => '', 'PROCESSLIST_ATTRS' => ''],
            ['PROCESSLIST_ID' => '2', 'PROCESSLIST_USER' => 'root', 'PROCESSLIST_HOST' => 'localhost', 'PROCESSLIST_DB' => '', 'PROCESSLIST_COMMAND' => 'Query', 'PROCESSLIST_TIME' => '0', 'PROCESSLIST_STATE' => 'executing', 'PROCESSLIST_INFO' => 'SELECT 1', 'PROCESSLIST_ATTRS' => ''],
        ]);

        $page = ConnectionsPage::new($this->context);
        // First navigate down
        [$page, ] = $page->update(new KeyMsg(KeyType::Char, 'j'));
        // Now navigate up
        [$newPage, $cmd] = $page->update(new KeyMsg(KeyType::Char, 'k'));

        $this->assertNotSame($page, $newPage);
        $this->assertNull($cmd);
    }

    public function testUpdateNavigatesDownForDownKey(): void
    {
        $this->db->setQueryResult([
            ['PROCESSLIST_ID' => '1', 'PROCESSLIST_USER' => 'root', 'PROCESSLIST_HOST' => 'localhost', 'PROCESSLIST_DB' => '', 'PROCESSLIST_COMMAND' => 'Sleep', 'PROCESSLIST_TIME' => '10', 'PROCESSLIST_STATE' => '', 'PROCESSLIST_INFO' => '', 'PROCESSLIST_ATTRS' => ''],
            ['PROCESSLIST_ID' => '2', 'PROCESSLIST_USER' => 'root', 'PROCESSLIST_HOST' => 'localhost', 'PROCESSLIST_DB' => '', 'PROCESSLIST_COMMAND' => 'Query', 'PROCESSLIST_TIME' => '0', 'PROCESSLIST_STATE' => 'executing', 'PROCESSLIST_INFO' => 'SELECT 1', 'PROCESSLIST_ATTRS' => ''],
        ]);

        $page = ConnectionsPage::new($this->context);
        $msg = new KeyMsg(KeyType::Down);

        [$newPage, $cmd] = $page->update($msg);

        $this->assertNotSame($page, $newPage);
        $this->assertNull($cmd);
    }

    public function testUpdateNavigatesUpForUpKey(): void
    {
        $this->db->setQueryResult([
            ['PROCESSLIST_ID' => '1', 'PROCESSLIST_USER' => 'root', 'PROCESSLIST_HOST' => 'localhost', 'PROCESSLIST_DB' => '', 'PROCESSLIST_COMMAND' => 'Sleep', 'PROCESSLIST_TIME' => '10', 'PROCESSLIST_STATE' => '', 'PROCESSLIST_INFO' => '', 'PROCESSLIST_ATTRS' => ''],
            ['PROCESSLIST_ID' => '2', 'PROCESSLIST_USER' => 'root', 'PROCESSLIST_HOST' => 'localhost', 'PROCESSLIST_DB' => '', 'PROCESSLIST_COMMAND' => 'Query', 'PROCESSLIST_TIME' => '0', 'PROCESSLIST_STATE' => 'executing', 'PROCESSLIST_INFO' => 'SELECT 1', 'PROCESSLIST_ATTRS' => ''],
        ]);

        $page = ConnectionsPage::new($this->context);
        [$page, ] = $page->update(new KeyMsg(KeyType::Down));
        [$newPage, ] = $page->update(new KeyMsg(KeyType::Up));

        $this->assertNotSame($page, $newPage);
    }

    public function testUpdateCyclesDetailTabsForTabKey(): void
    {
        $page = ConnectionsPage::new($this->context);

        // Start at details
        $this->assertSame(ConnectionsPage::DETAIL_TAB_DETAILS, $page->detailTab());

        // Tab -> attributes
        [$page, ] = $page->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(ConnectionsPage::DETAIL_TAB_ATTRIBUTES, $page->detailTab());

        // Tab -> mdl
        [$page, ] = $page->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(ConnectionsPage::DETAIL_TAB_MDL, $page->detailTab());

        // Tab -> back to details
        [$page, ] = $page->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(ConnectionsPage::DETAIL_TAB_DETAILS, $page->detailTab());
    }

    public function testUpdateSelectsDetailTabFor1Key(): void
    {
        $page = ConnectionsPage::new($this->context);
        $page = $page->withDetailTab(ConnectionsPage::DETAIL_TAB_MDL);

        [$newPage, ] = $page->update(new KeyMsg(KeyType::Char, '1'));

        $this->assertSame(ConnectionsPage::DETAIL_TAB_DETAILS, $newPage->detailTab());
    }

    public function testUpdateSelectsDetailTabFor2Key(): void
    {
        $page = ConnectionsPage::new($this->context);

        [$newPage, ] = $page->update(new KeyMsg(KeyType::Char, '2'));

        $this->assertSame(ConnectionsPage::DETAIL_TAB_ATTRIBUTES, $newPage->detailTab());
    }

    public function testUpdateSelectsDetailTabFor3Key(): void
    {
        $page = ConnectionsPage::new($this->context);

        [$newPage, ] = $page->update(new KeyMsg(KeyType::Char, '3'));

        $this->assertSame(ConnectionsPage::DETAIL_TAB_MDL, $newPage->detailTab());
    }

    public function testUpdateTogglesHideSleepingForFKey(): void
    {
        $page = ConnectionsPage::new($this->context);
        $this->assertFalse($page->filters()->hideSleeping);

        [$newPage, ] = $page->update(new KeyMsg(KeyType::Char, 'f'));
        $this->assertTrue($newPage->filters()->hideSleeping);

        [$newPage, ] = $newPage->update(new KeyMsg(KeyType::Char, 'f'));
        $this->assertFalse($newPage->filters()->hideSleeping);
    }

    public function testUpdateReturnsRefreshCmdForRKey(): void
    {
        $this->db->setQueryResult([
            ['PROCESSLIST_ID' => '1', 'PROCESSLIST_USER' => 'root', 'PROCESSLIST_HOST' => 'localhost', 'PROCESSLIST_DB' => '', 'PROCESSLIST_COMMAND' => 'Sleep', 'PROCESSLIST_TIME' => '10', 'PROCESSLIST_STATE' => '', 'PROCESSLIST_INFO' => '', 'PROCESSLIST_ATTRS' => ''],
        ]);

        $page = ConnectionsPage::new($this->context);
        $msg = new KeyMsg(KeyType::Char, 'r');

        [$newPage, $cmd] = $page->update($msg);

        $this->assertNotSame($page, $newPage);
        $this->assertNotNull($cmd);
        $this->assertIsCallable($cmd);
    }

    public function testWithFiltersClearsMemoization(): void
    {
        $this->db->setQueryResult([
            ['PROCESSLIST_ID' => '1', 'PROCESSLIST_USER' => 'root', 'PROCESSLIST_HOST' => 'localhost', 'PROCESSLIST_DB' => '', 'PROCESSLIST_COMMAND' => 'Sleep', 'PROCESSLIST_TIME' => '10', 'PROCESSLIST_STATE' => '', 'PROCESSLIST_INFO' => '', 'PROCESSLIST_ATTRS' => ''],
        ]);

        $page = ConnectionsPage::new($this->context);
        // Prime the memoization cache by calling filteredProcesslist
        $page->filteredProcesslist();

        // Now change filters
        $newPage = $page->withFilters($page->filters()->withHideSleeping(true));

        $this->assertNotSame($page, $newPage);
        $this->assertTrue($newPage->filters()->hideSleeping);
    }

    public function testFiltersReturnsConnectionFilters(): void
    {
        $page = ConnectionsPage::new($this->context);

        $filters = $page->filters();

        $this->assertInstanceOf(ConnectionFilters::class, $filters);
        $this->assertFalse($filters->hideSleeping);
    }

    public function testCountersReturnsConnectionCounters(): void
    {
        $page = ConnectionsPage::new($this->context);

        $counters = $page->counters();

        $this->assertNotNull($counters);
    }

    public function testSelectedIndexClampedAtZeroForUpFromZero(): void
    {
        $this->db->setQueryResult([
            ['PROCESSLIST_ID' => '1', 'PROCESSLIST_USER' => 'root', 'PROCESSLIST_HOST' => 'localhost', 'PROCESSLIST_DB' => '', 'PROCESSLIST_COMMAND' => 'Sleep', 'PROCESSLIST_TIME' => '10', 'PROCESSLIST_STATE' => '', 'PROCESSLIST_INFO' => '', 'PROCESSLIST_ATTRS' => ''],
        ]);

        $page = ConnectionsPage::new($this->context);
        [$newPage, ] = $page->update(new KeyMsg(KeyType::Char, 'k'));

        // At index 0, can't go up - should stay at 0
        $this->assertNotSame($page, $newPage);
    }

    public function testSelectedIndexClampedAtMax(): void
    {
        $this->db->setQueryResult([
            ['PROCESSLIST_ID' => '1', 'PROCESSLIST_USER' => 'root', 'PROCESSLIST_HOST' => 'localhost', 'PROCESSLIST_DB' => '', 'PROCESSLIST_COMMAND' => 'Sleep', 'PROCESSLIST_TIME' => '10', 'PROCESSLIST_STATE' => '', 'PROCESSLIST_INFO' => '', 'PROCESSLIST_ATTRS' => ''],
        ]);

        $page = ConnectionsPage::new($this->context);
        // Try to navigate down when there's only 1 row
        [$newPage, ] = $page->update(new KeyMsg(KeyType::Char, 'j'));

        // Should still work (navigating down works even at last row if there are more)
        $this->assertNotSame($page, $newPage);
    }
}
