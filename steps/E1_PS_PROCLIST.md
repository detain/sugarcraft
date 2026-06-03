# Step E1: Add PS-Based Processlist Path to MysqlAdminProvider

## Goal
Add Performance Schema path for processlist in MysqlAdminProvider, used when `@@performance_schema = 1`.

## Current Problem
`MysqlAdminProvider::fetchProcesslist()` always uses `SHOW FULL PROCESSLIST`, never uses the PS path even when available.

## MySQL Workbench PS Path (per mysql_workbench_dash.md §5.5)
```sql
SELECT <cols> FROM performance_schema.threads t 
  LEFT OUTER JOIN performance_schema.session_connect_attrs a 
    ON t.processlist_id = a.processlist_id 
   AND (a.attr_name IS NULL OR a.attr_name = 'program_name')
  WHERE t.TYPE <> 'BACKGROUND'
```

Fallback when PS is off: `SHOW FULL PROCESSLIST`

## Files to Modify

1. **`src/Admin/Providers/MysqlAdminProvider.php`** — Add PS path in `fetchProcesslist()`

## Spec
```php
public function fetchProcesslist(): array
{
    // Check if performance_schema is enabled
    $psEnabled = $this->context->serverVariables()['performance_schema'] ?? 'OFF';
    
    if (strtoupper($psEnabled) === 'ON') {
        return $this->fetchProcesslistFromPs();
    }
    return $this->fetchProcesslistFromShow();
}

private function fetchProcesslistFromPs(): array
{
    // PS-based query with session_connect_attrs join
    // Return format matches interface contract
}

private function fetchProcesslistFromShow(): array
{
    // Current SHOW FULL PROCESSLIST implementation
}
```

## Acceptance Criteria
- When performance_schema=ON, uses PS query path
- When performance_schema=OFF, falls back to SHOW FULL PROCESSLIST  
- ProcesslistResult format is maintained (processId, user, host, database, command, time, state, info, connectionAttr)
- ProcesslistProvider tests (which also use PS) still pass

## Coder Notes
- Use `$this->context->connection()->query()` for the PS query
- Truncate PROCESSLIST_INFO to 255 chars as MySQL Workbench does
- Connection attributes should come from session_connect_attrs join
- Test with FakeMysqlDatabase that returns appropriate rows for both paths
