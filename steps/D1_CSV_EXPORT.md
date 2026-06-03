# Step D1: Implement CSV Export in ReportsPage

## Goal
Replace the no-op `withExport()` stub in ReportsPage with actual CSV export using the existing CsvExporter class.

## Current State
```php
// ReportsPage.php line 300-303
public function withExport(): self
{
    return $this;  // No-op stub!
}
```

## MySQL Workbench Reports Export Behavior
- Exports currently viewed report to CSV
- Filename format: `{report_name}_{timestamp}.csv`
- Includes column headers
- All rows (not just displayed page)

## Files to Modify

1. **`src/Admin/Reports/ReportsPage.php`** — Implement export logic
2. **`src/Admin/Reports/ReportRunner.php`** — May need to add raw export method

## Spec

### Approach
When `withExport()` is called:
1. Get `currentResult` (the loaded report data)
2. If no result, return `$this` (nothing to export)
3. Create `CsvExporter` instance with the database connection
4. For sys reports, use the raw column data from `currentResult->rows`
5. Write to temp file or stdout
6. Return `$this` (immutable - export doesn't change page state)

### Alternative: Return the CSV as string
```php
public function exportToCsv(): string
{
    if ($this->currentResult === null) {
        return '';
    }
    
    $exporter = new CsvExporter($this->context->connection());
    $rows = $this->currentResult->rows;
    
    return $exporter->exportRows($rows, array_keys($rows[0] ?? []));
}
```

## Acceptance Criteria
- `withExport()` or new export method produces valid CSV
- CSV includes headers matching report column names
- Empty report returns empty string, not error
- Unit test with fake data confirms CSV format

## Coder Notes
- CsvExporter is in `src/Db/Export/CsvExporter.php` - already exists
- `ReportResult::$rows` is `list<array<string,mixed>>` - standard associative array rows
- `CsvExporter::export()` takes table name, but sys reports aren't real tables - use `exportRows()` if available or adapt
