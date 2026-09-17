# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-17 @ code tip `7988f2305` (round-87 weld chain: base `cfb26f8f2` (r86 closeout) →
picks `f48537daf`/`c37486747`/`848f1fbb0`/`232686276` (w1 candy-vt)/`66debe01c` (w2 sugar-bits)/
`971c0fa96` (w3 sugar-charts) → `7988f2305` (companion: §E742 MINT + folds)) — **FULL REGEN at the
round-87 close** (supersedes the round-86 `cfb26f8f2`-base cut); regenerate this file at every
round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 2 rows below): ACTIONABLE = 2 BY ROW CENSUS**
(OPEN-table 2 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the four triage
section tables (awk strict-prefix survivor recount, exact command + output pasted below and at
`crush_code_RESUME.md` §0-NOW-90 §2(a); r86 left 1 survivor — the round-87 weld dispositioned the ENTIRE
E736 tail (w1 built 7 vt rows + gated 6.2 + deferred 6.4-with-trigger; w2 CLOSED bits 2.4; w3 dispositioned
charts P5 4/4) and MINTED §E742 from the rv-w1 seam → post-census **2**: **E736** + **E742**) and
cross-confirmed against the picks. **One mint at this close (§E742), zero closes of whole rows.** The
survivor recount returns exactly 2, never chained.

CLOSED rows are PRUNED (none at this regen — the two survivors pre-date it). Round-87 lane letters
**w1 w2 w3** are RETIRED at this close (their files land inside E736's still-actionable umbrella — the `⚠`
marks carry them). U retired sets: w (r87), v (r86), u (r85), t (r84), r/s (r83), q1–q18 (r82),
pa–pg (r81), ob/oc (r80), na (r79), ma/mb (r78), la/lc/lb (r77).

**Scheduling warnings (the round-88 point):** BOTH survivors are decision-shaped, not defect work —
§E742 needs an orchestrator RULING (correct-to-xterm vs document-fallback) BEFORE any build; §E736's
residual tails are ALL trigger-gated (forms Ph5 on real consumer demand; vt 6.4 on a Buffer row-shift-API
demand; MATCHUPS port completions per the full add-a-lib checklist, one lib per wave, only when scheduled).
The campaign is MECHANICALLY COMPLETE per the operator (m0225) — round 88 ONLY on operator direction;
Phase-12 stays PAUSED (never auto-resume). Re-derive row anchors at lane base before building
(zany-beige-roadrunner law — held again at r87: w1's 6.2 consumer sweep, w3's 5.1 verified-landed stamp,
w2's P2 honest re-measure 2→1 were all probe-first).
**No lane may touch `sugar-crush/src/Cli/Bootstrap.php`, `sugar-crush/src/Chat.php`,
`scripts/parallel-tests.sh`, or the figure files without re-arming the census family** (s4's lesson: three
of four W3 files there are census-guarded in-step).

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) stays in every
src-touching brief's gate list (including sibling-lib briefs touching lib src). The serial-only census law
stands — the r85 BOTH waves, the r86 weld AND the r87 weld moved ZERO sugar-crush files, so the r86u serial
(12,027/170,392/1S/exit0 @ `988696aea`) is carried EXACT through `971c0fa96` — **FOUR** rounds running the
welds moved zero crush files — and each weld gates the touched libs FULL + the targeted family + five-guard
125T/7362A + both repo tools. SHARD GATE (s4/E737): shards launch with
`SUGARCRUSH_MCP_DISABLE=1`; launch-asserting tests arm via `tests/Support/McpLaunchEnabledTrait.php` — any
new MCP-launch test MUST use the trait or it reddens only in-shard. `scripts/parallel-tests-durations.tsv`
is SUGAR-CRUSH-ONLY — **532 rows HELD** (zero new crush test files this round, set-diff empty by
construction); `failOnWarning` is UNIVERSAL (58/58) — every lane gate expects ZERO Warnings (vt/bits/charts
0W at this weld). Harness law UNCHANGED: serials run through the PLAIN BASH-TOOL PIPE — NEVER tmux/PTY.
Determinism law (r82-q9) unchanged. NEW PIN LAW (r87-w2): pins for ADJUST-GATING defects must be
WALK-FORM (replay the interaction) — the withActive-jump form demonstrably missed the seed-neuter mutation.
NEW MEASUREMENT LAW (r87-w2/rv): a `Mark::zone` span costs ≈13 cells to `Width::of` — geometry pins measure
STRIPPED rows. The per-section ledger-edit law (r77 lc wipe) governed this closeout's flips (backlog:
scoped per-section split + assert-1, numstat 6/0 total across companion+stamps; triage: two anchored row
edits, +2/−1; worklog: one inserted section, 9/0; RESUME: whole-region replace + banner swap, verbatim awk
block re-executed → `E736` + `E742`; root pointer: single-paragraph 1/1; far canaries intact — `### E741`,
`### E475`, ROUND-80…85 headings, APPENDIX I–V all present). Config-md5 at-rest truth
**`d96e124ee7967eb34ef479ef824231ad`** (start==end at the weld). **LINK CENSUS POST-r86u: sugar-crush
19/19 + candy-pty 8/8** — fresh sandboxes verify the census BEFORE briefing (r76 lesson).

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and
the §0-NOW-90 roster, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed** — EXCEPT where the citation names a sibling lib (`candy-…`, `sugar-dash`, `sugar-bits`, …), in which case the bare paths belong to that lib's directory.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`, `docs/MATCHUPS.md`) stay as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-bits`, …) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `docs/plans/leftover/`).
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠`-free note: `⚠<lane>` = RETIRED lane letter — marks a file where that lane landed while the row stayed actionable.

## Row census (exact command + verbatim output, re-run at the filemap regen)

```
$ awk -F'|' '/^## `OPEN`/{sec="OPEN"} /^## `PARTIAL`/{sec="PARTIAL"}
    /^## `STALE-CITATION`/{sec="STALE"} /^## `UNCERTAIN`/{sec="UNCERTAIN"}
    /^## `SUPERSEDED`/{sec=""} /^\| \*\*E/{gsub(/^ +| +$/,"",$4);
    if (sec!="" && $4 !~ /^\*\*CLOSED/) print sec" "$2}' docs/plans/crush_code_backlog_triage.md
