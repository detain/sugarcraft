<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Variables;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Admin\Variables\Catalog;
use SugarCraft\Query\Admin\Variables\VariableEditor;
use SugarCraft\Query\Tests\Admin\FakeDatabase;

/**
 * Tests for VariableEditor.
 */
final class VariableEditorTest extends TestCase
{
    private FakeDatabase $db;
    private ServerContextInterface $context;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->context = new \SugarCraft\Query\Admin\ServerContext($this->db);
    }

    public function testNewCreatesInstance(): void
    {
        $editor = VariableEditor::new($this->context);

        $this->assertInstanceOf(VariableEditor::class, $editor);
    }

    public function testIsEditableReturnsFalseWhenNoCatalog(): void
    {
        $editor = VariableEditor::new($this->context);

        $result = $editor->isEditable('max_connections');

        $this->assertFalse($result);
    }

    public function testIsEditableReturnsTrueForEditableVariable(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->isEditable('max_connections');

        $this->assertTrue($result);
    }

    public function testIsEditableReturnsFalseForNonEditableVariable(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->isEditable('system_time_zone');

        $this->assertFalse($result);
    }

    public function testIsEditableReturnsFalseForNonexistentVariable(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->isEditable('nonexistent_variable_xyz');

        $this->assertFalse($result);
    }

    public function testGetEditPreviewReturnsCorrectFormat(): void
    {
        $editor = VariableEditor::new($this->context);

        $preview = $editor->getEditPreview('max_connections', '100', 'global');

        $this->assertStringContainsString('SET GLOBAL', $preview);
        $this->assertStringContainsString('`max_connections`', $preview);
        $this->assertStringContainsString('100', $preview);
    }

    public function testGetEditPreviewWithPersistMode(): void
    {
        $editor = VariableEditor::new($this->context);

        $preview = $editor->getEditPreview('max_connections', '100', 'persist');

        $this->assertStringContainsString('SET PERSIST', $preview);
        $this->assertStringContainsString('`max_connections`', $preview);
    }

    public function testGetEditPreviewWithPersistOnlyMode(): void
    {
        $editor = VariableEditor::new($this->context);

        $preview = $editor->getEditPreview('max_connections', '100', 'persist_only');

        $this->assertStringContainsString('SET PERSIST_ONLY', $preview);
        $this->assertStringContainsString('`max_connections`', $preview);
    }

    public function testGetEditPreviewAddsQuotesForStringValue(): void
    {
        $editor = VariableEditor::new($this->context);

        $preview = $editor->getEditPreview('wait_timeout', '28800', 'global');

        // wait_timeout is numeric, should not be quoted
        $this->assertStringContainsString(' = 28800', $preview);
    }

    public function testGetEditPreviewAddsQuotesForNonNumericValue(): void
    {
        $editor = VariableEditor::new($this->context);

        $preview = $editor->getEditPreview('character_set_client', 'utf8mb4', 'global');

        // String values should be quoted
        $this->assertStringContainsString("'utf8mb4'", $preview);
    }

    public function testLastErrorReturnsNullByDefault(): void
    {
        $editor = VariableEditor::new($this->context);

        $this->assertNull($editor->lastError());
    }

    public function testIsPrivilegeErrorReturnsFalseByDefault(): void
    {
        $editor = VariableEditor::new($this->context);

        $this->assertFalse($editor->isPrivilegeError());
    }

    public function testIsPersistedVariablesErrorReturnsFalseByDefault(): void
    {
        $editor = VariableEditor::new($this->context);

        $this->assertFalse($editor->isPersistedVariablesError());
    }

    public function testIsEditableWithCatalogLoadFailure(): void
    {
        $catalog = Catalog::new('/nonexistent/path');
        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->isEditable('max_connections');

        $this->assertFalse($result);
    }

    // ─── Edit method tests ───────────────────────────────────────────────────

    public function testEditReturnsSuccessResultWhenVariableIsEditable(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->edit('max_connections', '200');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('errorCode', $result);
        $this->assertArrayHasKey('errorMessage', $result);
        $this->assertTrue($result['success']);
        $this->assertNull($result['errorCode']);
        $this->assertNull($result['errorMessage']);
    }

    public function testEditReturnsFailureForNonEditableVariable(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->edit('system_time_zone', 'UTC');

        $this->assertFalse($result['success']);
        $this->assertNull($result['errorCode']);
        $this->assertSame('Variable not editable', $result['errorMessage']);
    }

    public function testEditReturnsFailureWhenPrepareFails(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);
        $this->db->setQueryThrows(new \PDOException('Prepare failed'));

        $result = $editor->edit('max_connections', '200');

        $this->assertFalse($result['success']);
        $this->assertNull($result['errorCode']);
        $this->assertSame('Prepare failed', $result['errorMessage']);
    }

    public function testEditReturnsErrorResultOnPDOException(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);
        // Simulate a privilege error (MySQL error code 1142)
        $this->db->setQueryThrows(new \PDOException('SELECT command denied', 1142));

        $result = $editor->edit('max_connections', '200');

        $this->assertFalse($result['success']);
        $this->assertSame(1142, $result['errorCode']);
        $this->assertStringContainsString('SELECT command denied', $result['errorMessage']);
    }

    public function testEditPersistentReturnsSuccessResultWhenVariableIsEditable(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->editPersistent('max_connections', '200');

        $this->assertTrue($result['success']);
        $this->assertNull($result['errorCode']);
        $this->assertNull($result['errorMessage']);
    }

    public function testEditPersistentReturnsFailureForNonEditableVariable(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->editPersistent('system_time_zone', 'UTC');

        $this->assertFalse($result['success']);
        $this->assertSame('Variable not editable', $result['errorMessage']);
    }

    public function testEditPersistentReturnsErrorOnPDOException(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);
        // Simulate persisted_variables restriction (MySQL error code 3680)
        $this->db->setQueryThrows(new \PDOException('Variable "max_connections" is a persisted variable', 3680));

        $result = $editor->editPersistent('max_connections', '200');

        $this->assertFalse($result['success']);
        $this->assertSame(3680, $result['errorCode']);
    }

    public function testPersistReturnsSuccessResultWhenVariableIsEditable(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->persist('max_connections', '200');

        $this->assertTrue($result['success']);
        $this->assertNull($result['errorCode']);
        $this->assertNull($result['errorMessage']);
    }

    public function testPersistReturnsFailureForNonEditableVariable(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->persist('system_time_zone', 'UTC');

        $this->assertFalse($result['success']);
        $this->assertSame('Variable not editable', $result['errorMessage']);
    }

    public function testPersistReturnsErrorOnPDOException(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);
        // Simulate access denied error (MySQL error code 1227)
        $this->db->setQueryThrows(new \PDOException('Access denied', 1227));

        $result = $editor->persist('max_connections', '200');

        $this->assertFalse($result['success']);
        $this->assertSame(1227, $result['errorCode']);
    }

    public function testPersistOnlyReturnsSuccessResultWhenVariableIsEditable(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->persistOnly('max_connections', '200');

        $this->assertTrue($result['success']);
        $this->assertNull($result['errorCode']);
        $this->assertNull($result['errorMessage']);
    }

    public function testPersistOnlyReturnsFailureForNonEditableVariable(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->persistOnly('system_time_zone', 'UTC');

        $this->assertFalse($result['success']);
        $this->assertSame('Variable not editable', $result['errorMessage']);
    }

    public function testPersistOnlyReturnsErrorOnPDOException(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);
        // Simulate access denied error (MySQL error code 1227)
        $this->db->setQueryThrows(new \PDOException('Access denied', 1227));

        $result = $editor->persistOnly('max_connections', '200');

        $this->assertFalse($result['success']);
        $this->assertSame(1227, $result['errorCode']);
    }

    public function testResetPersistWithNameReturnsSuccess(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->resetPersist('max_connections');

        $this->assertTrue($result['success']);
        $this->assertNull($result['errorCode']);
        $this->assertNull($result['errorMessage']);
    }

    public function testResetPersistWithoutNameReturnsSuccess(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->resetPersist(null);

        $this->assertTrue($result['success']);
        $this->assertNull($result['errorCode']);
        $this->assertNull($result['errorMessage']);
    }

    public function testResetPersistDoesNotCheckEditable(): void
    {
        // resetPersist should work even for non-editable variables
        // because it removes the persisted value, not sets it
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $result = $editor->resetPersist('system_time_zone');

        $this->assertTrue($result['success']);
    }

    public function testLastErrorReturnsErrorMessageAfterFailedEdit(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);
        $this->db->setQueryThrows(new \PDOException('SELECT command denied', 1142));

        $editor->edit('max_connections', '200');

        $this->assertStringContainsString('SELECT command denied', $editor->lastError());
    }

    public function testIsPrivilegeErrorReturnsTrueAfterPrivilegeError(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);
        $this->db->setQueryThrows(new \PDOException('SELECT command denied', 1142));

        $editor->edit('max_connections', '200');

        $this->assertTrue($editor->isPrivilegeError());
    }

    public function testIsPrivilegeErrorReturnsTrueForErrorCode1227(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);
        $this->db->setQueryThrows(new \PDOException('Access denied', 1227));

        $editor->edit('max_connections', '200');

        $this->assertTrue($editor->isPrivilegeError());
    }

    public function testIsPersistedVariablesErrorReturnsTrueAfter3680Error(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);
        $this->db->setQueryThrows(new \PDOException('Persisted variables restriction', 3680));

        $editor->editPersistent('max_connections', '200');

        $this->assertTrue($editor->isPersistedVariablesError());
    }

    public function testEditExecutesCorrectSQL(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $editor->edit('max_connections', '300');

        $executions = $this->db->getExecutions();
        $this->assertCount(1, $executions);
        $this->assertStringContainsString('SET GLOBAL `max_connections`', $executions[0]['sql']);
        $this->assertSame(['300'], $executions[0]['values']);
    }

    public function testEditPersistentExecutesCorrectSQL(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $editor->editPersistent('max_connections', '300');

        $executions = $this->db->getExecutions();
        $this->assertCount(1, $executions);
        $this->assertStringContainsString('SET PERSIST `max_connections`', $executions[0]['sql']);
    }

    public function testPersistExecutesCorrectSQL(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $editor->persist('max_connections', '300');

        $executions = $this->db->getExecutions();
        $this->assertCount(1, $executions);
        $this->assertStringContainsString('SET PERSIST `max_connections`', $executions[0]['sql']);
    }

    public function testPersistOnlyExecutesCorrectSQL(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $editor->persistOnly('max_connections', '300');

        $executions = $this->db->getExecutions();
        $this->assertCount(1, $executions);
        $this->assertStringContainsString('SET PERSIST_ONLY `max_connections`', $executions[0]['sql']);
    }

    public function testResetPersistWithNameExecutesCorrectSQL(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $editor->resetPersist('max_connections');

        $executions = $this->db->getExecutions();
        $this->assertCount(1, $executions);
        $this->assertStringContainsString('RESET PERSIST `max_connections`', $executions[0]['sql']);
    }

    public function testResetPersistWithoutNameExecutesCorrectSQL(): void
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        $editor = VariableEditor::new($this->context, $catalog);

        $editor->resetPersist(null);

        $executions = $this->db->getExecutions();
        $this->assertCount(1, $executions);
        $this->assertStringContainsString('RESET PERSIST', $executions[0]['sql']);
        $this->assertStringNotContainsString('`', $executions[0]['sql']);
    }
}
