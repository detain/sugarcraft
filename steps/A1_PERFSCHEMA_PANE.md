# Step A1: Wire PerfSchemaPage into AdminPane

## Goal
Add `AdminPane::PerfSchema` case and map it in `App::adminPage()` to `PerfSchemaPage::new($context)`.

## Files to Modify

1. **`src/Admin/AdminPane.php`** — Add `case PerfSchema = 'perf_schema'` with `label()` and `section()` matches
2. **`src/App.php`** — Add `AdminPane::PerfSchema => PerfSchemaPage::new($context)` to the match in `adminPage()`

## Spec

### AdminPane.php changes
```php
enum AdminPane: string
{
    case ProcessList = 'processlist';
    case Variables   = 'variables';
    case Status      = 'status';
    case QueryStats  = 'query_stats';
    case ConnStats   = 'conn_stats';
    case TableStats  = 'table_stats';
    case PerfSchema  = 'perf_schema';  // ADD THIS

    // label() additions:
    // self::PerfSchema => 'Performance Schema',

    // section() additions:
    // self::PerfSchema => AdminSection::Performance,

    // next() additions:
    // self::PerfSchema => self::ProcessList (wrap around)
}
```

### App.php changes
In `adminPage()` match block, add:
```php
AdminPane::PerfSchema => PerfSchemaPage::new($context),
```

## Acceptance Criteria
- `AdminPane::PerfSchema` exists with correct label "Performance Schema"
- `PerfSchema` is in the `Performance` admin section
- Navigating to PerfSchema admin pane renders `PerfSchemaPage::build()`
- All existing tests pass

## Coder Notes
- Do NOT modify any other AdminPane case values (to avoid breaking existing state)
- Follow immutable with*() pattern for App changes
- Use existing `PerfSchemaPage::new($context)` factory method
