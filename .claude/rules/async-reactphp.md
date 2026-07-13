---
paths:
  - candy-query/src/**
  - '*/src/**/Async*.php'
  - '*/src/**/Cmd.php'
---

# Async / ReactPHP conventions

- Runtime is the ReactPHP event loop (`candy-core` `Program`). `candy-async` provides `CancellationToken`, `Subscription`, and `AsyncOps` (`withTimeout`/`retry`/`debounce`/`throttle`).
- `view()` MUST stay non-blocking — never run a sync DB query on the render/keystroke path. Route data through a cache (`candy-query`'s `AdminQueryCache` + `AsyncCachedConnection`) and refresh via `Cmd::promise`/poll ticks.
- `candy-query` DB is async on the loop: `react/mysql` + `voryx/pgasync` (NOT amphp). `Cmd::promise` takes no trailing `()`.
- `Database::query()` returns `array|null` — `null` signals a reconnectable error (2002/2003/2013); guard before iterating.
- Subscriptions pump is on by default: `candy-core` `Program` calls `model->subscriptions()` — required for `candy-query` admin polling.
- External CLIs go through an arg-array to `proc_open`, never a shell string; pass every flag via `escapeshellarg((string)($field ?? ''))`, blank included.
