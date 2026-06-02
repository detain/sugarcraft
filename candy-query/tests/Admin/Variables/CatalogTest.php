<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Variables;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Variables\Catalog;
use SugarCraft\Query\Admin\Variables\VariableMetadata;

/**
 * Tests for Catalog variable metadata loader.
 */
final class CatalogTest extends TestCase
{
    private string $dataPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataPath = __DIR__ . '/../../../data';
    }

    public function testLoadVariableMetadata(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $variables = $catalog->all();
        $this->assertNotEmpty($variables);
        $this->assertGreaterThanOrEqual(50, count($variables));
    }

    public function testGetExistingVariable(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $metadata = $catalog->get('max_connections');

        $this->assertInstanceOf(VariableMetadata::class, $metadata);
        $this->assertSame('max_connections', $metadata->name);
        $this->assertSame('The maximum number of concurrent connections. Setting this variable requires the SUPER privilege or the SYSTEM_VARIABLES_ADMIN privilege (MySQL 8.0).', $metadata->description);
        $this->assertTrue($metadata->editable);
        $this->assertSame(['connection', 'advanced'], $metadata->groups);
    }

    public function testGetNonExistentVariable(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $metadata = $catalog->get('nonexistent_variable_xyz');

        $this->assertNull($metadata);
    }

    public function testByGroupFiltering(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $connectionVars = $catalog->byGroup('connection');
        $this->assertNotEmpty($connectionVars);
        $this->assertArrayHasKey('max_connections', $connectionVars);
        $this->assertArrayHasKey('wait_timeout', $connectionVars);
        $this->assertArrayHasKey('interactive_timeout', $connectionVars);

        foreach ($connectionVars as $metadata) {
            $this->assertTrue($metadata->inGroup('connection'));
        }
    }

    public function testByGroupNoMatches(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $result = $catalog->byGroup('nonexistent_group_xyz');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGroupsListing(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $groups = $catalog->groups();

        $this->assertIsArray($groups);
        $this->assertNotEmpty($groups);
        $this->assertContains('connection', $groups);
        $this->assertContains('buffer', $groups);
        $this->assertContains('log', $groups);
        $this->assertContains('security', $groups);
        $this->assertContains('replication', $groups);
        $this->assertContains('performance', $groups);

        // Groups should be sorted alphabetically
        $sortedGroups = $groups;
        sort($sortedGroups);
        $this->assertSame($sortedGroups, $groups);
    }

    public function testIsEditableWhenEditable(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $this->assertTrue($catalog->isEditable('max_connections'));
        $this->assertTrue($catalog->isEditable('wait_timeout'));
    }

    public function testIsEditableWhenNotEditable(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $this->assertFalse($catalog->isEditable('system_time_zone'));
        $this->assertFalse($catalog->isEditable('lower_case_table_names'));
    }

    public function testIsEditableWhenNotFound(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $this->assertFalse($catalog->isEditable('nonexistent_variable_xyz'));
    }

    public function testGetVariableWithMultipleGroups(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $metadata = $catalog->get('max_connections');

        $this->assertInstanceOf(VariableMetadata::class, $metadata);
        $this->assertTrue($metadata->inGroup('connection'));
        $this->assertTrue($metadata->inGroup('advanced'));
        $this->assertFalse($metadata->inGroup('buffer'));
    }

    public function testLoadThrowsOnNonExistentPath(): void
    {
        $catalog = Catalog::new('/nonexistent/path/to/data');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Variable metadata file not found');

        $catalog->load();
    }

    public function testLoadThrowsOnInvalidJson(): void
    {
        $tempDir = sys_get_temp_dir() . '/catalog_invalid_json_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/variable_metadata.json', '{ invalid json }');

        try {
            $catalog = Catalog::new($tempDir);

            $this->expectException(\JsonException::class);

            $catalog->load();
        } finally {
            unlink($tempDir . '/variable_metadata.json');
            rmdir($tempDir);
        }
    }

    public function testAllReturnsCopy(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $all1 = $catalog->all();
        $all2 = $catalog->all();

        $this->assertSame($all1, $all2);
    }

    public function testInnodbVariablesExist(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $innodbVars = $catalog->byGroup('innodb');

        $this->assertNotEmpty($innodbVars);
        $this->assertArrayHasKey('innodb_buffer_pool_size', $innodbVars);
        $this->assertArrayHasKey('innodb_log_file_size', $innodbVars);
    }

    public function testSslVariablesExist(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $sslVars = $catalog->byGroup('ssl');

        $this->assertNotEmpty($sslVars);
        $this->assertArrayHasKey('ssl_ca', $sslVars);
        $this->assertArrayHasKey('ssl_cert', $sslVars);
        $this->assertArrayHasKey('ssl_key', $sslVars);
    }

    public function testReplicationVariablesExist(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $replVars = $catalog->byGroup('replication');

        $this->assertNotEmpty($replVars);
        $this->assertArrayHasKey('server_id', $replVars);
        $this->assertArrayHasKey('binlog_format', $replVars);
        $this->assertArrayHasKey('gtid_mode', $replVars);
    }
}
