# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-17 @ code tip `5c9cd321f` (round-86 W1 weld chain: base `0ab8555bf` (v6 rulings) →
picks `c887c1458`(v1-shell)/`86dd9807c`(v2-layout)/`2d11ab830`(v3-bits)/`7dfa8de45`(v4-charts)/
`3696b7bf7`(v5-vt docs)/`5c9cd321f`(companion folds)) — **FULL REGEN at the round-86 wave-1 close**
(supersedes the round-85 `b26b9989f`-base cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 1 row below): ACTIONABLE = 1 BY ROW CENSUS**
(OPEN-table 1 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the four triage section
tables (awk strict-prefix survivor recount, exact command + output pasted below and at
`crush_code_RESUME.md` §0-NOW-89 §2(a); r85 left 1 survivor — the r86 wave-1 welds closed only lib
SUBSCOPES inside the umbrella (shell/layout CLOSED-subscope, bits substantial-close, charts P2–P4,
vt re-derived to a 9-row tail) → post-census **1**: **E736**) and cross-confirmed against the
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

**Scheduling warnings (the round-87 point):** the ONLY actionable row is the E736 port umbrella — the
ROUND-87 CUT is pre-approved at §0-NOW-89 §2(b): w1 candy-vt cheap lane (9-row tail; 6.2 gated on the
consumer set-ordering verification; 6.4 CARVED for a post-w1 ruling), w2 sugar-bits 2.4, w3 sugar-charts
P5 verify. Re-derive row anchors at lane base before building (zany-beige-roadrunner law — held again at
r86: v2's Tableau SHA, v5's feedAsync attribution, v3/v4's landed-already stamps were all caught by
probe-first). forms/shine DECLINED at v6 rulings — do NOT re-cut without new consumer demand. ONE lib per
wave, full AGENTS.md add-a-lib checklist for any final re-mark.
**No lane may touch `sugar-crush/src/Cli/Bootstrap.php`, `sugar-crush/src/Chat.php`,
`scripts/parallel-tests.sh`, or the figure files without re-arming the census family** (s4's lesson: three
of four W3 files there are census-guarded in-step).

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) HELD CLEAN through r85
(keep it in every src-touching brief's gate list — including sibling-lib briefs touching lib src). The
serial-only census law stands — the r85 welds moved ZERO sugar-crush files across BOTH waves, so the r86u
serial (12,027/170,392/1S/exit0 @ `988696aea`) is carried EXACT through `5c9cd321f` — THREE rounds running the welds moved zero crush files — and each weld gate was
the targeted family + five-guard 125T/7362A + both repo tools. SHARD GATE (s4/E737): shards launch with
`SUGARCRUSH_MCP_DISABLE=1`; launch-asserting tests arm via `tests/Support/McpLaunchEnabledTrait.php` — any
new MCP-launch test MUST use the trait or it reddens only in-shard. `scripts/parallel-tests-durations.tsv`
is SUGAR-CRUSH-ONLY — **532 rows HELD** (zero new crush test files this round, set-diff empty by
construction); `failOnWarning` is UNIVERSAL (58/58) — every lane gate expects ZERO Warnings (forms 0W, dash
0W at this weld). Harness law UNCHANGED: serials run through the PLAIN BASH-TOOL PIPE — NEVER tmux/PTY.
Determinism law (r82-q9) unchanged. The per-section ledger-edit law (r77 lc wipe) governed this closeout's
flips (backlog: scoped per-section split + assert-1, numstat 2/0; triage: one anchored row swap, 1/1;
worklog: one inserted section, 20/0; RESUME: whole-region replace + banner swap, verbatim awk block
re-executed → `E736`; root pointer: single-paragraph 1/1; far canaries intact — `### E740`, ROUND-80…84
headings, APPENDIX I–V all present). Config-md5 at-rest truth
**`d96e124ee7967eb34ef479ef824231ad`** (start==end at the weld). **LINK CENSUS POST-r86u: sugar-crush
19/19 + candy-pty 8/8** — the pre-merge 18/18+7/7 expectation is STALE; fresh sandboxes verify the census
BEFORE briefing (r76 lesson).

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and
the §0-NOW-89 roster, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed** — EXCEPT where the citation is inside a sibling-lib row (then lib-relative paths resolve under that lib).
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`, `docs/MATCHUPS.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-bits`, …) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `docs/plans/leftover/`).
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠<lane>` = RETIRED lane letter — marks a file where that lane landed while the row stayed actionable.

## Table (one row per actionable id, ledger order)

| id | status | files touched | domain |
|---|---|---|---|
| E736 | OPEN ⚠u2 ⚠u3 ⚠u4 ⚠v1 ⚠v2 ⚠v3 ⚠v4 ⚠v5 | r87 CUT per §0-NOW-89 §2(b): **w1** candy-vt 9-row tail (`src/Terminal/Terminal.php`, root `src/Terminal.php`, `src/Parser/CsiHandlerImpl.php`, `src/Handler/ScreenHandler.php`, `src/Screen/Screen.php` gated-6.2, `src/Theme.php`, `src/Sgr/Sgr.php`+`src/Cell.php` 2.3; findings/plan re-derivation already landed via v5 `3696b7bf7`) — **w2** sugar-bits `src/Tabs/Tabs.php` 2.4 scrollEnd consume — **w3** sugar-charts Phase-5 verify (`tests/`, coverage gaps). CLOSED-subscopes at r86 w1: candy-shell 12/12 (`c887c1458`), candy-layout P5/6 (`86dd9807c`), sugar-bits residue (`2d11ab830`), sugar-charts P2–P4 (`7dfa8de45`). forms Phase-5 DECLINED wholesale (v6 ruling — reopen only on consumer demand; `findings/plan_candy-forms.md` `status:` header stale-rewrite still pending its owning touchpoint). docs/MATCHUPS.md re-mark (candy-forms row :49 + the 🟡 rows for shell/layout/charts/vt-forms re-derivation outcomes) + per-port AGENTS adding-a-lib touchpoints at final row close | port completion |

**RECOUNT-BY-COMMAND at this regen** (never chained), run live at this closeout against
`docs/plans/crush_code_backlog_triage.md` after the stamp flips:

```
$ awk -F'|' '/^## `OPEN`/{sec="OPEN"} /^## `PARTIAL`/{sec="PARTIAL"}
    /^## `STALE-CITATION`/{sec="STALE"} /^## `UNCERTAIN`/{sec="UNCERTAIN"}
    /^## `SUPERSEDED`/{sec=""} /^\| \*\*E/{gsub(/^ +| +$/,"",$4);
    if (sec!="" && $4 !~ /^\*\*CLOSED/) print sec" "$2}' docs/plans/crush_code_backlog_triage.md
OPEN  **E736**
```

Sum: 1 row = census 1 (by the recount above). ✓

## Domain index

| domain | n | ids |
|---|---:|---|
| port completion | 1 | E736 |

**Collision check:** single actionable row — no intra-wave collision possible. Within E736 the r87 waves
are lib-disjoint BY DESIGN (vt `src/**` vs bits `src/Tabs/**` vs charts `tests/`+verify). CLOSED-subscope
territory at this tip: `candy-shell/**`+`findings/plan_candy-shell.md` (v1), `candy-layout/**` (v2),
`sugar-bits/**` except 2.4 (v3), `sugar-charts/**` except P5 (v4), `sugar-dash/**`+candy-forms defect
surface (u1–u6); future lanes there reopen via new findings, not these rows. forms Phase-5 is a DECLINED
decision record, not a lane.
`docs/MCP.md` collision cluster: CLOSED (r77–r81). `sugar-crush/src/Cli/Bootstrap.php` +
`sugar-crush/src/Chat.php` — zero remaining actionable citations; treat both as census-guarded for any
future lane.
