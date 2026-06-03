# Step B3: Complete PostgresWidgetCatalog

## Goal
Ensure PostgresWidgetCatalog has equivalent metrics to the MySQL WidgetCatalog, using pg_stat_database and pg_stat_bgwriter counters.

## Current PostgresWidgetCatalog State
Check what currently exists in `PostgresWidgetCatalog.php`.

## MySQL vs PostgreSQL Metric Mapping

| MySQL Widget | PostgreSQL Equivalent | pg_stat_* View/Column |
|--------------|----------------------|----------------------|
| Bytes In/Out | tup_fetched/tup_returned | pg_stat_database |
| Connections | numbackends | pg_stat_database |
| SQL Statements (select/insert/etc) | sum of tup_* | pg_stat_database |
| InnoDB Buffer Pool | shared_buffers | pg_settings |
| Buffer Cache Hit Ratio | blks_hit / (blks_hit + blks_read) | pg_stat_database |

## Files to Modify

1. **`src/Admin/Dashboard/PostgresWidgetCatalog.php`** — Add/verify pg_stat_database based metrics

## Acceptance Criteria
- `io()` panel has I/O rate widgets (tup_fetched, tup_returned, blks_read, blks_hit)
- `transactions()` panel has commit/rollback rates  
- `cache()` panel has buffer hit ratio and shared_buffers usage
- Postgres dashboard shows meaningful data when connected to PostgreSQL

## Coder Notes
- Use `CacheHitRate` calc class if available, or implement buffer hit ratio as RatePerSecond on computed values
- PostgresWidgetCatalog should return same Widget entry format as WidgetCatalog
- DashboardPage already checks `isPostgres` flag and uses PostgresWidgetCatalog - verify routing is correct
