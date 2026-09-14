# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-14 @ code tip `710544154` — **FULL REGEN from scratch at the round-80 close** (supersedes the
`ca9aac147` r79 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — ZERO rows below): ACTIONABLE = 0 BY ROW CENSUS —
PHASE-2 COMPLETE** (OPEN-table 0 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the
four triage section tables (awk strict-prefix survivor recount, exact command + EMPTY output pasted in
`crush_code_RESUME.md` §0-NOW-82 §2(a); pre-census 1 → post-census 0) and cross-confirmed against the triage
**ROUND-80 CLOSE** census paragraph (round-80 lanes ob+oc — ONE row CLOSED-in-place this close: **E699** by
oc's gated `claude-mcp` double opt-in per the operator ruling "wire it" (lane `db93dbe16`+`a489db1da`+
`54cc5afac`+`2f87b4f8b`+fix `8b7989af1` → picks `ad8dfda12`+`e438c9dca`+`f0ef430fc`+`b57ed8fec`+`4b9ab1258`
ALL CLEAN, merge drift-fix `b11a34ca3` (stacked-docblock unburial), weld `710544154` floor 11,870/170,786,
rv APPROVE-WITH-FIX 0C/1MAJOR+4MINOR healed in fix-round) + ob's test-hygiene tempnam family close
(`ce41d8c9f` → pick `896c18205`, rv APPROVE 0C/0M, +0T — no row, it consumed the r79 trigger-watch).
**Zero mints.** The survivor recount returns exactly 0, never chained.

CLOSED rows are PRUNED at this regen (E699's close record lives in the triage row + backlog §E699 + worklog
ROUND 80 — the map is a scheduling aid, not the history). Round-80 lane letters (**ob**, **oc**) are RETIRED
at this close: every file they touched lands inside what CLOSED/what was hygiene (oc: new
`src/MCP/ClaudeCodeMcpServer.php`, `ClaudeCodeMcpClient.php` ctor/isUp/pumpStderr carry, Bootstrap grant
reader + factory arm, MCP.md/SETTINGS.md in-step, DocFigure widen + new arm, 3 new test files; ob: 3 test
cleanups) — no `⚠` marks are owed: a retired lane marks only a file where it landed while the row stayed
ACTIONABLE, and none did. na (r79), ma/mb (r78), la/lc/lb (r77) stay retired.

**Scheduling warnings (the round-81 point):** there are NO schedulable lanes — the phase-2 queue terminated
at E699's ruling. Round-81 lanes (p*) mint ONLY from adjudicated probes of the three operator-reported UX
findings recorded in RESUME §0-NOW-82 §2(c): multiline/paste input handling; possible reasoning truncation;
MCP config-shape compat for opencode users. Trigger-watch (NOT rows — each names its own mint condition):
**E696-deny-residual** (if a `denyPatterns` config producer is ever minted, the deny half ships ENFORCED in
the same change), **updateRegistration() redirect-churn** seam (nd §2.2), **E309** DenialKind product
question, **E611** tripwire, **E694/E25** re-severity gate, **E655 VOID** phantom ban,
**LspClientDispatchPumpTest** ambient flake, **Chat.php:8266** beginTurn wiring seam. The r79 tempnam
hygiene watch is **STRUCK** — ob closed the whole family (capture-base-clean-both idiom).

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) HELD CLEAN through r80's
serial2 weld (keep it in every src-touching brief's gate list). r80 re-proved the serial-only law for
whole-tree doc/comment censuses (stacking catch = third co-shard class, after r77-w1 SwallowingCatch and
r79's roster pair): lane filter lists CANNOT see them; the drift-fix policy (one disclosed commit) stays in
the merge cadence. The per-section ledger-edit law (r77 lc wipe) governed this closeout's E699 flips
(scoped re.split + assert-1 + numstat + far-canaries: backlog heading count 697 stable, 'Ledger-edit law
recorded' survives, worklog '## Round 55' survives, triage ROUND-78 CLOSE survives, APPENDIX count 5
stable). Fresh-worktree vendor law (r78) + FULL linked refresh incl candy-pty (r76 lesson) stand in the
restart recipe. Durations regen via `--manifest` mode is run-free (r80: set-diff = exactly oc's 3 new
*Test.php, 515→518). Config md5 truth `05480c743aff302fd6c06c5a4a4c2210` (start==end at the r80 weld).

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and
the ROUND-80 CLOSE census paragraph, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/`).
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠<lane>` = RETIRED lane letter — marks a file where that lane landed while the row stayed
  actionable. NONE owed at this regen (see retirement paragraph).

## Table (one row per actionable id, ledger order)

| id | status | files touched | domain |
|---|---|---|---|
| — | (empty — ACTIONABLE = 0) | — | — |

Sum: 0 rows = census 0. ✓

## Domain index

| domain | n | ids |
|---|---:|---|
| — | 0 | (none) |

**docs/MCP.md collision cluster: CLOSED — the last cite (E699's doc in-step) landed with oc; zero remaining
actionable rows.** `sugar-crush/src/Cli/Bootstrap.php` — zero remaining actionable citations (unchanged from
r78; oc's grant-reader/factory edits are CLOSED content). `sugar-crush/src/ClaudeCodeMcpClient.php` — was
the sole survivor's cite; CLOSED ROUND-80, pruned here.
