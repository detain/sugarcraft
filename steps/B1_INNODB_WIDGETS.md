# Step B1: Add Missing InnoDB Dashboard Widgets

## Goal
Add InnoDB metrics that are in MySQL Workbench but missing from `WidgetCatalog.php`.

## Missing Widgets to Add

Based on mysql_workbench_dash.md §5.1 Appendix A and comparing with current WidgetCatalog:

### Row Lock Metrics
| Caption | Kind | Calc | Format | Color |
|--------|------|------|--------|-------|
| Row Lock Waits | counter | RatePerSecond('Innodb_row_lock_waits') | %s/s | cyan |
| Row Lock Time Avg | counter | StatusVar('Innodb_row_lock_time') / some divisor | %.0fms | orange |

### Page Operations
| Caption | Kind | Calc | Format | Color |
|--------|------|------|--------|-------|
| Pages Flushed | counter | RatePerSecond('Innodb_pages_flushed') | %s/s | orange |
| Pages Created | counter | RatePerSecond('Innodb_pages_created') | %s/s | cyan |
| Pages Read | counter | RatePerSecond('Innodb_pages_read') | %s/s | cyan |

### Insert Buffer
| Caption | Kind | Calc | Format | Color |
|--------|------|------|--------|-------|
| Insert Buffer Hits | counter | RatePerSecond('Innodb_ibuf_discards') | %s/s | green |
| Insert Buffer Size | counter | StatusVar('ibuf_pool_size') or similar | bytes | blue |

### Key Metrics Missing
- `Innodb_innodb_io_pending_*` (pending I/O) — may need special handling
- `Innodb_buffer_pool_read_ahead_*` (read-ahead effectiveness)

## Files to Modify

1. **`src/Admin/Dashboard/WidgetCatalog.php`** — Add missing entries to `innodb()` array

## Acceptance Criteria
- WidgetCatalog::innodb() includes at least 5 new InnoDB metrics
- WidgetRegistry::build() includes them in the MySQL dashboard
- Timeline/counter/kind assignments match MySQL Workbench widget definitions
- Tests in WidgetCatalogTest or similar pass

## Coder Notes
- Check if status variable names exist in MySQL before using them
- Use `RatePerSecond` for rate-based metrics, `StatusVar` for absolute values
- Follow the existing entry format: `[caption, kind, calc, format, color, tooltip, serverVarsKeys]`
- Color palette: cyan (60,178,191), orange (253,138,39), green (124,193,80), blue (30,144,255)
