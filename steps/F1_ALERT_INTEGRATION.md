# Step F1: Wire AlertManager into DashboardPage Polling

## Goal
Integrate alerting into the DashboardPage 3-second polling cycle so threshold violations produce toast notifications.

## Current State
- AlertManager exists and has `checkAllMetrics()` and `checkConnectionUsage()` methods
- DashboardPage has a 3-second polling cycle via `pollAndUpdateCells()`
- AlertNotifier can display toasts via sugar-toast

## What MySQL Workbench Does
Workbench doesn't have explicit alerting in the dashboard - it shows threshold-colored gauges. But the plan specified (Phase 7.2):
> "Turn thresholds into notifications (e.g. connections > 90% of max_connections); optional toast via existing libs"

## Files to Modify

1. **`src/Admin/Dashboard/DashboardPage.php`** — Add alert integration
2. **`src/Admin/Alerts/AlertNotifier.php`** — Ensure integration points exist

## Spec

### DashboardPage Changes
In `pollAndUpdateCells()` or a new `checkAlerts()` method:

```php
private function checkAlerts(array $statusVars, array $serverVars): void
{
    $manager = AlertManager::new()
        ->withThresholds(AlertThresholds::default())
        ->withNotifier(AlertNotifier::withDefaults());
    
    $alerts = $manager->checkAllMetrics($statusVars, $serverVars);
    
    if ($alerts !== []) {
        $this->pendingAlerts = array_merge($this->pendingAlerts ?? [], $alerts);
    }
}
```

### Show Alerts in Footer
In `renderFooter()`:
- If `pendingAlerts` not empty, show count: `[!] 2 alerts`
- Or integrate into DashboardPage view() output

## Acceptance Criteria
- When connection usage > 80%, an alert is created
- AlertNotifier shows toast (or is muted by default, still acceptable)
- Alerts don't block dashboard rendering - graceful degradation
- No alerts when metrics are normal

## Coder Notes
- AlertNotifier is mute-safe by default - calling notify() with no factory is a no-op
- Make alerts non-blocking - don't stall the dashboard render for alert checks
- Consider adding keyboard shortcut to dismiss/view alerts