OPEN  **E736** 
OPEN  **E742** 
```

## Table (one row per actionable id, ledger order)

| id | status | files touched | domain |
|---|---|---|---|
| E736 | OPEN ⚠u2 ⚠u3 ⚠u4 ⚠v1 ⚠v2 ⚠v3 ⚠v4 ⚠v5 ⚠w1 ⚠w2 ⚠w3 | umbrella — remaining shape ALL trigger/ruling-gated: forms Phase-5 `candy-forms/` (DECLINED r86-v6, reopen on demand); vt 6.4 `candy-vt/src/Parser/CsiHandlerImpl.php` + a NEW `candy-vt/src/Buffer/Buffer.php` row-shift API (DEFERRED-WITH-TRIGGER post-w1); MATCHUPS 🟡/🔴 ports `docs/MATCHUPS.md` + per-lib `candy-*/`/`sugar-*/` skeletons on operator schedule (one lib per wave, full add-a-lib checklist); shine/layout/bits/charts declines ride here. See backlog §E736 + `findings/plan_candy-vt.md` (tail fully stamped at r87). | port-queue umbrella |
| E742 | OPEN (MINTED r87 weld, from rv-w1 seam) | `candy-vt/src/Theme.php` (cubePalette() + base-colors union), `candy-vt/src/Color.php`, vt goldens + any consumer snapshot carrying 256-color SGR (`candy-vcr/tests/`, `candy-pty/tests/`, crush `Vt\|Terminal\|Buffer` window); pre-requisite: orchestrator RULING correct-to-xterm vs document-fallback — NOT auto-built (behavior-change STOP). | candy-vt palette |
