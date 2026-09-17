# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-17 @ code tip `0f26082fe` (round-85 W2 weld chain: base `ccb86e5fa` (w1 closeout) →
picks `ac82cf822`(u4-E741)/`c1cebb634`(u4-Ph2+6)/`5461ded56`(u4-censuses)/`2aba19b05`(u4-rvfix)/
`0f26082fe`(u6-COMP-2b)) — **FULL REGEN from scratch at the round-85 close** (supersedes the round-85
wave-1 `1f92f70c8`-base cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 1 row below): ACTIONABLE = 1 BY ROW CENSUS**
(OPEN-table 1 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the four triage section
tables (awk strict-prefix survivor recount, exact command + output pasted in `crush_code_RESUME.md`
§0-NOW-88 §2(a); wave-1 closed at 3 → **E731** CLOSED (trait sweep + COMP-2 (b) landed) and **E741** CLOSED
(shared-carrier family fix) at this weld → post-census **1**: **E736**) and cross-confirmed against the
picks (round-85 wave-2: u4 four picks — `CarriesNonCtorState.php` carrier trait wired into 6 Field classes
across 25 `new self(...)` sites + fail-closed `TraitStateCarryFamilyTest` roster + Phase-2 perf caches +
single-pass validateAll + Phase-6 opt-in `fetchTimeoutSeconds` (null⇒byte-identical) + existence censuses +
rv fix-round; u6 one pick — `sugar-dash/src/Module/ProcAvailability.php` probe-once memo + `'n/a'`
sentinel render in System/Uptime modules on /proc-absent hosts). **Zero mints at this close.** The survivor
recount returns exactly 1, never chained.

CLOSED rows are PRUNED at this regen: **E731** closes with the five-trait sweep (wave-1,
`b8b702618`/`11992bf81`/`1f92f70c8`) + COMP-2 option-(b) implementation (wave-2, `0f26082fe`) — close
record in backlog §E731, honest residuals (race-leg non-latch UNPINNED, readMemLoad clamp
kernel-invariant) recorded not-minted; **E741** closes with the shared-carrier family fix (`ac82cf822`) —
Confirm private-carrier coexistence fold-note recorded in backlog §E741. Round-85 wave-2 lane letters
**u4 u6** are RETIRED at this close (their files land inside E736's still-actionable umbrella — the `⚠`
marks carry them); **u1 u2 u3** were retired at the wave-1 close. U retired sets: u (r85), t (r84),
r/s (r83), q1–q18 (r82), pa–pg (r81), ob/oc (r80), na (r79), ma/mb (r78), la/lc/lb (r77).

**Scheduling warnings (the round-86+ point):** the ONLY actionable row is the E736 port umbrella — the
operator decision (§0-NOW-88 §2(b)) gates everything: cut sugar-bits (MANDATORY per-lib re-derivation of
`findings/sugar-bits*` against the tree FIRST — zany-beige-roadrunner law), or run the forms Phase-5
product-scoping session, or close. Remaining 🟡 queue to enumerate before committing: sugar-charts,
candy-shine, candy-vt, sugar-layout, candy-shell. ONE lib per wave, full AGENTS.md add-a-lib checklist.
**No lane may touch `sugar-crush/src/Cli/Bootstrap.php`, `sugar-crush/src/Chat.php`,
`scripts/parallel-tests.sh`, or the figure files without re-arming the census family** (s4's lesson: three
of four W3 files there are census-guarded in-step).

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) HELD CLEAN through r85
(keep it in every src-touching brief's gate list — including sibling-lib briefs touching lib src). The
serial-only census law stands — the r85 welds moved ZERO sugar-crush files across BOTH waves, so the r86u
serial (12,027/170,392/1S/exit0 @ `988696aea`) is carried EXACT through `0f26082fe` and each weld gate was
the targeted family + five-guard 125T/7362A + both repo tools. SHARD GATE (s4/E737): shards launch with
`SUGARCRUSH_MCP_DISABLE=1`; launch-asserting tests arm via `tests/Support/McpLaunchEnabledTrait.php` — any
new MCP-launch test MUST use the trait or it reddens only in-shard. `scripts/parallel-tests-durations.tsv`
is SUGAR-CRUSH-ONLY — **532 rows HELD** (zero new crush test files this round, set-diff empty by
construction); `failOnWarning` is UNIVERSAL (58/58) — every lane gate expects ZERO Warnings (forms 0W, dash
0W at this weld). Harness law UNCHANGED: serials run through the PLAIN BASH-TOOL PIPE — NEVER tmux/PTY.
Determinism law (r82-q9) unchanged. The per-section ledger-edit law (r77 lc wipe) governed this closeout's
flips (backlog: scoped per-section split + assert-1, numstat 9/2; triage: three anchored row swaps, 3/3;
worklog: one inserted subsection, 15/0; RESUME: whole-region replace + banner swap, verbatim awk block
re-executed → `E736`; root pointer: single-paragraph 1/1; far canaries intact — `### E740`, ROUND-80…84
headings, APPENDIX I–V all present). Config-md5 at-rest truth
**`d96e124ee7967eb34ef479ef824231ad`** (start==end at the weld). **LINK CENSUS POST-r86u: sugar-crush
19/19 + candy-pty 8/8** — the pre-merge 18/18+7/7 expectation is STALE; fresh sandboxes verify the census
BEFORE briefing (r76 lesson).

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and
the §0-NOW-88 roster, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed** — EXCEPT where the citation is inside a sibling-lib row (then lib-relative paths resolve under that lib).
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`, `docs/MATCHUPS.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-bits`, …) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `docs/plans/leftover/`).
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠<lane>` = RETIRED lane letter — marks a file where that lane landed while the row stayed actionable.

## Table (one row per actionable id, ledger order)

| id | status | files touched | domain |
|---|---|---|---|
| E736 | OPEN ⚠u2 ⚠u3 ⚠u4 | docs/MATCHUPS.md (candy-forms row :49 "extraction in progress" STALE — re-mark at row close; + per-port AGENTS adding-a-lib touchpoints per lib: root composer.json, MATCHUPS, PROJECT_NAMES, root README, docs/index.html, docs/_data/<slug>.{json,body.html}+gen-docs, media/icons, vhs.yml, codecov.yml), candy-forms/ Phase-5 ONLY (product-scoping decision — Phases 1–4/6–8 COMPLETE at this close, forms 1891T/3164A/0W; plan file findings/plan_candy-forms.md needs its `status:` header rewrite by the scoping session); then next lib per §0-NOW-88 §2(b) (sugar-bits candidate — RE-DERIVE findings/plan_sugar-bits.md AGAINST THE TREE FIRST; then charts/shine/vt/layout/shell to enumerate) | port completion |

Sum: 1 row = census 1. ✓

## Domain index

| domain | n | ids |
|---|---:|---|
| port completion | 1 | E736 |

**Collision check:** single actionable row — no intra-wave collision possible. Within E736, a forms-Ph5
session touches `candy-forms/**` (docs/decisions only, no code until scoped) while a sugar-bits wave would
touch `sugar-bits/**` + the add-a-lib docs touchpoints — disjoint surfaces, but BOTH cannot be cut without
the §2(b) operator ruling. `sugar-dash/**` and the candy-forms defect surface are CLOSED territory at this
tip (u1–u6 landed); future lanes there reopen via new findings, not these rows.
`docs/MCP.md` collision cluster: CLOSED (r77–r81). `sugar-crush/src/Cli/Bootstrap.php` +
`sugar-crush/src/Chat.php` — zero remaining actionable citations; treat both as census-guarded for any
future lane.
