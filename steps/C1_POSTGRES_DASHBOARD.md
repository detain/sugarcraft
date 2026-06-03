# Step C1: Implement PostgresAdminProvider Dashboard Metrics

## Goal
Make PostgresAdminProvider actually return meaningful metrics instead of empty arrays.

## Current Problem
`PostgresAdminProvider::checkAllMetrics()` and `checkConnectionUsage()` return empty arrays - stub implementation.

## What MySQL Workbench Dashboard Shows (PostgreSQL equivalent)
- Database activity: transactions, rollbacks, tuples returned/fetched
- Cache effectiveness: blocks hit vs blocks read
- Connection count: numbackends vs max_connections
- I/O: blocks read/written

## Files to Modify

1. **`src/Admin/Providers/PostgresAdminProvider.php`** — Implement `checkAllMetrics()` and `checkConnectionUsage()`

## Spec for checkAllMetrics()
```php
public function checkAllMetrics(array $statusVariables, array $serverVariables): array
{
    // For PostgreSQL, statusVariables comes from pg_stat_database
    // Use pg_stat_database columns to compute rates
    // Similar to MysqlAdminProvider::checkAllMetrics() but with pg_stat_* mappings
}
```

## Metrics to Compute
From `pg_stat_database` (queried via PostgresServerContext):
- `tup_returned` rate → "Tuples Returned/sec"  
- `tup_fetched` rate → "Tuples Fetched/sec"
- `tup_inserted` + `tup_updated` + `tup_deleted` rates → "Writes/sec"
- `blks_hit` / (`blks_hit` + `blks_read`) → cache hit ratio %
- `numbackends` / `max_connections` → connection usage %

## Acceptance Criteria
- `checkAllMetrics()` returns non-empty array with computed PostgreSQL metrics
- `checkConnectionUsage()` returns connection alerts when threshold exceeded
- Metrics map correctly to PostgresWidgetCatalog widget calcs
- Tests use FakePostgresDatabase with pg_stat_database mock data

## Coder Notes
- Use `CacheHitRate` calc if it exists and fits
- Do NOT use live database - use FakePostgresDatabase in tests
- PostgresServerContext should already be providing pg_stat_database data via the admin fetch promise in App::createAdminFetchPromise()
