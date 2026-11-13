<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\Variables;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Admin\Variables\Catalog;
use SugarCraft\Sql\Admin\Variables\VariableEditor;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

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
        $this->context = new \SugarCraft\Sql\Admin\ServerContext($this->db);
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
        // (SQL-P13/023) message deliberately unlike the library's own copy so
        // this leg pins the PDOException passthrough, not a re-asserted literal.
        $this->db->setQueryThrows(new \PDOException('prepare is broken (driver)'));

        $result = $editor->edit('max_connections', '200');

        $this->assertFalse($result['success']);
        $this->assertNull($result['errorCode']);
        $this->assertSame('prepare is broken (driver)', $result['errorMessage']);
    }

    /**
     * adminpages-023 census: the bare "Prepare failed" copy must be gone
     * from the editor source entirely (it named neither statement position
     * nor connection at five sites).
     */
    public function testBarePrepareFailedLiteralIsGoneFromTheEditor(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Admin/Variables/VariableEditor.php');
        $this->assertNotFalse($source);
        $this->assertSame(0, substr_count($source, "'errorMessage' => 'Prepare failed'"));
        $this->assertStringContainsString('Could not prepare the statement on this connection', $source);
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

    // ─── SQL-P13 (adminpages-024/-029): vendor-aware PERSIST gates ────────

    private function editorWithCatalog(): VariableEditor
    {
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();

        return VariableEditor::new($this->context, $catalog);
    }

    /**
     * Each boundary can FAIL against today's shape: MariaDB 10.6 passed the
     * old numeric isAtLeast(8,0) gate, and MySQL 8.0.15 passed it too — both
     * then ate a raw 1064. Post-fix, the refusal is named and nothing dials.
     */
    public function testMariaDb10point6RefusesPersistByName(): void
    {
        $this->db->setServerVersion('5.5.5-10.6.7-MariaDB-1:10.6.7+maria~ubu2204');
        $editor = $this->editorWithCatalog();

        $result = $editor->persist('max_connections', '200');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('MariaDB 11.1+', $result['errorMessage'], 'the refusal names the vendor the pane faces');
        $this->assertCount(0, $this->db->getExecutions(), 'a gated refusal must not reach the server');
    }

    public function testMariaDb11point1AllowsPersist(): void
    {
        $this->db->setServerVersion('5.5.5-11.1.4-MariaDB-1:11.1.4+maria~ubu2204');
        $editor = $this->editorWithCatalog();

        $result = $editor->persist('max_connections', '200');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('SET PERSIST', $this->db->getExecutions()[0]['sql']);
    }

    public function testMySql8015RefusesAnd8016Allows(): void
    {
        $this->db->setServerVersion('MySQL version 8.0.15');
        $refused = $this->editorWithCatalog()->persist('max_connections', '200');
        $this->assertFalse($refused['success']);
        $this->assertStringContainsString('MySQL 8.0.16+', $refused['errorMessage']);
        $this->assertCount(0, $this->db->getExecutions());

        $db2 = new FakeDatabase();
        $db2->setServerVersion('MySQL version 8.0.16');
        $context2 = new \SugarCraft\Sql\Admin\ServerContext($db2);
        $catalog = Catalog::new(__DIR__ . '/../../../data');
        $catalog->load();
        $allowed = VariableEditor::new($context2, $catalog)->persist('max_connections', '200');
        $this->assertTrue($allowed['success'], '8.0.16 is the exact floor');
    }

    public function testPersistOnlyAndResetPersistShareTheGate(): void
    {
        $this->db->setServerVersion('5.5.5-10.11.8-MariaDB-1:10.11.8+maria~ubu2204');
        $editor = $this->editorWithCatalog();

        $this->assertStringContainsString('MariaDB 11.1+', $editor->persistOnly('max_connections', '200')['errorMessage']);
        $this->assertStringContainsString('MariaDB 11.1+', $editor->resetPersist('max_connections')['errorMessage']);
        $this->assertCount(0, $this->db->getExecutions(), 'no leg dials the server past the gate');
    }

    /**
     * adminpages-029: editPersistent no longer runs an ungated raw SET
     * PERSIST — on this MariaDB 10.6 server it must refuse with the named
     * message instead of surfacing a misleading 1064.
     */
    public function testEditPersistentReachesTheGatedPath(): void
    {
        $this->db->setServerVersion('5.5.5-10.6.7-MariaDB-1:10.6.7+maria~ubu2204');
        $editor = $this->editorWithCatalog();

        $result = $editor->editPersistent('max_connections', '200');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('MariaDB 11.1+', $result['errorMessage'], 'editPersistent rides the same named gate as persist()');
        $this->assertCount(0, $this->db->getExecutions());
    }

    public function testEditPersistentStillSucceedsOnCurrentServers(): void
    {
        $editor = $this->editorWithCatalog();

        $result = $editor->editPersistent('max_connections', '200');

        $this->assertTrue($result['success'], 'the 8.0.33 default passes the 8.0.16 floor');
        $this->assertStringContainsString('SET PERSIST', $this->db->getExecutions()[0]['sql']);
    }
}
