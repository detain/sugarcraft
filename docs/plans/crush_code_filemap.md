# crush_code backlog — actionable file-map (lane-scheduling aid)

Derived 2026-09-13 @ code tip `f8eba0f34` — **FULL REGEN from scratch at the round-75 close** (supersedes the
`ab7f3726a` r74 cut); regenerate this file at every round-close.
Purpose: map every actionable backlog id to the files it touches so the supervisor can schedule
file-disjoint lanes. **Actionable set (one row per id — 2 rows below): ACTIONABLE = 2 BY ROW CENSUS**
(OPEN-table 2 / PARTIAL-table 0 / STALE-CITATION 0 / UNCERTAIN 0) — the figure and its survivor list
(`E611, E694`, both physically in the triage OPEN table) are re-derived from
the four triage section tables and cross-confirmed against the triage **ROUND-75 CLOSE** census paragraph
(three code picks ja/ja2/jb + companion — ONE row CLOSED-in-place this close: E686, the doc-figure
campaign, tranche-11 executed by ja `99cc68e02` + fix-lane ja2 `332e6fdd7` under the orchestrator CLOSE
ruling; ONE row MINTED: E694, from jb's design, whose per-id half already landed via `90b7d1ae7`).
Census rule stands (r68): within the OPEN/PARTIAL/STALE-CITATION/UNCERTAIN tables a row counts in its
SECTION's bucket unless its evidence cell LEADS with `**CLOSED` — E686's evidence cell was flipped to lead
with `**CLOSED ROUND-75` at this close, exiting the census; the survivor recount (awk strict-prefix over
the four tables) returns exactly 2, never chained. CLOSED rows are PRUNED
at this regen (E686's close record lives in the triage row + backlog row + worklog ROUND 75 — the map is a
scheduling aid, not the history). Round-75 lane letters (**ja, ja2, jb**) are RETIRED at this close
(ja/ja2's files are all CLOSED-row domain — no surviving row cites them); `⚠jb` marks below name the
retired lane that LANDED in a still-actionable row's file (collision history for round-76 scheduling):
jb touched `sugar-crush/src/Chat.php` (memoryLocate, pick `90b7d1ae7`) and
`sugar-crush/tests/Chat/MemoryCommandTest.php` (+5 methods) — exactly E694's landing files, so ka inherits
already-landed seams, not a fresh field. Round-74 marks (⚠if) are dropped — MEMORY.md/DocFigure history
lives in the worklog; E694's MEMORY.md + DocFigure cites are FORWARD obligations (ka sequences doc edit +
arm in one commit), not collision marks. Round-76 lane ownership (**ka–kb**) is defined in
`crush_code_RESUME.md` §0-NOW-77 §2 — `src/Chat.php` carries ka's memory-arm reservation; no other active
reservation. Note: the r75 companion `02c65e6eb` healed the Bootstrap `unfilteredTools()` DOCBLOCK to
twelve-truth but left live-false ELEVEN claims in the same file's BODY (`:6371`/`:6376`/`:6546`/`:6552`) and
`README.md:224`/`:233` + `tests/Integration/ForeignAgentPresetWiringTest.php:273` "six" — these are
foldable doc-truth seams for kb (they belong to no actionable row; the E686 close did not cover them
because the campaign armed pages under `sugar-crush/docs/`, not the root README/Bootstrap body).
Tier/lane analysis lives in `docs/plans/crush_code_concurrency.md` — NOT duplicated here; the `domain`
column below is a file-cluster bucket.

## Path normalization

Rows are derived from the ledger's evidence/note citations (`docs/plans/crush_code_backlog_triage.md`) and the
ROUND-75 CLOSE census paragraph, normalized to repo-root paths:

- Bare `src/…`, `tests/…`, `docs/…` (lib docs), `bin/…`, `README.md`, `phpunit.xml` as cited → **`sugar-crush/`-prefixed**.
- Citations already written monorepo-root (`sugar-crush/…`, `docs/plans/…`, `tools/…`, `.github/…`, `scripts/…`, `crush_code.md`) → kept as-is.
- Sibling libs (`candy-…`, `sugar-dash`, `sugar-reel`) → kept as-is.
- Bare-directory citations kept with trailing `/` (e.g. `sugar-crush/tests/`).
- `files = UNKNOWN(re-derive)` when the row cites no resolvable path.
- `⚠jb` = RETIRED round-75 lane letter — marks a file where that lane landed this round
  while the row stays actionable (see header).

## Table (one row per actionable id, ledger order)

| id | stamp | conf | files (⚠ retired letters) | domain | size | r75 status → round-76 |
|---|---|---|---|---|---|---|
| E611 | OPEN | MED | UNKNOWN(re-derive) | other | S | DESIGN CARRY (supervisor-harness tool + machine-readable ownership schema, OUT of code-plan scope); mitigation (explicit OWNERSHIP blocks per brief) in force since r62 — the row may be premise-dead; **kb (r76) = read-only verdict lane: re-derive the round-59 premises at tip, rule pick-or-drop with evidence, zero commits unless premise dead → docs-only flip** — pick-or-drop SLIPPED r74 (ie) and r75 (ja/jb never touched it); honest slip recorded at the backlog row |
| E694 | OPEN | MED | sugar-crush/src/Chat.php⚠jb;sugar-crush/src/Context/;sugar-crush/tests/Chat/MemoryCommandTest.php⚠jb;sugar-crush/docs/MEMORY.md;sugar-crush/tests/Config/DocFigureProseDriftTest.php | memory | M | **MINTED r75 close from jb's design** — per-id half ALREADY LANDED (`memoryLocate` repo-first, delete/edit mutate the shown entry); step-1 = `/memory list` + `/memory search` grouped display naming the store per row (home-section wording byte-stable; MEMORY.md sentence + DocFigure arm in the SAME commit — the sentence is quoted verbatim in the backlog row); bulk `/memory clear`: ORCHESTRATOR RULING — NOT supported for project scope, per-id ops only, repo-store bulk routing REFUSES LOUDLY, `--confirm --force` DECLINED; step-3 agent memory tool GATED on E25 re-severity review + built-in-tool corpus census; carries rv-jb MINOR-1 (array-shape tuple doc at `memoryLocate` signature). **ASSIGNED round-76 lane ka** (DESIGN-FIRST like jb; hermeticity law: pin `projectRoot`; containment inventories bind — any new fs code classifies into both fail-closed rosters) |

## Domain index

| domain | n | ids |
|---|---:|---|
| memory | 1 | E694 |
| other | 1 | E611 |

Sum = 2 ✓ (equals the table's row count).
