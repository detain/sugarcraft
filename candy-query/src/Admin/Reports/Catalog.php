<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Reports;

/**
 * Catalog loader for performance report metadata.
 *
 * Reads report definitions from a JSON file and provides lookup methods
 * for querying reports by name, category, or viewing all available reports.
 *
 * @see Mirrors mysql-workbench wb_admin_perfschema_reports ReportCatalog
 */
final class Catalog
{
    /** @var array<string, ReportDefinition>|null */
    private ?array $reports = null;

    /** @var array<string, list<ReportDefinition>>|null */
    private ?array $byCategory = null;

    /** @var list<string>|null */
    private ?array $categories = null;

    /**
     * Curated category display order — problems first (high-priority一眼), then
     * schema, I/O, wait, InnoDB, user resource, and memory (lower priority).
     * Mirrors the MySQL Workbench Reports catalog ordering in Appendix B.
     *
     * @var array<string, int>
     */
    private const CATEGORY_ORDER = [
        'problems' => 0,
        'schema' => 1,
        'io' => 2,
        'wait' => 3,
        'innodb' => 4,
        'user_resource_use' => 5,
        'memory' => 6,
    ];

    private function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * Factory method to create a new Catalog instance.
     *
     * @param string $basePath Path to the data directory containing sys_reports.json
     */
    public static function new(string $basePath = __DIR__ . '/../../../data'): self
    {
        return new self($basePath);
    }

    /**
     * Load and parse the report metadata JSON file.
     *
     * @throws \JsonException If the JSON file cannot be parsed
     * @throws \RuntimeException If the metadata file cannot be read
     */
    public function load(): void
    {
        $filePath = $this->basePath . '/sys_reports.json';

        if (!is_readable($filePath)) {
            throw new \RuntimeException(
                "Report metadata file not found: " . htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8')
            );
        }

        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new \RuntimeException(
                "Failed to read report metadata file: " . htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8')
            );
        }

        /** @var array<string, array{name:string,category:string,caption:string,description:string,query:string,columns:array<array{name:string,type:string,width:int}>}> $data */
        $data = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $this->reports = [];
        foreach ($data as $viewName => $reportData) {
            $columns = array_map(
                fn(array $col): ColumnDefinition => new ColumnDefinition(
                    name: $col['name'],
                    type: ColumnType::tryFrom($col['type']) ?? ColumnType::String,
                    width: $col['width'],
                ),
                $reportData['columns']
            );

            $this->reports[$viewName] = new ReportDefinition(
                name: $reportData['name'],
                category: $reportData['category'],
                caption: $reportData['caption'],
                description: $reportData['description'],
                query: $reportData['query'],
                columns: $columns,
            );
        }

        $this->byCategory = null;
        $this->categories = null;
    }

    /**
     * Get a report by its view name.
     *
     * @param string $name The view name (e.g. "x$statement_analysis")
     * @return ReportDefinition|null The report definition or null if not found
     */
    public function get(string $name): ?ReportDefinition
    {
        $this->ensureLoaded();

        return $this->reports[$name] ?? null;
    }

    /**
     * Get all available reports.
     *
     * @return array<string, ReportDefinition> All report definitions indexed by view name
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return $this->reports;
    }

    /**
     * Get all reports belonging to a specific category.
     *
     * @param string $category The category name to filter by
     * @return array<string, ReportDefinition> Matching reports indexed by view name
     */
    public function byCategory(string $category): array
    {
        $this->ensureLoaded();

        $result = [];
        foreach ($this->reports as $name => $report) {
            if ($report->category === $category) {
                $result[$name] = $report;
            }
        }

        return $result;
    }

    /**
     * List all available categories.
     *
     * @return list<string> Sorted list of unique category names
     */
    public function categories(): array
    {
        $this->ensureLoaded();

        if ($this->categories === null) {
            $categories = [];
            foreach ($this->reports as $report) {
                $categories[$report->category] = true;
            }
            $categoryNames = array_keys($categories);

            // Sort by curated order (problems first), then alphabetically for unknown categories
            $maxOrder = max(self::CATEGORY_ORDER) + 1;
            usort($categoryNames, function (string $a, string $b) use ($maxOrder): int {
                $orderA = self::CATEGORY_ORDER[$a] ?? $maxOrder;
                $orderB = self::CATEGORY_ORDER[$b] ?? $maxOrder;
                if ($orderA !== $orderB) {
                    return $orderA <=> $orderB;
                }
                return $a <=> $b;
            });

            $this->categories = $categoryNames;
        }

        return $this->categories;
    }

    /**
     * Get all reports grouped by category.
     *
     * @return array<string, list<ReportDefinition>> Reports grouped by category name
     */
    public function groupedByCategory(): array
    {
        $this->ensureLoaded();

        if ($this->byCategory === null) {
            $this->byCategory = [];
            foreach ($this->reports as $report) {
                if (!isset($this->byCategory[$report->category])) {
                    $this->byCategory[$report->category] = [];
                }
                $this->byCategory[$report->category][] = $report;
            }
        }

        return $this->byCategory;
    }

    /**
     * Check if a report exists in the catalog.
     */
    public function has(string $name): bool
    {
        $this->ensureLoaded();

        return isset($this->reports[$name]);
    }

    /**
     * Get the number of reports in the catalog.
     */
    public function count(): int
    {
        $this->ensureLoaded();

        return count($this->reports);
    }

    /**
     * Ensure the metadata has been loaded.
     *
     * @throws \RuntimeException If load() has not been called
     */
    private function ensureLoaded(): void
    {
        if ($this->reports === null) {
            throw new \RuntimeException(
                'Catalog has not been loaded. Call load() first.'
            );
        }
    }
}
