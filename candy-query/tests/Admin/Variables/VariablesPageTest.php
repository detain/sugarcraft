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

    // ─── Edit dialog tests ───────────────────────────────────────────────────

    public function testEditKeyOnDynamicVariableEntersInputPhase(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();
        $editor = VariableEditor::new($this->context, $catalog);

        $this->db->setQueryResult([
            ['Variable_name' => 'max_connections', 'Value' => '100'],
        ]);

        $page = new VariablesPage($this->context, $catalog, $editor);
        $page->view(); // load variables

        $msg = new KeyMsg(KeyType::Char, 'e');
        [$newPage, ] = $page->update($msg);

        $this->assertNotSame($page, $newPage);
        $phase = $this->getPrivateField($newPage, 'editDialogPhase');
        $varName = $this->getPrivateField($newPage, 'editVarName');
        $this->assertSame('input', $phase);
        $this->assertSame('max_connections', $varName);
    }

    public function testEditKeyOnStaticVariableStaysInBrowseMode(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();
        $editor = VariableEditor::new($this->context, $catalog);

        $this->db->setQueryResult([
            ['Variable_name' => 'innodb_log_file_size', 'Value' => '5242880'],
        ]);

        $page = new VariablesPage($this->context, $catalog, $editor);
        $page->view(); // load variables

        $msg = new KeyMsg(KeyType::Char, 'e');
        [$newPage, ] = $page->update($msg);

        // Static var should NOT enter dialog — stays in browse (null phase)
        $phase = $this->getPrivateField($newPage, 'editDialogPhase');
        $this->assertNull($phase);
    }

    public function testEscapeInInputPhaseCancelsDialog(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();
        $editor = VariableEditor::new($this->context, $catalog);

        $this->db->setQueryResult([
            ['Variable_name' => 'max_connections', 'Value' => '100'],
        ]);

        $page = new VariablesPage($this->context, $catalog, $editor);
        $page->view();

        // Enter input phase
        $msg = new KeyMsg(KeyType::Char, 'e');
        [$inputPage, ] = $page->update($msg);
        $this->assertSame('input', $this->getPrivateField($inputPage, 'editDialogPhase'));

        // Press Escape — should cancel and return to browse
        $escMsg = new KeyMsg(KeyType::Escape);
        [$browsePage, ] = $inputPage->update($escMsg);

        $phase = $this->getPrivateField($browsePage, 'editDialogPhase');
        $this->assertNull($phase);
    }

    public function testEnterInInputPhaseWithChangedValueTransitionsToConfirm(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();
        $editor = VariableEditor::new($this->context, $catalog);

        $this->db->setQueryResult([
            ['Variable_name' => 'max_connections', 'Value' => '100'],
        ]);

        $page = new VariablesPage($this->context, $catalog, $editor);
        $page->view();

        // Enter input phase
        $msg = new KeyMsg(KeyType::Char, 'e');
        [$inputPage, ] = $page->update($msg);

        // Type a new value character by character
        $newPage = $inputPage;
        foreach (\str_split('200') as $ch) {
            $charMsg = new KeyMsg(KeyType::Char, $ch);
            [$newPage, ] = $newPage->update($charMsg);
        }

        $this->assertSame('200', $this->getPrivateField($newPage, 'editNewValue'));

        // Press Enter — should transition to confirm phase
        $enterMsg = new KeyMsg(KeyType::Enter);
        [$confirmPage, ] = $newPage->update($enterMsg);

        $phase = $this->getPrivateField($confirmPage, 'editDialogPhase');
        $this->assertSame('confirm', $phase);
        $this->assertSame('200', $this->getPrivateField($confirmPage, 'editNewValue'));
    }

    public function testEnterInConfirmPhaseExecutesEditAndReturnsToBrowse(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();
        $editor = VariableEditor::new($this->context, $catalog);

        $this->db->setQueryResult([
            ['Variable_name' => 'max_connections', 'Value' => '100'],
        ]);

        $page = new VariablesPage($this->context, $catalog, $editor);
        $page->view();

        // Enter input phase and type new value
        $eMsg = new KeyMsg(KeyType::Char, 'e');
        [$inputPage, ] = $page->update($eMsg);

        $newPage = $inputPage;
        foreach (\str_split('200') as $ch) {
            $charMsg = new KeyMsg(KeyType::Char, $ch);
            [$newPage, ] = $newPage->update($charMsg);
        }

        // Enter confirm phase
        $enterMsg = new KeyMsg(KeyType::Enter);
        [$confirmPage, ] = $newPage->update($enterMsg);
        $this->assertSame('confirm', $this->getPrivateField($confirmPage, 'editDialogPhase'));

        // Execute the edit
        $execMsg = new KeyMsg(KeyType::Enter);
        [$browsePage, ] = $confirmPage->update($execMsg);

        // Should be back in browse mode
        $phase = $this->getPrivateField($browsePage, 'editDialogPhase');
        $this->assertNull($phase);

        // Should have executed SET GLOBAL via editor
        $executions = $this->db->getExecutions();
        $this->assertCount(1, $executions);
        $this->assertStringContainsString('SET GLOBAL `max_connections`', $executions[0]['sql']);
        $this->assertSame(['200'], $executions[0]['values']);
    }

    public function testEnterInInputPhaseWithIdenticalValueStaysInInputPhase(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();
        $editor = VariableEditor::new($this->context, $catalog);

        $this->db->setQueryResult([
            ['Variable_name' => 'max_connections', 'Value' => '100'],
        ]);

        $page = new VariablesPage($this->context, $catalog, $editor);
        $page->view();

        // Enter input phase (editNewValue starts empty, accumulates typed chars)
        $eMsg = new KeyMsg(KeyType::Char, 'e');
        [$inputPage, ] = $page->update($eMsg);

        // Type '100' (same as current value) character by character
        $typedPage = $inputPage;
        foreach (\str_split('100') as $ch) {
            $charMsg = new KeyMsg(KeyType::Char, $ch);
            [$typedPage, ] = $typedPage->update($charMsg);
        }

        $this->assertSame('100', $this->getPrivateField($typedPage, 'editNewValue'));

        // Press Enter with identical value — should stay in input (self-write guard)
        $enterMsg = new KeyMsg(KeyType::Enter);
        [$samePage, ] = $typedPage->update($enterMsg);

        $phase = $this->getPrivateField($samePage, 'editDialogPhase');
        $this->assertSame('input', $phase);

        // No SQL should have been executed
        $this->assertCount(0, $this->db->getExecutions());
    }

    public function testError1238ShowsMessageInConfirmPhaseAndStaysInConfirm(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();
        $editor = VariableEditor::new($this->context, $catalog);

        // Use max_connections (dynamic) to enter dialog
        $this->db->setQueryResult([
            ['Variable_name' => 'max_connections', 'Value' => '100'],
        ]);

        $page = new VariablesPage($this->context, $catalog, $editor);
        $page->view();

        // Enter input phase
        $eMsg = new KeyMsg(KeyType::Char, 'e');
        [$inputPage, ] = $page->update($eMsg);
        $this->assertSame('input', $this->getPrivateField($inputPage, 'editDialogPhase'));

        // Type a new value
        $newPage = $inputPage;
        foreach (\str_split('200') as $ch) {
            $charMsg = new KeyMsg(KeyType::Char, $ch);
            [$newPage, ] = $newPage->update($charMsg);
        }

        // Enter confirm phase
        $enterMsg = new KeyMsg(KeyType::Enter);
        [$confirmPage, ] = $newPage->update($enterMsg);
        $this->assertSame('confirm', $this->getPrivateField($confirmPage, 'editDialogPhase'));

        // Simulate error 1238 when SET GLOBAL is issued (after view so SELECT succeeds)
        $this->db->setQueryThrows(new \PDOException(
            "Variable 'max_connections' is a read-only variable",
            1238
        ));

        // Execute — should get error 1238 and stay in confirm phase
        $execMsg = new KeyMsg(KeyType::Enter);
        [$errorPage, ] = $confirmPage->update($execMsg);

        $phase = $this->getPrivateField($errorPage, 'editDialogPhase');
        $this->assertSame('confirm', $phase); // stays in confirm, not browse

        $errorMsg = $this->getPrivateField($errorPage, 'editErrorMessage');
        $this->assertNotNull($errorMsg);
        $this->assertStringContainsString('1238', $errorMsg);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Read a private field from an object using reflection.
     *
     * @param object $obj
     * @param string $field
     * @return mixed
     */
    private function getPrivateField(object $obj, string $field): mixed
    {
        $reflector = new \ReflectionClass($obj);
        $prop = $reflector->getProperty($field);
        $prop->setAccessible(true);

        return $prop->getValue($obj);
    }
}
