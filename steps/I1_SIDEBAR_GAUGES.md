# Step I1: Add SidebarGaugeSet to ServerStatusPage

## Goal
Add the live gauge sidebar (CPU, Connections, Traffic, etc.) to ServerStatusPage, matching MySQL Workbench's server status sidebar.

## Current State
- `SidebarGaugeSet` and `SidebarGauge` classes exist in `src/Admin/ServerStatus/`
- `ServerStatusPage::build()` renders info cards but NOT the gauge sidebar
- MySQL Workbench wb_admin_monitor.py renders CPU bar + 5 line graphs on Server Status

## MySQL Workbench Sidebar Gauges (from mysql_workbench_dash.md §5.4)

| Gauge | Variables | Calculation |
|-------|-----------|--------------|
| CPU/Load | host uptime load avg | raw load number |
| Connections | Threads_connected | raw count |
| Traffic | Bytes_sent delta | (now-last)/interval B/s |
| Key Efficiency | Key_reads, Key_read_requests | 100 - (Key_reads/Key_read_requests*100) |
| Selects/sec | Com_select delta | (now-last)/interval |
| InnoDB Buffer | Innodb_buffer_pool_pages_free, total | 100*(total-free)/total |
| InnoDB Reads/sec | Innodb_data_reads delta | (now-last)/interval |
| InnoDB Writes/sec | Innodb_data_writes delta | (now-last)/interval |

## Files to Modify

1. **`src/Admin/ServerStatus/ServerStatusPage.php`** — Add SidebarGaugeSet rendering

## Spec

### Layout
ServerStatusPage currently has a single-column layout. Need a 2-column layout:
- Left: Info card, Features, Directories, SSL, Replication, Firewall panels
- Right: SidebarGaugeSet (vertical stack of gauges)

### Rendering
```php
protected function build(): string
{
    // Existing left panel content...
    $leftPanel = $this->renderInfoPanels();
    
    // Add gauge sidebar  
    $gaugeSet = new SidebarGaugeSet($this->context);
    $rightPanel = $gaugeSet->render();
    
    // 2-column join
    return Layout::joinHorizontal(Position::TOP, $leftPanel, '  ', $rightPanel);
}
```

## Acceptance Criteria
- ServerStatusPage shows gauges on the right
- Gauges update with status variables from context
- Color thresholds (green/yellow/red) are applied correctly
- Falls back gracefully if Sampler isn't providing rates

## Coder Notes
- SidebarGaugeSet needs ServerContext or StatusSnapshotProviderInterface
- Gauges should use Sampler for rate calculations where appropriate
- CPU gauge requires host metrics (uptime) - may be optional/N/A for remote connections
- Keep the "return to browse" key behavior working
