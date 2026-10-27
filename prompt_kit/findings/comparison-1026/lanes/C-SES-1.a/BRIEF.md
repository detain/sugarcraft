### C-SES-1.a — Headless `-p` spend reaches the ledger; `session list` shows spend
- item: C-SES-1 (sources: SES-1-P1, p2-miss: headless `-p` spend, SES-1-P4 `session list` half)
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause.** `NonInteractive::run()` collects each step's `Usage` into `$sessionUsages` (`src/Cli/NonInteractive.php:405-409`), but only for the JSON envelope (`:609`). `appendUsageRecord` has exactly two callers, `src/Chat.php:19002` and `src/Host/SessionHost.php:2267`, so a `-p` run persists its session row (the AUT-2 block, `:653-660`) without its money. `/usage`, `/budget` after `--resume`, and `/status`'s ledger row then all under-report.
  - **Fix.** In the AUT-2 persist block, after `$mint()`, send each buffered usage through `SpendLedger::new()->account(new TokenTracker(), $usage, $sink, $backend->model())`. The sink calls `EnhancedSessionStore::appendUsageRecord($opened['id'], …)`.
    - Gate it on `$ownsSession && $opened !== null && $sessionStore instanceof EnhancedSessionStore`.
    - Put it inside the same `try`, so a store failure costs one stderr line and leaves the exit code unchanged (the "store must not cost the answer" law).
  - **`session list`.** Its text rows gain the cached spend, printed with `SpendLedger::boundUsd($row->costUnpriced, $row->costUsd)` (`≥` when unpriced, `—` when null). Its `--json` rows gain `spentUsd` and `spendUnpriced`.
  - **What the user sees:** `-p` spend in `/usage`, and every row of `candy-code session list` showing its cost.
- files.create: none
- files.modify: `candy-code/src/Cli/NonInteractive.php` (run(): AUT-2 persist block), `candy-code/src/Cli/Subcommands.php` (sessionList(), sessionLabel()), `candy-code/lang/en.php` (`cli.session.list.spend*`), `candy-code/README.md` (Non-interactive mode: the session-persistence paragraph; Subcommands: the `session list` sample)
- tests: create `candy-code/tests/Cli/NonInteractiveUsageLedgerTest.php`. It pins:
  - a priced echo-provider run → `usageTotals(id)` is non-null;
  - a `--resume` run appends to the same id;
  - unpriced usage sets `unpriced`;
  - a throwing store still exits 0, with exactly one stderr line.
  
  Modify `candy-code/tests/Cli/SessionSubcommandsTest.php`: the spend column is exact when priced and `≥` when unpriced; the JSON keys are present.
- docs/guards: the README.md paragraphs only. No new verb, flag or env var, so no roster guard moves.
- serialized: [headless] [lang:cli.session.list.spend] [docs:README.md]
- deps: none
- phase: normal
- ruling: none
- risks: The lazy mint must run before the first append. Buffering the appends to the persist block guarantees that; never append inside the step observer.

