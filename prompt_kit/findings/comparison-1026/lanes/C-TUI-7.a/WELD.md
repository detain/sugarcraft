# C-TUI-7.a — WELD (W12, single-lane batch)

Welded onto master `cc14518d5` (weld: C-WEB-7.b). Lane base `b38baf0f8`. Never pushed.

## Picks

| lane | master | note |
|---|---|---|
| e003ffa15 | 97113b210 | build commit; 1 conflict (below) |
| fa64a39b9 | 03280fdea | review fixes (round 2); applied clean, range-diff `=` (byte-identical) |

Author/committer both detain@interserver.net on both picks (separate `%ae`/`%ce` greps), zero trailers.
range-diff `b38baf0f8..fa64a39b9` vs `cc14518d5..03280fdea`: the only delta is the
RouteTraitParityTest union hunk — everything else absorbed exactly.

## Conflict (expected, per lead brief)

`candy-code/tests/Chat/RouteTraitParityTest.php` (brief said `tests/App/` — same file, lives under Chat/):
kept MASTER's `ModelCatalogReadyMsg` + `TelemetryRetryTickMsg` (commented) rows and added the lane's
`StartupSummaryGatheredMsg` row after `StatusLineTickMsg`; dropped the lane's duplicate
`TelemetryRetryTickMsg` + its now-stale cure comment (master cured it via C-PRV-5.c). Roster test
sorts both sides before comparing, so row order is free. No other file conflicted (en.php append rode clean).

## Gates (merged tree, cwd candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'StartupSummary'` → **OK 24T/475A** (exact vs brief)
- `--filter 'StartupSummaryGatherTest|RouteTraitParity|AppModel'` → **OK 71T/820A** (exact)
- `--filter 'MacAltNotice'` (same-file neighborhood untouched sanity) → **OK 12T/56A**
- Each run carries `PHPUnit Deprecations: 2` — **foreign AccessorDeprecation deprecations**:
  private data providers in `tests/Config/AccessorDeprecationTest` (rulings-1024-era file, zero lane
  diff lines), also present in the untouched MacAltNotice run. Weld glance only, no action.
  PtyRunToolTest never in any filter per standing rule.

## Regenerate — none

Pick diff (`cc14518d5..03280fdea`, 14 files) touches zero `src/Protocol/**`, schema, generated-docs,
`generated.ts`, or `dist/` paths — verified by name-only grep; no generator invocation required.

## scripts commit (separate)

`a5f5b15b8 scripts:` — durations.tsv row `candy-code/tests/Chat/StartupSummaryGatherTest.php 0.727 6`
(junit per-testcase sum at merged tip 03280fdea; tie with TurnInboxDrainTest 0.727 → landed after it,
before DoctorBypassNoticeProbeTest 0.726; set-equality both directions proven: tsv gained exactly this
row, zero orphans, pre-existing 73 file-without-row + 4 desc-violations unchanged, zero added).
suite-figure.json UNTOUCHED per brief.

## Carries (recorded, not fixed)

- Foreign `AccessorDeprecationTest` provider deprecations (2/run) — weld glance, above.
- `ReadmeSuiteFigureDrift` staleness pair: lane REPORT proves it was ALREADY red at lane base
  (foreign drift accumulation, lane +8 methods rides it); re-pin is gate-owned (§7 closeout),
  out of this weld's brief.
- 73 tracked `*Test.php` files without durations rows (other lanes' closeout debt) — unchanged here.
