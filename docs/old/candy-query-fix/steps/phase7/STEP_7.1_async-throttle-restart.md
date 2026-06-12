# STEP 7.1 — Adopt AsyncOps::throttle; unify restart detection

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §E; query_update.md issue #11.

## Why
Polling cadence is hand-rolled in three places (`StatusPoller`, `App::subscriptions` tick,
`ServerContext` TTL) while `candy-async AsyncOps::throttle` exists and is never used —
`candy-async` isn't even a declared `require` (path-repo only). Restart detection (decreasing
Uptime → reset rates) is duplicated three times (`Sampler::registerUptime`,
`StatusPoller::trackUptimeFromSnapshot`, `ServerContext::detectReset`) with float/int
inconsistency and a double-reset risk.

## Goal
- The admin poll cadence uses `AsyncOps::throttle(3.0, …)` (or documents a deliberate deviation).
- `candy-async` is a declared dependency with the path-repo closure correct.
- One restart-detection owner (`ServerContext`); `Sampler` consumes it; no double-reset.

## Files
- `composer.json` (add `"sugarcraft/candy-async": "dev-master"` to `require`; confirm path-repo).
- `src/App.php` (subscriptions/admin tick), `src/Admin/{StatusPoller,Sampler,ServerContext}.php`.
- Run `php /home/sites/sugarcraft/tools/check-path-repos.php --fix` from repo root; eyeball diff.
- Tests under `tests/Admin/`.

## Do
1. Add `candy-async` to `require`; fix path-repo closure (the checker may flag transitive deps).
   `cd candy-query && composer update --quiet` before trusting a local red.
2. Route the admin fetch tick through `AsyncOps::throttle(3.0, …)`; remove the redundant manual
   cadence gates (or, if throttle doesn't fit the TEA subscription model cleanly, keep one gate
   and `NOTE:` the deliberate choice — don't keep three).
3. Consolidate restart detection into `ServerContext::wasReset()` (one float-based
   implementation); have `Sampler` call `resetAll()` based on it; delete the duplicate logic in
   `StatusPoller`/`Sampler` (note: `StatusPoller` itself may be deleted in 7.3 — coordinate via
   `updates.md`).

## Acceptance criteria
- [ ] `candy-async` is in `require` and resolves (suite green after `composer update`).
- [ ] Admin cadence no longer has three independent gates; throttle adopted or deviation noted.
- [ ] Restart detection lives in one place; no double-reset; `Sampler` consumes it.
- [ ] Full suite green.

## Out of scope / defer
- Dead-class deletion (incl. possibly `StatusPoller`) → STEP 7.3; leave a `NOTE:` on its fate.
