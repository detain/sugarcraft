# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-17 @ code tip `1f92f70c8` (round-85 W1 weld chain: base `988696aea` (r86u absorption) →
picks `b8b702618`(u1-a)/`11992bf81`(u1-b)/`23aea4386`(u2)/`be0b010d6`(u3, ONE keep-both docblock conflict)/
`1f92f70c8`(companion fold)) — **FULL REGEN from scratch at the round-85 wave-1 close** (supersedes the
round-84 `7a3280759`-base cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 3 rows below): ACTIONABLE = 3 BY ROW CENSUS**
(OPEN-table 3 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the four triage section
tables (awk strict-prefix survivor recount, exact command + output pasted in `crush_code_RESUME.md`
§0-NOW-87 §2(a); pre-wave 2 → **E731** trait-sweep renote STAYS OPEN, **E736** candy-forms F1+F3 renote
STAYS OPEN, **E741** MINTED from u2's disclosed seam (Input trait-drop family) → post-census **3**:
**E731 E736 E741**) and cross-confirmed against the picks (round-85 lanes u1–u3, four picks one weld +
companion — **E731** half: five shared traits (`ChartBorderStyle`, `SparklineScaling`,
`HeatmapColorScale`, `PriceAxisProjection`, `AxisLabelFormatter`), 17+1 content pins, 65-fixture
render byte-identity, COMP-2 door-probes + option-(b) design paragraph (ADOPTED per orchestrator ruling —
render pending u6), progressRing example + 2 goldens repaired; **E736** candy-forms: Confirm trait-drop
FIX + F1 docs (u2) and Cursor re-key / valuesMemo / ViewportPan / length-cache / 8.4-compat (u3);
`1f92f70c8`: getHeatChar divergence pin + SparklineScaling docblock property truth per rv-u1 MINORs).
**One MINT at this close (E741).** The survivor recount returns exactly 3, never chained.

CLOSED rows are PRUNED at this regen (nothing flipped CLOSED this wave — the two umbrellas renoted in
place; their close records live when they DO close, per the §0-NOW-87 §2(c) posture). Round-85 wave-1 lane
letters **u1 u2 u3** are RETIRED at this close (all their files land inside still-actionable rows — the
`⚠` marks below carry them); **u4** and **u6** are the wave-2 roster in §0-NOW-87 §2(b). U retired sets:
t (r84), r/s (r83), q1–q18 (r82), pa–pg (r81), ob/oc (r80), na (r79), ma/mb (r78), la/lc/lb (r77).

**Scheduling warnings (the round-85 wave-2 point):** u4 (candy-forms F2 = Phase-2 perf + Phase-6 async +
E741 folded) and u6 (sugar-dash COMP-2 (b) `'n/a'` sentinel render) are **file-disjoint** — safe to run in
parallel. u4 owns `candy-forms/src/Field/Input.php` + `candy-forms/src/Select/Select.php` (workerPool
threading) + E741 carrier work; u6 owns `sugar-dash/src/Modules/System/SystemModule.php` +
`sugar-dash/src/Modules/Uptime/UptimeModule.php` + both `view()`s. Before any NEXT E736 lib opens, take
the §0-NOW-87 §2(c) OPERATOR DECISION POINT (continue wave — sugar-bits candidate ONLY after re-deriving
its plan against the tree: the not-started/8-phases header claim was STALE for candy-forms — or close).
**No lane may touch `sugar-crush/src/Cli/Bootstrap.php`, `sugar-crush/src/Chat.php`,
`scripts/parallel-tests.sh`, or the figure files without re-arming the census family** (s4's lesson: three
of four W3 files there are census-guarded in-step).

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) HELD CLEAN through r85
(keep it in every src-touching brief's gate list — including sibling-lib briefs touching lib src). The
serial-only census law stands — the r85 weld moved ZERO sugar-crush files, so the r86u serial
(12,027/170,392/1S/exit0 @ `988696aea`) is carried EXACT and the weld gate was the targeted family +
five-guard 125T/7362A + both repo tools. SHARD GATE (s4/E737): shards launch with
`SUGARCRUSH_MCP_DISABLE=1`; launch-asserting tests arm via `tests/Support/McpLaunchEnabledTrait.php` — any
new MCP-launch test MUST use the trait or it reddens only in-shard. `scripts/parallel-tests-durations.tsv`
is SUGAR-CRUSH-ONLY — **532 rows HELD** (zero new crush test files this weld, set-diff empty by
construction); `failOnWarning` is UNIVERSAL (58/58) — every lane gate expects ZERO Warnings (dash 0W,
forms 0W at this weld). Harness law UNCHANGED: serials run through the PLAIN BASH-TOOL PIPE — NEVER
tmux/PTY. Determinism law (r82-q9) unchanged. The per-section ledger-edit law (r77 lc wipe) governed this
closeout's flips (backlog: scoped per-section split + assert-1, numstat 7/0; triage: two anchored row
swaps + one appended row, 3/2; RESUME: whole-region replace + banner swap, verbatim awk block re-executed
→ `E731 E736 E741`; root pointer: single-paragraph 1/1; far canaries intact — `### E740`, ROUND-84,
ROUND-85 headings all present). Config-md5 at-rest truth **`d96e124ee7967eb34ef479ef824231ad`**
(start==end at the weld). **LINK CENSUS POST-r86u: sugar-crush 19/19 + candy-pty 8/8** — the pre-merge
18/18+7/7 expectation is STALE (upstream added candy-mosaic→candy-flip, candy-vt→candy-async); fresh
sandboxes verify the census BEFORE briefing (r76 lesson).

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and
the §0-NOW-87 roster, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed** — EXCEPT where the citation is inside a sibling-lib row (then lib-relative paths resolve under that lib).
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`, `docs/MATCHUPS.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-bits`, …) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `docs/plans/leftover/`).
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠<lane>` = RETIRED lane letter — marks a file where that lane landed while the row stayed actionable.

