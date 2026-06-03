# Step B2: Widget Catalog Verification & Cleanup

## Goal
Verify WidgetCatalog entries match MySQL Workbench expressions exactly, and clean up any inconsistencies.

## MySQL Workbench Widget Expressions (per mysql_workbench_dash.md Appendix A)

### Network Panel (should have)
- Bytes In: `Bytes_received` rate per second
- Bytes Out: `Bytes_sent` rate per second  
- Connections: raw `Threads_connected`

### MySQL Panel (pre-8.0 vs 8.0)
- pre-8.0: DDL expression includes `Com_alter_db_upgrade`
- post-8.0: DDL expression includes role commands (`Com_create_role`, `Com_drop_role`, `Com_alter_user_default_role`)

### InnoDB Panel
- Buffer Pool Usage: `Innodb_buffer_pool_pages_total` - formula is `(total - free) / total * 100`
- Row Lock Waits, Page Operations, etc. (from B1)

## Tasks

1. **Verify expressions** — Compare current `WidgetCatalog.php` entries against MySQL Workbench `charting.py` calc expressions
2. **Check color assignments** — Ensure colors match Workbench severity/meaning
3. **Verify tooltip templates** — Should use `%(VarName)s` style substitution
4. **Fix any discrepancies** — Document findings in updates.md if MySQL Workbench expression is incompatible with PHP implementation

## Files to Modify

1. **`src/Admin/Dashboard/WidgetCatalog.php`** — Fix any incorrect expressions

## Acceptance Criteria
- All verified expressions match MySQL Workbench (document any intentional deviations)
- Color assignments are consistent and match severity semantics  
- Tooltips are properly formatted
- Test coverage for widget computation (RatePerSecond, MakeTuple results)

## Coder Notes
- This is a VERIFICATION step first - don't change things that work correctly
- If MySQL Workbench uses an expression that would require eval() in PHP, document it and decide whether to approximate
- The pre-8.0 vs 8.0 distinction for DDL commands is already implemented - verify it's correct
