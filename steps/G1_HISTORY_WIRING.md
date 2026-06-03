# Step G1: Wire HistoryRecorder into App Polling Loop

## Goal
Connect HistoryRecorder to the App's admin data fetching so query history is actually recorded.

## Current State
- `HistoryRecorder` implements `StatusSnapshotProviderInterface`
- `SqliteHistoryStore` persists to SQLite
- App::subscriptions() uses `Cmd::promise()` directly, not HistoryRecorder

## MySQL Workbench
No query history in Workbench admin - this is a candy-query enhancement.

## Files to Modify

1. **`src/App.php`** — Add optional history recording to the polling loop

## Spec

### Option A: Add as a subscription
In `App::subscriptions()`:
```php
if ($this->pane !== Pane::Admin) {
    return null;
}

// Existing admin-fetch tick...
return Subscriptions::withTick('admin-fetch', 1.0, function(): \SugarCraft\Core\Msg {
    return Cmd::batch(
        fn() => new AdminFetchStartedMsg(),
        Cmd::promise(fn() => $this->createAdminFetchPromise()),
    )();
});
```

Add history recording as a second subscription, or integrate into the promise chain.

### Option B: HistoryRecorder wraps the fetch promise
Pass HistoryRecorder into `createAdminFetchPromise()` and call it with query results.

### Option C: Make history opt-in via AppBuilder
Add `withHistoryStore(SqliteHistoryStore $store)` to AppBuilder.

## Acceptance Criteria
- When a query is executed in Query pane, it's recorded in history
- HistoryQuery can retrieve queries by time range
- History persists across app restarts (SqliteHistoryStore)
- Performance impact is minimal - history recording is async

## Coder Notes
- HistoryRecorder::provideStatusSnapshot() takes previous snapshot - this is for metrics, not queries
- For query history, need a different integration point - perhaps in App::runQuery()
- Make it opt-in via AppBuilder flag since it requires SQLite file
- SqliteHistoryStore uses WAL mode - safe for concurrent reads during writes
