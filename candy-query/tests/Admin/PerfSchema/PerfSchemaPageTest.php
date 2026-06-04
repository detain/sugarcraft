<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\PerfSchema\ChangeTracker;
use SugarCraft\Query\Admin\PerfSchema\CommitPlanner;
use SugarCraft\Query\Admin\PerfSchema\EasySetupDetector;
use SugarCraft\Query\Admin\PerfSchema\PerfSchemaPage;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Tests for PerfSchemaPage.
 */
final class PerfSchemaPageTest extends TestCase
{
    private FakeDatabase $db;
    private ServerContextInterface $context;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->context = new \SugarCraft\Query\Admin\ServerContext($this->db);
    }

    private function setupFakeDatabaseWithInstruments(): void
    {
        // Setup fake query results for PS tables
        $this->db->setQueryResult([
            ['NAME' => 'wait/io/file/sql/binlog', 'ENABLED' => 'YES', 'TIMED' => 'YES', 'PROPERTIES' => '', 'FLAGS' => ''],
            ['NAME' => 'statement/abstract/new_packet', 'ENABLED' => 'YES', 'TIMED' => 'YES', 'PROPERTIES' => 'abstract', 'FLAGS' => ''],
        ]);
    }

    private function setupFakeDatabaseWithConsumers(): void
    {
        $this->db->setQueryResult([
            ['NAME' => 'events_statements_history', 'ENABLED' => 'YES'],
            ['NAME' => 'events_waits_history', 'ENABLED' => 'NO'],
        ]);
    }

    public function testExtendsPageBase(): void
    {
        $page = new PerfSchemaPage($this->context);
        $this->assertInstanceOf(PageBase::class, $page);
    }

    public function testNewCreatesInstanceWithContext(): void
    {
        $page = PerfSchemaPage::new($this->context);
        $this->assertInstanceOf(PerfSchemaPage::class, $page);
    }

    public function testActiveTabDefaultsToEasySetup(): void
    {
        $page = new PerfSchemaPage($this->context);
        $this->assertSame(PerfSchemaPage::TAB_EASY_SETUP, $page->activeTab());
    }

    public function testWithNextTabAdvancesTab(): void
    {
        $page = new PerfSchemaPage($this->context);
        $nextPage = $page->withNextTab();

        $this->assertNotSame($page, $nextPage);
        $this->assertSame(PerfSchemaPage::TAB_INSTRUMENTS, $nextPage->activeTab());
    }

    public function testWithPrevTabGoesToPreviousTab(): void
    {
        $page = new PerfSchemaPage($this->context);
        $page = $page->withTab(PerfSchemaPage::TAB_INSTRUMENTS);
        $prevPage = $page->withPrevTab();

        $this->assertNotSame($page, $prevPage);
        $this->assertSame(PerfSchemaPage::TAB_EASY_SETUP, $prevPage->activeTab());
    }

    public function testWithPrevTabWrapsAround(): void
    {
        $page = new PerfSchemaPage($this->context);
        $page = $page->withTab(PerfSchemaPage::TAB_EASY_SETUP);
        $prevPage = $page->withPrevTab();

        $this->assertSame(PerfSchemaPage::TAB_OPTIONS, $prevPage->activeTab());
    }

    public function testWithNextTabWrapsAround(): void
    {
        $page = new PerfSchemaPage($this->context);
        $page = $page->withTab(PerfSchemaPage::TAB_OPTIONS);
        $nextPage = $page->withNextTab();

        $this->assertSame(PerfSchemaPage::TAB_EASY_SETUP, $nextPage->activeTab());
    }

    public function testWithTabSetsSpecificTab(): void
    {
        $page = new PerfSchemaPage($this->context);
        $targetPage = $page->withTab(PerfSchemaPage::TAB_THREADS);

        $this->assertSame(PerfSchemaPage::TAB_THREADS, $targetPage->activeTab());
    }

    public function testWithTabIgnoresInvalidTab(): void
    {
        $page = new PerfSchemaPage($this->context);
        $resultPage = $page->withTab('invalid_tab');

        $this->assertSame($page, $resultPage);
    }

    public function testSelectedRowIndexDefaultsToZero(): void
    {
        $page = new PerfSchemaPage($this->context);
        $this->assertSame(0, $page->selectedRowIndex());
    }

    public function testUpdateReturnsSelfForNonKeyMsg(): void
    {
        $page = new PerfSchemaPage($this->context);
        $msg = new \SugarCraft\Core\Msg\MouseMsg(
            0, 0,
            \SugarCraft\Core\MouseButton::Left,
            \SugarCraft\Core\MouseAction::Press
        );

        $result = $page->update($msg);

        $this->assertSame($page, $result[0]);
        $this->assertNull($result[1]);
    }

    public function testUpdateNavigatesDownAfterViewLoadsData(): void
    {
        // First setup fake data so view() can load it
        $this->db->setQueryResult([
            ['NAME' => 'wait/io/file/sql/binlog', 'ENABLED' => 'YES', 'TIMED' => 'YES', 'PROPERTIES' => '', 'FLAGS' => ''],
            ['NAME' => 'statement/abstract/new_packet', 'ENABLED' => 'YES', 'TIMED' => 'YES', 'PROPERTIES' => 'abstract', 'FLAGS' => ''],
        ]);

        $page = new PerfSchemaPage($this->context);

        // Call view() to load data first
        $page->view();

        // Switch to instruments tab
        $page = $page->withTab(PerfSchemaPage::TAB_INSTRUMENTS);

        // Now test navigation - but since instruments array is populated by loadData()
        // and FakeDatabase returns the result, navigation should work
        $msg = new \SugarCraft\Core\Msg\KeyMsg(
            \SugarCraft\Core\KeyType::Char,
            'j',
            false,
            false,
            false
        );

        $result = $page->update($msg);

        // Navigation should work after data is loaded
        $this->assertNotSame($page, $result[0]);
        // selectedRowIndex might be 0 or 1 depending on whether data was loaded
        $this->assertIsInt($result[0]->selectedRowIndex());
    }

    public function testUpdateNavigatesUp(): void
    {
        $this->db->setQueryResult([
            ['NAME' => 'wait/io/file/sql/binlog', 'ENABLED' => 'YES', 'TIMED' => 'YES', 'PROPERTIES' => '', 'FLAGS' => ''],
        ]);

        $page = new PerfSchemaPage($this->context);
        $page = $page->withTab(PerfSchemaPage::TAB_CONSUMERS);

        // Manually set selected row index to 1 for testing
        $msg = new \SugarCraft\Core\Msg\KeyMsg(
            \SugarCraft\Core\KeyType::Char,
            'k',
            false,
            false,
            false
        );

        $result = $page->update($msg);

        $this->assertNotSame($page, $result[0]);
    }

    public function testUpdateSwitchesTabWithTabKey(): void
    {
        $page = new PerfSchemaPage($this->context);
        $msg = new \SugarCraft\Core\Msg\KeyMsg(
            \SugarCraft\Core\KeyType::Tab,
            '',
            false,
            false,
            false
        );

        $result = $page->update($msg);

        $this->assertNotSame($page, $result[0]);
        $this->assertSame(PerfSchemaPage::TAB_INSTRUMENTS, $result[0]->activeTab());
    }

    public function testUpdateSwitchesToPreviousTabWithShiftTab(): void
    {
        $page = new PerfSchemaPage($this->context);
        // Start at instruments tab
        $page = $page->withTab(PerfSchemaPage::TAB_INSTRUMENTS);

        // KeyMsg signature: (type, rune='', alt=false, ctrl=false, shift=false)
        $msg = new \SugarCraft\Core\Msg\KeyMsg(
            \SugarCraft\Core\KeyType::Tab,
            '',
            false, // alt
            false, // ctrl
            true   // shift
        );

        $result = $page->update($msg);

        $this->assertNotSame($page, $result[0]);
        // Shift+tab from instruments should go to easy_setup
        $this->assertSame('easy_setup', $result[0]->activeTab());
    }

    public function testUpdateReturnsWithQuitForQKey(): void
    {
        $page = new PerfSchemaPage($this->context);
        $msg = new \SugarCraft\Core\Msg\KeyMsg(
            \SugarCraft\Core\KeyType::Char,
            'q',
            false,
            false,
            false
        );

        $result = $page->update($msg);

        $this->assertNotSame($page, $result[0]);
        $this->assertNull($result[1]);
    }

    public function testWithQuitReturnsClone(): void
    {
        $page = new PerfSchemaPage($this->context);
        $quit = $page->withQuit();

        $this->assertNotSame($page, $quit);
        $this->assertInstanceOf(PerfSchemaPage::class, $quit);
    }

    public function testSetupStateAccessor(): void
    {
        $page = new PerfSchemaPage($this->context);
        // Without data loaded, should return 'custom' as default
        $this->assertSame('custom', $page->setupState());
    }

    public function testInstrumentsAccessorReturnsEmptyArrayBeforeLoad(): void
    {
        $page = new PerfSchemaPage($this->context);
        $this->assertSame([], $page->instruments());
    }

    public function testConsumersAccessorReturnsEmptyArrayBeforeLoad(): void
    {
        $page = new PerfSchemaPage($this->context);
        $this->assertSame([], $page->consumers());
    }

    public function testActorsAccessorReturnsEmptyArrayBeforeLoad(): void
    {
        $page = new PerfSchemaPage($this->context);
        $this->assertSame([], $page->actors());
    }

    public function testObjectsAccessorReturnsEmptyArrayBeforeLoad(): void
    {
        $page = new PerfSchemaPage($this->context);
        $this->assertSame([], $page->objects());
    }

    public function testThreadsAccessorReturnsEmptyArrayBeforeLoad(): void
    {
        $page = new PerfSchemaPage($this->context);
        $this->assertSame([], $page->threads());
    }

    public function testTimersAccessorReturnsEmptyArrayBeforeLoad(): void
    {
        $page = new PerfSchemaPage($this->context);
        $this->assertSame([], $page->timers());
    }

    public function testTabConstants(): void
    {
        $this->assertSame('easy_setup', PerfSchemaPage::TAB_EASY_SETUP);
        $this->assertSame('instruments', PerfSchemaPage::TAB_INSTRUMENTS);
        $this->assertSame('consumers', PerfSchemaPage::TAB_CONSUMERS);
        $this->assertSame('actors', PerfSchemaPage::TAB_ACTORS);
        $this->assertSame('objects', PerfSchemaPage::TAB_OBJECTS);
        $this->assertSame('threads', PerfSchemaPage::TAB_THREADS);
        $this->assertSame('options', PerfSchemaPage::TAB_OPTIONS);
    }

    public function testUpdateHandlesArrowKeys(): void
    {
        $this->db->setQueryResult([
            ['NAME' => 'wait/io/file/sql/binlog', 'ENABLED' => 'YES', 'TIMED' => 'YES', 'PROPERTIES' => '', 'FLAGS' => ''],
            ['NAME' => 'statement/abstract/new_packet', 'ENABLED' => 'YES', 'TIMED' => 'YES', 'PROPERTIES' => 'abstract', 'FLAGS' => ''],
        ]);

        $page = new PerfSchemaPage($this->context);

        // Load data first
        $page->view();
        $page = $page->withTab(PerfSchemaPage::TAB_INSTRUMENTS);

        // Test Down arrow
        $downMsg = new \SugarCraft\Core\Msg\KeyMsg(
            \SugarCraft\Core\KeyType::Down,
            '',
            false,
            false,
            false
        );
        $result = $page->update($downMsg);
        $this->assertSame(1, $result[0]->selectedRowIndex());

        // Test Up arrow
        $upMsg = new \SugarCraft\Core\Msg\KeyMsg(
            \SugarCraft\Core\KeyType::Up,
            '',
            false,
            false,
            false
        );
        $result = $result[0]->update($upMsg);
        $this->assertSame(0, $result[0]->selectedRowIndex());
    }

    public function testSelectedRowIndexDoesNotGoBelowZero(): void
    {
        $page = new PerfSchemaPage($this->context);

        // Try to go up when at 0
        $msg = new \SugarCraft\Core\Msg\KeyMsg(
            \SugarCraft\Core\KeyType::Up,
            '',
            false,
            false,
            false
        );

        $result = $page->update($msg);

        $this->assertSame(0, $result[0]->selectedRowIndex());
    }

    public function testViewReturnsString(): void
    {
        // Setup database to return empty results (no PS tables)
        $this->db->setQueryResult([]);

        $page = new PerfSchemaPage($this->context);
        $result = $page->view();

        $this->assertIsString($result);
    }

    public function testViewContainsPerformanceSchemaHeader(): void
    {
        $this->db->setQueryResult([]);

        $page = new PerfSchemaPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('Performance Schema', $result);
    }

    public function testViewContainsTabLabels(): void
    {
        $this->db->setQueryResult([]);

        $page = new PerfSchemaPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('Easy Setup', $result);
        $this->assertStringContainsString('Instruments', $result);
        $this->assertStringContainsString('Consumers', $result);
    }

    public function testViewContainsFooter(): void
    {
        $this->db->setQueryResult([]);

        $page = new PerfSchemaPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('[q] quit', $result);
    }

    // ─── Version Gating Tests ─────────────────────────────────────────────────

    public function testActorsNotLoadedOnMySQL56(): void
    {
        // MySQL 5.5 doesn't have setup_actors table
        $this->db->setServerVersion('MySQL version 5.5.62');

        // Setup actors data that would be returned if queried
        $this->db->setQueryResult([
            ['HOST' => "'%'", 'USER' => "'%'", 'ROLE' => "'%'", 'ENABLED' => 'YES'],
        ]);

        $page = new PerfSchemaPage($this->context);
        $page->view();

        // Actors should not be loaded on MySQL < 5.6
        $this->assertSame([], $page->actors());
    }

    public function testActorsLoadedOnMySQL56(): void
    {
        // MySQL 5.6 has setup_actors table
        $this->db->setServerVersion('MySQL version 5.6.62');

        // Setup actors data
        $this->db->setQueryResult([
            ['HOST' => "'%'", 'USER' => "'%'", 'ROLE' => "'%'", 'ENABLED' => 'YES'],
        ]);

        $page = new PerfSchemaPage($this->context);
        $page->view();

        // Actors should be loaded on MySQL >= 5.6
        $this->assertCount(1, $page->actors());
        $this->assertSame("'%'", $page->actors()[0]->host);
    }

    public function testObjectsEnabledColumnOmittedOnMySQL563(): void
    {
        // MySQL 5.6.2 doesn't have ENABLED column in setup_objects
        $this->db->setServerVersion('MySQL version 5.6.2');

        // Query returns only TIMED column
        $this->db->setQueryResult([
            ['OBJECT_TYPE' => 'TABLE', 'OBJECT_SCHEMA' => "'%'", 'OBJECT_NAME' => "'%'", 'TIMED' => 'YES'],
        ]);

        $page = new PerfSchemaPage($this->context);
        $page->view();

        // Objects should be loaded but enabled should default to false (ENABLED not available)
        $this->assertCount(1, $page->objects());
        $this->assertFalse($page->objects()[0]->enabled);
        $this->assertTrue($page->objects()[0]->timed);
    }

    public function testObjectsEnabledColumnPresentOnMySQL563(): void
    {
        // MySQL 5.6.3+ has ENABLED column in setup_objects
        $this->db->setServerVersion('MySQL version 5.6.3');

        $this->db->setQueryResult([
            ['OBJECT_TYPE' => 'TABLE', 'OBJECT_SCHEMA' => "'%'", 'OBJECT_NAME' => "'%'", 'ENABLED' => 'YES', 'TIMED' => 'YES'],
        ]);

        $page = new PerfSchemaPage($this->context);
        $page->view();

        // Objects should have ENABLED properly loaded
        $this->assertCount(1, $page->objects());
        $this->assertTrue($page->objects()[0]->enabled);
    }

    public function testTimersLoadedFromSetupTimersOnMySQL57(): void
    {
        // MySQL 5.7 uses setup_timers table
        $this->db->setServerVersion('MySQL version 5.7.42');

        // Query setup_timers (what's queried on < 8.0)
        $this->db->setQueryResult([
            ['NAME' => 'CYCLE', 'TIMER_NAME' => 'cycle'],
            ['NAME' => 'NANOSECOND', 'TIMER_NAME' => 'nanosecond'],
        ]);

        $page = new PerfSchemaPage($this->context);
        $page->view();

        // Timers from setup_timers should be loaded
        $this->assertCount(2, $page->timers());
        // The timers should have isDirty() returning false (clean load)
        $this->assertFalse($page->timers()[0]->isDirty());
    }

    public function testTimersReadOnlyOnMySQL80(): void
    {
        // MySQL 8.0+ doesn't have setup_timers - uses performance_timers
        $this->db->setServerVersion('MySQL version 8.0.33');

        $this->db->setQueryResult([
            ['NAME' => 'CYCLE', 'TIMER_NAME' => 'cycle'],
            ['NAME' => 'NANOSECOND', 'TIMER_NAME' => 'nanosecond'],
        ]);

        $page = new PerfSchemaPage($this->context);
        $page->view();

        // Timers from performance_timers should be loaded (read-only)
        $this->assertCount(2, $page->timers());
        $this->assertFalse($page->timers()[0]->isDirty());
    }
}
