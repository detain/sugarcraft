<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Reports;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Reports\Catalog;
use SugarCraft\Query\Admin\Reports\ReportDefinition;

/**
 * Tests for Catalog report metadata loader.
 */
final class CatalogTest extends TestCase
{
    private string $dataPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataPath = __DIR__ . '/../../../data';
    }

    public function testLoadReportMetadata(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $reports = $catalog->all();
        $this->assertNotEmpty($reports);
        $this->assertGreaterThanOrEqual(20, count($reports));
    }

    public function testGetExistingReport(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $report = $catalog->get('x$statement_analysis');

        $this->assertInstanceOf(ReportDefinition::class, $report);
        $this->assertSame('x$statement_analysis', $report->name);
        $this->assertSame('problems', $report->category);
        $this->assertSame('Statement Analysis', $report->caption);
        $this->assertNotEmpty($report->columns);
    }

    public function testGetNonExistentReport(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $report = $catalog->get('nonexistent_report_xyz');

        $this->assertNull($report);
    }

    public function testByCategoryFiltering(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $problemsReports = $catalog->byCategory('problems');
        $this->assertNotEmpty($problemsReports);
        $this->assertArrayHasKey('x$statement_analysis', $problemsReports);
        $this->assertSame('problems', $problemsReports['x$statement_analysis']->category);

        foreach ($problemsReports as $report) {
            $this->assertSame('problems', $report->category);
        }
    }

    public function testByCategoryNoMatches(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $result = $catalog->byCategory('nonexistent_category_xyz');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCategoriesListing(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $categories = $catalog->categories();

        $this->assertIsArray($categories);
        $this->assertNotEmpty($categories);
        $this->assertContains('problems', $categories);
        $this->assertContains('schema', $categories);
        $this->assertContains('io', $categories);
        $this->assertContains('memory', $categories);
        $this->assertContains('innodb', $categories);
        $this->assertContains('user_resource_use', $categories);
        $this->assertContains('wait', $categories);

        $sortedCategories = $categories;
        sort($sortedCategories);
        $this->assertSame($sortedCategories, $categories);
    }

    public function testGroupedByCategory(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $grouped = $catalog->groupedByCategory();

        $this->assertIsArray($grouped);
        $this->assertNotEmpty($grouped);
        $this->assertArrayHasKey('problems', $grouped);
        $this->assertArrayHasKey('memory', $grouped);

        foreach ($grouped as $category => $reports) {
            $this->assertIsArray($reports);
            foreach ($reports as $report) {
                $this->assertSame($category, $report->category);
            }
        }
    }

    public function testHasReport(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $this->assertTrue($catalog->has('x$statement_analysis'));
        $this->assertTrue($catalog->has('x$memory_global_total'));
        $this->assertFalse($catalog->has('nonexistent_report_xyz'));
    }

    public function testCount(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $count = $catalog->count();
        $this->assertGreaterThan(0, $count);
        $this->assertSame($count, count($catalog->all()));
    }

    public function testLoadThrowsOnNonExistentPath(): void
    {
        $catalog = Catalog::new('/nonexistent/path/to/data');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Report metadata file not found');

        $catalog->load();
    }

    public function testLoadThrowsOnInvalidJson(): void
    {
        $tempDir = sys_get_temp_dir() . '/catalog_invalid_json_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/sys_reports.json', '{ invalid json }');

        try {
            $catalog = Catalog::new($tempDir);

            $this->expectException(\JsonException::class);

            $catalog->load();
        } finally {
            unlink($tempDir . '/sys_reports.json');
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

    public function testMemoryReportsExist(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $memoryReports = $catalog->byCategory('memory');

        $this->assertNotEmpty($memoryReports);
        $this->assertArrayHasKey('x$memory_global_total', $memoryReports);
        $this->assertArrayHasKey('x$memory_global_by_current_bytes', $memoryReports);
    }

    public function testIoReportsExist(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $ioReports = $catalog->byCategory('io');

        $this->assertNotEmpty($ioReports);
        $this->assertArrayHasKey('x$io_global_by_file_by_bytes', $ioReports);
    }

    public function testReportDefinitionHasCorrectQuery(): void
    {
        $catalog = Catalog::new($this->dataPath);
        $catalog->load();

        $report = $catalog->get('x$statement_analysis');

        $this->assertInstanceOf(ReportDefinition::class, $report);
        $this->assertStringContainsString('x$statement_analysis', $report->query);
    }
}