## Table (one row per actionable id, ledger order)

| id | status | files touched | domain |
|---|---|---|---|
| E731 | OPEN ⚠u1 ⚠companion | sugar-dash/src/Plot/Chart/ (35-file family; five traits landed `⚠u1`: ChartBorderStyle/SparklineScaling/HeatmapColorScale/PriceAxisProjection/AxisLabelFormatter; remaining = structural duplicates + divergent near-twins with STOP records), sugar-dash/src/Modules/System/SystemModule.php (COMP-2 (b) `'n/a'` sentinel → u6; door-probes landed `⚠u1`), sugar-dash/src/Modules/Uptime/UptimeModule.php (u6 shared surface `⚠u1`), sugar-dash/src/Dashboard/ (both view() gates per design paragraph — u6), sugar-dash/tests/ (memo-reset seam + render pins — u6) | sugar-dash architecture |
| E736 | OPEN ⚠u2 ⚠u3 | docs/MATCHUPS.md (candy-forms row :49 "extraction in progress" STALE — re-mark at row close; + per-port AGENTS adding-a-lib touchpoints per lib: root composer.json, MATCHUPS, PROJECT_NAMES, root README, docs/index.html, docs/_data/<slug>.{json,body.html}+gen-docs, media/icons, vhs.yml, codecov.yml), candy-forms/ REMAINDER: Phase-2 perf + Phase-6 async + Phase-5 scoping → u4 (plan file findings/plan_candy-forms.md header `status: not-started` STALE — rewrite by u4); then next lib per §0-NOW-87 §2(c) (sugar-bits candidate, RE-DERIVE ITS PLAN FIRST) | port completion |
| E741 | OPEN | candy-forms/src/Field/Input.php (withValidator :325, mutate :636, 10 `new self(` sites audited at weld), candy-forms/tests/ (survival pins + carrier-drop mutation, ConfirmTest:143 recipe), sugar-bits/+sugar-prompt façade smoke (class_alias consumers — UNMOVED trio is the gate) → owner lane u4 (F2, Input.php shared) | candy-forms defect family |

Sum: 3 rows = census 3. ✓

## Domain index

| domain | n | ids |
|---|---:|---|
| sugar-dash architecture | 1 | E731 |
| port completion | 1 | E736 |
| candy-forms defect family | 1 | E741 |

**Collision check for wave-2 (u4 vs u6):** u4 = `candy-forms/**`, u6 = `sugar-dash/src/Modules/** +
src/Dashboard/** + tests/**` — DISJOINT. `sugar-dash/src/Plot/Chart/` untouched by both (E731's structural
half stays unlaned). **docs/MCP.md collision cluster: CLOSED (r77–r81) — zero remaining actionable rows
cite it.** `sugar-crush/src/Cli/Bootstrap.php` + `sugar-crush/src/Chat.php` — zero remaining actionable
citations; treat both as census-guarded for any future lane.
