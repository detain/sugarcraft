# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-14 @ code tip `a5d0033d9` — **FULL REGEN from scratch at the round-81 close** (supersedes the
`effdbdd82` r80 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — ZERO rows below): ACTIONABLE = 0 BY ROW CENSUS —
PHASE-3 COMPLETE** (OPEN-table 0 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the
four triage section tables (awk strict-prefix survivor recount, exact command + EMPTY output pasted in
`crush_code_RESUME.md` §0-NOW-83 §2(a); pre-census 7 (the r80-close mints E704–E710) → post-census 0) and
cross-confirmed against the triage **ROUND-81 CLOSE** census paragraph (round-81 lanes pa–pg, three waves —
SEVEN rows CLOSED-in-place this close: **E704** by pa `291755f57`→pick `2452947ba` (+12T), **E708** by pb
`18eacb4e1`+fix `1a7d67823`→picks `4b767f85c`+`56a71d134` (+16T), **E706** by pc `73638bcda`→pick
`0bf64d08e` (+1T), **E709** by pd `02b59b1bb`→pick `fea4b3b26` (+3T), **E705** by pe `c60b01005`+fix
`f42ef1794`→picks `772ef1b03`+`2a33d175b` (+9T), **E707** by pf `244c6b2a8`→pick `ab21cfd48` (+57T),
**E710** by pg `f73112e7b`→pick `0c347e995` (+23T); all rv APPROVE-class, merge companion `8dd48df9d` +
drift-fix `086035f50`, weld `a5d0033d9` floor 11,991/172,259). **Zero mints.** The survivor recount returns
exactly 0, never chained.

CLOSED rows are PRUNED at this regen (E704–E710 close records live in the triage rows + backlog §E704-§E710
CLOSED paragraphs + worklog ROUND 81 — the map is a scheduling aid, not the history). Round-81 lane letters
(**pa**, **pb**, **pc**, **pd**, **pe**, **pf**, **pg**) are RETIRED at this close: every file they touched
lands inside a CLOSED row — no `⚠` marks are owed: a retired lane marks only a file where it landed while
the row stayed ACTIONABLE, and none did (pf/pg's DocFigureProseDriftTest co-hunks merged ZERO-conflict —
regions disjoint by construction, verified in the w3 merge). ob/oc (r80), na (r79), ma/mb (r78), la/lc/lb
(r77) stay retired.

**Scheduling warnings (the round-82 point):** there are NO schedulable lanes — the audit-derived ledger is
EMPTY end to end. The queue sits at RESUME §2(c): the operator's call (m0225) — FRESH AUDIT SWEEP of the
other 51 monorepo libs with probe-first scoping à la the phase-2/3 kickoffs, or bank completion. Do NOT
mint lanes before that call. Trigger-watch (NOT rows — each names its own mint condition): **NEW r81 — pa
paste-while-modal behavioral pin** (rv 1MINOR declined in-lane); **NEW r81 — ReadPathCensus `\`-prefix blind
spot** (census quote-prefix walk misses backslash-prefixed calls; zero live offenders measured); **NEW r81 —
pf maxOutputTokens threading seam** (TaskTool/Workflow keep defaults; ClaudeCode transport reads no request
ceiling); **E696-deny-residual** (a `denyPatterns` producer must ship the deny half ENFORCED),
**updateRegistration() redirect-churn** seam (nd §2.2), **E309** DenialKind product question, **E611**
tripwire, **E694/E25** re-severity gate, **E655 VOID** phantom ban, **LspClientDispatchPumpTest** ambient
flake, **Chat.php:8266** beginTurn wiring seam.

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) HELD CLEAN through r81
(r78–r81 — keep it in every src-touching brief's gate list). r81 EXTENDED the serial-only census law to a
FOURTH+ demonstration — the w3 serial1 redset was 6, not 2: pg buried the E678 doc-comment under its new
method (RuntimeNoticeSinkDeliveryTest stacking census), pg shipped an unaccounted `scandir($this->tempDir)`
(TreeWideGuardRoster, both arms), pf's new test moved only the environment HOME (OneSidedHomeSandbox); each
reproduced at its own lane tip alone, all healed by the guards' own prescriptions in disclosed drift-fix
`086035f50`. Lane filter lists AND K=8 (durations manifest silently drops unlisted new files) cannot see
whole-tree doc/comment/roster censuses — the full serial is the only complete gate; the drift-fix policy
(one disclosed commit) stays in the merge cadence. HARNESS LAW fixed at this close: serials run through the
PLAIN BASH-TOOL PIPE — NEVER tmux/PTY (stdin-pin trio reds on any PTY shape; w2 attempt-1 proof; the old
tmux-`< /dev/null` recipe is SUPERSEDED). The per-section ledger-edit law (r77 lc wipe) governed this
closeout's flips (backlog: scoped split + assert-1 + numstat 15/7 + far-canary at :16182 intact; triage:
seven per-line anchored swaps 7/7; RESUME: whole-region replace, census block byte-verified runnable).
Fresh-worktree vendor law (r78) + FULL linked refresh incl candy-pty (r76 lesson) stand in the restart
recipe. Durations regen via `--manifest` mode is run-free (r81: 522→529, set-diff = exactly the seven new
*Test.php — pf five, pg two — zero removals). Config md5 truth `05480c743aff302fd6c06c5a4a4c2210`
(start==end at the r81 weld).

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and
the ROUND-81 CLOSE census paragraph, normalized to repo-root paths:

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

**docs/MCP.md collision cluster: CLOSED — r81 added only a closeout-side enumeration flip (`mcp list|import`
at :445, guard-verified DocFigure 83T); zero remaining actionable rows cite it.**
`sugar-crush/src/Cli/Bootstrap.php` — zero remaining actionable citations (unchanged from r78).
`sugar-crush/src/Chat.php` — r81's pa/pe/pf landed inside CLOSED rows; pruned here like everything else.
