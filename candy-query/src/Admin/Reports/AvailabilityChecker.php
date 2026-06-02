<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Reports;

use SugarCraft\Query\Db\DatabaseInterface;

/**
 * Checks availability of the MySQL sys schema views.
 *
 * Uses `SHOW FULL TABLES FROM sys WHERE Table_type='VIEW'` to discover
 * which sys schema views are present on the server. Reports whose views
 * are not available are skipped with graceful degradation.
 *
 * This handles:
 * - MySQL 5.6.6+ with sys schema installed
 * - MariaDB which has a different sys schema
 * - Older MySQL versions without sys schema
 * - Cases where the sys schema exists but some views are not present
 *
 * @see Mirrors mysql-workbench wb_admin_perfschema_reports view discovery
 */
final class AvailabilityChecker
{
    /** @var list<string>|null */
    private ?array $availableViews = null;

    private function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Factory method to create a new AvailabilityChecker.
     */
    public static function new(DatabaseInterface $db): self
    {
        return new self($db);
    }

    /**
     * Discover available sys schema views.
     *
     * Runs `SHOW FULL TABLES FROM sys WHERE Table_type='VIEW'` and returns
     * the list of view names that exist on this server.
     *
     * @return list<string> Available view names
     * @throws \RuntimeException If the query fails (e.g., sys schema doesn't exist)
     */
    public function discoverViews(): array
    {
        if ($this->availableViews !== null) {
            return $this->availableViews;
        }

        try {
            $result = $this->db->query(
                "SHOW FULL TABLES FROM sys WHERE Table_type='VIEW'"
            );

            $this->availableViews = [];
            foreach ($result as $row) {
                if (isset($row['Tables_in_sys'])) {
                    $this->availableViews[] = (string) $row['Tables_in_sys'];
                }
            }
        } catch (\PDOException $e) {
            $this->availableViews = [];
        }

        return $this->availableViews;
    }

    /**
     * Check if the sys schema exists and is accessible.
     *
     * @return bool True if sys schema exists, false otherwise
     */
    public function sysSchemaExists(): bool
    {
        try {
            $this->db->query("SHOW FULL TABLES FROM sys WHERE Table_type='VIEW'");

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Check if a specific sys schema view is available.
     *
     * @param string $viewName The view name to check (e.g., "x$statement_analysis")
     */
    public function isViewAvailable(string $viewName): bool
    {
        return in_array($viewName, $this->discoverViews(), true);
    }

    /**
     * Get all views that are available from the catalog.
     *
     * Filters the catalog's reports to only those whose views exist
     * on this server.
     *
     * @param Catalog $catalog The report catalog to filter
     * @return list<string> View names that are both in the catalog and available on the server
     */
    public function availableFromCatalog(Catalog $catalog): array
    {
        $available = $this->discoverViews();

        return array_values(array_filter(
            $catalog->all(),
            fn(ReportDefinition $report): bool => in_array($report->name, $available, true)
        ));
    }

    /**
     * Get missing views from the catalog.
     *
     * Returns the list of catalog reports whose views do not exist
     * on this server. Useful for showing which reports are unavailable.
     *
     * @param Catalog $catalog The report catalog to check
     * @return list<string> View names that are in the catalog but not available on the server
     */
    public function missingFromCatalog(Catalog $catalog): array
    {
        $available = $this->discoverViews();
        $missing = [];

        foreach (array_keys($catalog->all()) as $viewName) {
            if (!in_array($viewName, $available, true)) {
                $missing[] = $viewName;
            }
        }

        return $missing;
    }

    /**
     * Get reports available for a specific category.
     *
     * @param Catalog $catalog The report catalog
     * @param string $category The category to filter by
     * @return list<ReportDefinition> Available reports in the category
     */
    public function availableInCategory(Catalog $catalog, string $category): array
    {
        $available = $this->discoverViews();

        return array_values(array_filter(
            $catalog->byCategory($category),
            fn(ReportDefinition $report): bool => in_array($report->name, $available, true)
        ));
    }

    /**
     * Reset the cached view list.
     *
     * Call this if you suspect the sys schema has changed and want to re-discover.
     */
    public function reset(): void
    {
        $this->availableViews = null;
    }
}
