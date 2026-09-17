# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-17 @ code tip `b475df971` (round-88 weld chain: base `f22353b2e` (r87 closeout) →
picks `48846c8d9` (x6 candy-vt row-shift API)/`871107869` (x4 candy-forms Date/Slider/Color)/
`6e83c1675` (x5 candy-forms mouse+clipboard) → `e2ac4fc7e` (companion: rv folds + prompt README rows +
§E743/§E744 MINTS) → `b475df971` (suite-figure re-pin)) — **FULL REGEN at the
round-88 close** (supersedes the round-87 `7988f2305`-base cut); regenerate this file at every
round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 4 rows below): ACTIONABLE = 4 BY ROW CENSUS**
(OPEN-table 4 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — re-derived from the four triage
section tables (awk strict-prefix survivor recount, exact command + output pasted below and at
`crush_code_RESUME.md` §0-NOW-92 §1; r87 left 2 survivors — the round-88 weld promoted bits 3.1 to
**§E743** after lane x2 phantom'd and minted **§E744** from rv-x5 MINOR-1 (crush cannot reach the shipped
widget features), while **§E742 STAYS OPEN** (its fix lane x1 phantom'd — the weld refused the incoming
brief's [CLOSED] stamp) → post-census **4**) and
cross-confirmed against the picks. **Two mints at this close (§E743, §E744), zero whole-row closes
(vt 6.4's DEFERRED-with-trigger resolved BUILT via x6 inside the E736 umbrella).** The
survivor recount returns exactly 4, never chained.

CLOSED rows are PRUNED (none at this regen). Round-88 lane letters **x1 x2 x3 x4 x5 x6 x7** —
x4/x5/x6 RETIRED (files landed, umbrella row stays actionable); x1/x2/x3/x7 were PHANTOM at weld time
(no branch/commit/object — reviewer + weld disk audits agree), their design intents ride the re-cut
candidates below. U retired sets: x-partial (r88), w (r87), v (r86), u (r85), t (r84), r/s (r83),
q1–q18 (r82), pa–pg (r81), ob/oc (r80), na (r79), ma/mb (r78), la/lc/lb (r77).

**Scheduling warnings (the round-89 point):** ALL FOUR survivors are decision/feature-shaped, not
defect work — §E742 needs the orchestrator RULING (correct-to-xterm vs document-fallback) BEFORE any
re-cut; §E743/§E744 are standalone builds (capture-proof / Chat.php-touching respectively); §E736's
residual tails are trigger-gated (forms cluster A + shine streaming re-cuts only on direction; MATCHUPS
port completions per the full add-a-lib checklist). The campaign remains MECHANICALLY COMPLETE per the
operator unless re-directed. Re-derive row anchors at lane base before building (zany-beige-roadrunner
law — held again at r88: the WELD's salvage-first disk audit is the same law applied to the brief itself:
this brief carried four phantom SHAs and a stale shine baseline; disk over paper, always).
NEW PROCESS CANDIDATE (r88): builders commit EARLY (WIP acceptable) so mid-death work survives; weld
briefs must enumerate lane commits via `git log` at authoring time, never pre-baked SHAs.
**No lane may touch `sugar-crush/src/Cli/Bootstrap.php`, `sugar-crush/src/Chat.php`,
`scripts/parallel-tests.sh`, or the figure files without re-arming the census family** (s4's lesson;
§E744's scope hits Chat.php — census trio + DocFigure re-arms mandatory in-brief).

**Seam dispositions verified at this regen:** the SwallowingCatch gate law (r77) stays in every
src-touching brief's gate list. The serial census law: r88 moved ZERO crush test files — tests figure
EXACT-CARRY 5th round (12,027) — but sibling builds retreed the tree-scan censuses, so the assertions
figure was REFRESHED from the green serial (170,392 → 170,422; serial pair 170,454/170,422 inside the
documented ±50 wobble band; K=8 conservation +0/+0 vs re-pinned figure). Durations 532 rows HELD
(zero new crush test files). Each weld gates the touched libs FULL + targeted families + five-guard
125T/7362A + both repo tools. SHARD GATE (s4/E737): shards launch with
`SUGARCRUSH_MCP_DISABLE=1`; launch-asserting tests arm via `tests/Support/McpLaunchEnabledTrait.php`.
`scripts/parallel-tests-durations.tsv` is SUGAR-CRUSH-ONLY. `failOnWarning` UNIVERSAL (58/58) —
forms/vt/bits/layout/shine/glow all 0W at this weld. Harness law UNCHANGED: serials run through the
PLAIN BASH-TOOL PIPE — NEVER tmux/PTY. Determinism law (r82-q9) unchanged. WALK-FORM pin law (r87-w2)
and STRIPPED-geometry law (r87-w2/rv) unchanged. The per-section ledger-edit law (r77 lc) governed
this closeout (backlog: scoped per-section insert, 3/0; triage: companion added two anchored rows,
+2/−0, verified at pick base; worklog: one appended section 45/0; RESUME: banner swap + region insert
40/2 + pointer 1/1; far canaries intact — `### E741`, `### E475`, ROUND-80…87 headings, APPENDIX I–V).
Config-md5 at-rest truth **`d96e124ee7967eb34ef479ef824231ad`** (start==end at the weld).
**LINK CENSUS: sugar-crush 19/19 + candy-pty 8/8** — fresh sandboxes verify the census BEFORE briefing.

Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and
the §0-NOW-92 roster, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed** — EXCEPT where the citation names a sibling lib.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`, `docs/MATCHUPS.md`) kept as-is.
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
OPEN  **E743** 
OPEN  **E744** 
```

## Table (one row per actionable id, ledger order)

| id | status | files touched | domain |
|---|---|---|---|
| E736 | OPEN ⚠x4 ⚠x5 ⚠x6 (umbrella tails; ⚠x1 ⚠x2 ⚠x3 ⚠x7 = PHANTOM re-cut intents, nothing landed) | umbrella — remaining shape ALL trigger/ruling-gated: forms cluster A re-cut `candy-forms/src/Form/Form.php`, `src/Field/Input.php`, `src/Field/TextArea/TextArea.php`, `src/ItemList/ItemList.php`, `tests/Field/TraitStateCarryFamilyTest.php`, `lang/en.php` (x3 scope, 5.9–5.12/5.15); shine streaming re-cut `candy-shine/src/Renderer.php` + Writer/StreamSink NEW + `lang/en.php` (x7 scope, 7.1/7.3, salvage patch in artifacts); layout docblock fold `candy-layout/src/CassowarySolver.php`; MATCHUPS 8 🟡 + 2 🔴 ports per add-a-lib checklist. Landed this round INSIDE the umbrella: forms 5.6/5.7/5.8 (`src/Field/{Date,Slider,Color}.php` + tests + shims), 5.13/5.14 (TextArea/ItemList + tests), vt 6.4 (`candy-vt/src/Buffer/Buffer.php` + handlers + `tests/RowShiftApiTest.php`) | sibling-lib umbrella |
| E742 | OPEN (RULING required — fix lane x1 phantom'd, NOT closed) | `candy-vt/src/Theme/Theme.php` (cubePalette() + `[16 base]∪cube` union, slots 0..15/196/216..231), `candy-vt/src/Color/Color.php`, vt goldens + ansi-index census consumers (`candy-pty` 663/1862/17S, `candy-vcr` 948/4082/14S, crush `Vt\|Terminal\|Buffer` 305/21034) | candy-vt palette |
| E743 | OPEN (minted r88 weld — bits 3.1 promote after x2 phantom) | `sugar-bits/src/Progress/Progress.php:210-319` (Line/Slim/Block width/percent math), `sugar-bits/tests/Progress/ProgressTest.php` (+capture pins) | sugar-bits refactor |
| E744 | OPEN (minted r88 weld — rv-x5 MINOR-1) | `sugar-crush/src/Chat.php` (`delegateToInput` ~:12123 Cmd drop, Ctrl+C pre-empt, `insertString` ~:1586 PasteMsg, input paint ~:4231), zone/mouse forwarding, OSC52 writer — census family (StderrEmitter/NoRawAnsi) re-arms in-step | crush host-seam |
