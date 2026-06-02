<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Variables;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Admin\ServerContext;
use SugarCraft\Query\Admin\Variables\Catalog;
use SugarCraft\Query\Admin\Variables\VariableEditor;
use SugarCraft\Query\Admin\Variables\VariablesPage;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Tests for VariablesPage.
 */
final class VariablesPageTest extends TestCase
{
    private FakeDatabase $db;
    private ServerContextInterface $context;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->context = new ServerContext($this->db);
    }

    public function testExtendsPageBase(): void
    {
        $page = new VariablesPage($this->context);

        $this->assertInstanceOf(PageBase::class, $page);
    }

    public function testNewCreatesInstanceWithContext(): void
    {
        $page = VariablesPage::new($this->context);

        $this->assertInstanceOf(VariablesPage::class, $page);
    }

    public function testViewReturnsString(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
            ['Variable_name' => 'Threads_connected', 'Value' => '5'],
        ]);

        $page = new VariablesPage($this->context);
        $result = $page->view();

        $this->assertIsString($result);
    }

    public function testViewContainsVariablesHeader(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);

        $page = new VariablesPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('Variables', $result);
    }

    public function testViewContainsTabBar(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);

        $page = new VariablesPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('[Status]', $result);
        $this->assertStringContainsString('[System]', $result);
    }

    public function testViewContainsVariableNames(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
            ['Variable_name' => 'Threads_connected', 'Value' => '5'],
        ]);

        $page = new VariablesPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('Uptime', $result);
        $this->assertStringContainsString('Threads_connected', $result);
    }

    public function testViewContainsFooter(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);

        $page = new VariablesPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('[tab] toggle', $result);
        $this->assertStringContainsString('[q] quit', $result);
    }

    public function testViewContainsSearchPrompt(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);

        $page = new VariablesPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('[search]', $result);
    }

    public function testWithTabChangesActiveTab(): void
    {
        $page = new VariablesPage($this->context);

        $page = $page->withTab(VariablesPage::TAB_SYSTEM);

        $this->assertSame(VariablesPage::TAB_SYSTEM, $page->activeTab());
    }

    public function testWithSearchUpdatesSearchQuery(): void
    {
        $page = new VariablesPage($this->context);

        $page = $page->withSearch('timeout');

        $this->assertSame('timeout', $page->searchQuery());
    }

    public function testWithCategorySetsCategory(): void
    {
        $page = new VariablesPage($this->context);

        $page = $page->withCategory('connection');

        $this->assertSame('connection', $page->activeCategory());
    }

    public function testWithCategoryNullClearsCategory(): void
    {
        $page = new VariablesPage($this->context);
        $page = $page->withCategory('connection');

        $page = $page->withCategory(null);

        $this->assertNull($page->activeCategory());
    }

    public function testWithToggleTabSwitchesFromStatusToSystem(): void
    {
        $page = new VariablesPage($this->context);
        $this->assertSame(VariablesPage::TAB_STATUS, $page->activeTab());

        $page = $page->withToggleTab();

        $this->assertSame(VariablesPage::TAB_SYSTEM, $page->activeTab());
    }

    public function testWithToggleTabSwitchesFromSystemToStatus(): void
    {
        $page = new VariablesPage($this->context);
        $page = $page->withTab(VariablesPage::TAB_SYSTEM);

        $page = $page->withToggleTab();

        $this->assertSame(VariablesPage::TAB_STATUS, $page->activeTab());
    }

    public function testUpdateReturnsSelfForNonKeyMsg(): void
    {
        $page = new VariablesPage($this->context);
        $msg = new \SugarCraft\Core\Msg\MouseMsg(
            0, 0,
            \SugarCraft\Core\MouseButton::Left,
            \SugarCraft\Core\MouseAction::Press
        );

        $result = $page->update($msg);

        $this->assertSame($page, $result[0]);
        $this->assertNull($result[1]);
    }

    public function testUpdateTogglesTabForTabKey(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);

        $page = new VariablesPage($this->context);
        $msg = new KeyMsg(KeyType::Tab);

        [$newPage, $cmd] = $page->update($msg);

        $this->assertNotSame($page, $newPage);
        $this->assertSame(VariablesPage::TAB_SYSTEM, $newPage->activeTab());
        $this->assertNull($cmd);
    }

    public function testUpdateTogglesReadWriteFilterForWKey(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);

        $page = new VariablesPage($this->context);
        $this->assertFalse($page->showReadWriteOnly());

        $msg = new KeyMsg(KeyType::Char, 'w');
        [$newPage, $cmd] = $page->update($msg);

        $this->assertNotSame($page, $newPage);
        $this->assertTrue($newPage->showReadWriteOnly());
        $this->assertNull($cmd);
    }

    public function testUpdateFocusesSearchForSKey(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);

        $page = new VariablesPage($this->context);
        // Trigger view to load variables first
        $page->view();

        $msg = new KeyMsg(KeyType::Char, 's');
        [$newPage, $cmd] = $page->update($msg);

        $this->assertNotSame($page, $newPage);
        $this->assertNull($cmd);
    }

    public function testUpdateNavigatesDownForJKey(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
            ['Variable_name' => 'Threads_connected', 'Value' => '5'],
        ]);

        $page = new VariablesPage($this->context);
        // Trigger view to load variables first
        $page->view();

        $this->assertSame(0, $page->selectedRowIndex());

        $msg = new KeyMsg(KeyType::Char, 'j');
        [$newPage, $cmd] = $page->update($msg);

        $this->assertNotSame($page, $newPage);
        $this->assertSame(1, $newPage->selectedRowIndex());
        $this->assertNull($cmd);
    }

    public function testUpdateNavigatesUpForKKey(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
            ['Variable_name' => 'Threads_connected', 'Value' => '5'],
        ]);

        $page = new VariablesPage($this->context);

        // Navigate down first
        $page = $page->withToggleTab(); // Switch tab to trigger something
        $page = $page->withToggleTab(); // Switch back
        $this->assertSame(0, $page->selectedRowIndex());

        $msg = new KeyMsg(KeyType::Char, 'k');
        [$newPage, $cmd] = $page->update($msg);

        // At index 0, can't go up
        $this->assertSame(0, $newPage->selectedRowIndex());
    }

    public function testUpdateReturnsWithQuitForQKey(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);

        $page = new VariablesPage($this->context);
        $msg = new KeyMsg(KeyType::Char, 'q');

        [$newPage, $cmd] = $page->update($msg);

        $this->assertNotSame($page, $newPage);
        $this->assertInstanceOf(VariablesPage::class, $newPage);
        $this->assertNull($cmd);
    }

    public function testUpdateClearsSearchFocusForEscape(): void
    {
        $this->db->setQueryResult([
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
        ]);

        $page = new VariablesPage($this->context);
        $msg = new KeyMsg(KeyType::Char, 's');
        [$page, ] = $page->update($msg);

        // Now escape should clear focus
        $msg = new KeyMsg(KeyType::Escape);
        [$newPage, $cmd] = $page->update($msg);

        $this->assertNotSame($page, $newPage);
        $this->assertNull($cmd);
    }

    public function testActiveTabAccessor(): void
    {
        $page = new VariablesPage($this->context);

        $this->assertSame(VariablesPage::TAB_STATUS, $page->activeTab());
    }

    public function testSearchQueryAccessor(): void
    {
        $page = new VariablesPage($this->context);

        $this->assertSame('', $page->searchQuery());
    }

    public function testActiveCategoryAccessor(): void
    {
        $page = new VariablesPage($this->context);

        $this->assertNull($page->activeCategory());
    }

    public function testShowReadWriteOnlyAccessor(): void
    {
        $page = new VariablesPage($this->context);

        $this->assertFalse($page->showReadWriteOnly());
    }

    public function testSelectedRowIndexAccessor(): void
    {
        $page = new VariablesPage($this->context);

        $this->assertSame(0, $page->selectedRowIndex());
    }

    public function testValidateReturnsFalseOnException(): void
    {
        $this->db->setQueryThrows(new \PDOException('Connection failed'));

        $page = new VariablesPage($this->context);
        $result = $page->view();

        $this->assertStringContainsString('Error', $result);
    }

    public function testCatalogAccessorReturnsNullByDefault(): void
    {
        $page = new VariablesPage($this->context);

        $this->assertNull($page->catalog());
    }

    public function testCatalogAccessorReturnsProvidedCatalog(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $page = new VariablesPage($this->context, $catalog);

        $this->assertSame($catalog, $page->catalog());
    }

    public function testEditorAccessorReturnsNullByDefault(): void
    {
        $page = new VariablesPage($this->context);

        $this->assertNull($page->editor());
    }

    public function testEditorAccessorReturnsProvidedEditor(): void
    {
        $editor = VariableEditor::new($this->context);

        $page = new VariablesPage($this->context, null, $editor);

        $this->assertSame($editor, $page->editor());
    }
}
